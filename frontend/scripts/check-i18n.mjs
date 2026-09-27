#!/usr/bin/env node
/**
 * Guard against uncompiled i18n strings (e.g. <Trans> / t`...` added but
 * `lingui extract` + `lingui compile` forgotten before a build).
 *
 * Lingui compiles the .po catalog to JS at build time. In production the
 * compiled catalog is keyed by MESSAGE ID (e.g. "yIzJXp") with the German
 * source text as the value. A string that exists in source code but is
 * missing from the compiled catalog renders as the cryptic message id
 * instead of the German text.
 *
 * Strategy:
 *  1. Run `lingui extract` to refresh src/locales/de/messages.po from current sources.
 *  2. Collect every msgid (German source text) from the extracted .po.
 *  3. Collect every compiled message VALUE from src/locales/de/messages.js.
 *  4. Fail if any extracted msgid is NOT present as a compiled value
 *     (catalog is stale → a build would ship untranslated message ids).
 *  5. Fail if any non-source catalog leaves a msgstr empty (step 4 cannot see
 *     this: an untranslated message still COMPILES, it just falls back to the
 *     German source text at runtime — see below).
 *
 * Why 5 exists: with only 1–4 an English-only feature compiles happily and
 * ships English users the German text, which reads as "the feature does not
 * exist" (e.g. an aria-label of "Stadion Nord reaktivieren"). The two check
 * families are complementary — 1–4 ask "is the catalog current?", 5 asks
 * "is it complete?" — and the EN catalog shipped with exactly that hole, so
 * the guard was green over a missing feature.
 */

import { execFileSync } from "node:child_process";
import { readFileSync, existsSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, basename, relative, resolve } from "node:path";
import { findTranslationGaps, indexMessages, parsePo } from "./po-catalog.mjs";

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, "..");

const PO_PATH = resolve(root, "src/locales/de/messages.po");
const COMPILED_PATH = resolve(root, "src/locales/de/messages.js");
const LOCALES_ROOT = resolve(root, "src/locales");

/** Mirrors `sourceLocale` in lingui.config.ts — the catalog that needs no translation. */
const SOURCE_LOCALE = "de";

function fail(message, hint = "Run `pnpm lingui:extract && pnpm lingui:compile` and commit the result.") {
  console.error(`\n❌ i18n check failed: ${message}`);
  console.error(`${hint}\n`);
  process.exit(1);
}

console.log("🔍 Extracting i18n messages from sources...");
try {
  execFileSync("pnpm", ["lingui:extract"], { cwd: root, stdio: "inherit" });
} catch {
  fail("lingui extract failed");
}

if (!existsSync(PO_PATH)) {
  fail(`catalog not found at ${PO_PATH}`);
}

// Collect all msgids from the freshly extracted .po (skip obsolete/empty).
const po = readFileSync(PO_PATH, "utf8");
const msgids = new Set();
const msgidRegex = /^msgid "((?:[^"\\]|\\.)*)"$/gm;
let match;
while ((match = msgidRegex.exec(po)) !== null) {
  const raw = match[1].replace(/\\"/g, '"').replace(/\\n/g, "\n");
  if (raw.length > 0) msgids.add(raw);
}

if (msgids.size === 0) {
  fail("no message ids found in catalog");
}

if (!existsSync(COMPILED_PATH)) {
  fail(`compiled catalog missing at ${COMPILED_PATH} — run pnpm lingui:compile`);
}

// The compiled catalog is a CommonJS module: module.exports = {messages:
// JSON.parse("{...}")}, keyed by message id with the source text as the first
// element of the message array, e.g. "yIzJXp":["SMTP-Verbindungstest"].
// Parse the embedded JSON directly to avoid CJS/ESM interop issues.
const compiled = readFileSync(COMPILED_PATH, "utf8");
const startMarker = 'JSON.parse("';
const startIdx = compiled.indexOf(startMarker);
if (startIdx === -1) {
  fail("could not locate JSON.parse(...) in compiled catalog messages.js");
}
const strStart = startIdx + startMarker.length;
let strEnd = -1;
for (let i = strStart; i < compiled.length; i++) {
  if (compiled[i] === '"' && compiled[i - 1] !== '\\') {
    strEnd = i;
    break;
  }
}
if (strEnd === -1) {
  fail("could not locate end of JSON string in compiled catalog messages.js");
}
const jsStringLiteral = '"' + compiled.slice(strStart, strEnd) + '"';
let jsonRaw;
try {
  jsonRaw = new Function(`return (${jsStringLiteral});`)();
} catch (err) {
  fail(`failed to decode compiled catalog JSON string: ${err.message}`);
}
const compiledCatalog = JSON.parse(jsonRaw);

// Lingui compiles simple messages as ["source text"] and ICU MessageFormat
// messages (e.g. {var, plural, ...}) as nested arrays like
// [["var","plural",{"one":["…"],"other":["…"]}]," text"].
// We verify completeness by counting: lingui never silently drops entries
// during compile, so entry count >= msgid count proves the catalog is fresh.
const compiledCount = Object.keys(compiledCatalog).length;

if (compiledCount < msgids.size) {
  console.error(`\n❌ Compiled catalog has ${compiledCount} entries but .po has ${msgids.size} msgids — catalog is stale.`);
  fail("compiled catalog is stale — run pnpm lingui:compile");
}

// Additionally verify that every simple (non-ICU) msgid is present.
const compiledValues = new Set();
for (const value of Object.values(compiledCatalog)) {
  if (Array.isArray(value) && typeof value[0] === "string" && value[0].length > 0) {
    compiledValues.add(value[0]);
  }
}

const missing = [];
for (const id of msgids) {
  // Skip ICU MessageFormat strings (contain {variable} or {variable, plural, …})
  if (/\{[^}]+\}/.test(id)) continue;
  if (!compiledValues.has(id)) {
    missing.push(id);
  }
}

if (missing.length > 0) {
  console.error("\n❌ The following source strings are in the .po catalog but NOT in the compiled catalog (messages.js):");
  for (const m of missing.slice(0, 20)) {
    console.error(`   - ${m}`);
  }
  if (missing.length > 20) console.error(`   … and ${missing.length - 20} more`);
  fail("compiled catalog is stale — run pnpm lingui:compile");
}

// ---------------------------------------------------------------------------
// Completeness: every ACTIVE msgid of every non-source catalog must carry a
// translation. An empty msgstr still compiles — it silently falls back to the
// German source text — so the checks above cannot see it.
// ---------------------------------------------------------------------------

const sourceEntries = parsePo(readFileSync(PO_PATH, "utf8"));
const sourceIndex = indexMessages(sourceEntries);

// Discovered, not hardcoded: a locale added to lingui.config.ts without a
// matching directory here must not skip this gate, and the "no translation
// needed" case is already covered structurally (an identical msgid/msgstr is
// non-empty and therefore passes) — which is why there is no exemption list.
//
// The source locale is excluded by EXACT path, not by prefix: a future regional
// sibling (`de-AT`) starts with `de`, and a `startsWith` test would have
// silently exempted it — the exact failure mode this gate exists to prevent.
const sourceCatalogPath = resolve(LOCALES_ROOT, SOURCE_LOCALE, "messages.po");
const targetCatalogs = readdirSync(LOCALES_ROOT, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => resolve(LOCALES_ROOT, entry.name, "messages.po"))
  .filter((poPath) => existsSync(poPath) && poPath !== sourceCatalogPath);

if (targetCatalogs.length === 0) {
  fail(
    `no catalog found for any locale other than the source locale "${SOURCE_LOCALE}" under ${LOCALES_ROOT}`,
    "If the app is single-language on purpose, delete the completeness check from scripts/check-i18n.mjs — do not leave it passing vacuously.",
  );
}

let gapCount = 0;

for (const poPath of targetCatalogs) {
  const locale = basename(dirname(poPath));
  const poRelative = relative(root, poPath);
  const gaps = findTranslationGaps(parsePo(readFileSync(poPath, "utf8")), sourceIndex);

  if (gaps.length === 0) {
    continue;
  }
  gapCount += gaps.length;

  const shown = gaps.slice(0, 20);
  console.error(`\n❌ ${gaps.length} untranslated message(s) in ${poRelative} (locale "${locale}"):`);
  for (const gap of shown) {
    const why =
      gap.kind === "missing"
        ? "this msgid is not in the source catalog — run `pnpm lingui:extract` to clean it up"
        : gap.kind === "incomplete"
          ? `only some msgstr slots are filled (missing: ${gap.emptySlots.map((index) => `msgstr[${index}]`).join(", ")})`
          : "the msgstr is empty, so this locale falls back to the German source text";
    console.error(`\n   ${poRelative}:${gap.line}${gap.context === null ? "" : ` (msgctxt "${gap.context}")`}`);
    console.error(`     msgid: ${JSON.stringify(gap.msgid)}`);
    console.error(`     ${why}`);
    if (gap.references.length > 0) {
      console.error(`     used in: ${gap.references.join(", ")}`);
    }
  }
  if (gaps.length > shown.length) {
    console.error(`\n   … and ${gaps.length - shown.length} more`);
  }
}

if (gapCount > 0) {
  fail(
    `${gapCount} message(s) are not translated into every locale`,
    "Write the missing msgstr values in the catalogs named above and run `pnpm lingui:extract && pnpm lingui:compile`.",
  );
}

console.log(`✅ i18n check passed (${msgids.size} messages compiled, ${targetCatalogs.length} translation catalog(s) complete).`);
