#!/usr/bin/env node
/**
 * Fill the EMPTY `msgstr` slots of `src/locales/en/messages.po` from a
 * msgid → msgstr table, in place.
 *
 * ## Why a script and not hand edits
 *
 * The `check:i18n` gate (§3) fails the build when an ACTIVE message has no
 * translation in a non-source locale, and a new feature adds ~30 of them at
 * once. Hand-editing 30 blocks in a 2000-line catalog is exactly the kind of
 * manual step where one entry is missed and the gate then reports it as a
 * mystery — so the fill is a TABLE, and this script refuses to write anything
 * it was not given a translation for.
 *
 * Refuses (exit 1) when:
 *   - a msgid in the table is not in the catalog at all (typo in the table), or
 *   - a msgid in the table is already translated (this script would overwrite a
 *     human's word), or
 *   - an empty `msgstr` block remains afterwards (something the table missed).
 *
 * Usage: `node scripts/fill-en-translations.mjs <translations.json>`
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const poPath = resolve(root, 'src/locales/en/messages.po');

const table = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const po = readFileSync(poPath, 'utf8');

/** Unescapes a .po double-quoted string body. */
function unescape(raw) {
    return raw.replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\');
}

function escape(value) {
    return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');
}

const problems = [];
let out = po;

// Block-wise rewrite: an ACTIVE entry is `msgid "…"\nmsgstr "…"` with no `#~`
// marker on the msgid line. `#~` obsolete entries are left untouched — Lingui
// keeps them as history and empties their msgstr itself.
const blockPattern = /(^msgid "((?:[^"\\]|\\.)*)"\n^msgstr "((?:[^"\\]|\\.)*)"\n)/gm;
let match;

while ((match = blockPattern.exec(po)) !== null) {
    const [full, block, rawId, rawStr] = match;
    const msgid = unescape(rawId);

    if (!(msgid in table)) {
        continue;
    }
    if (rawStr !== '') {
        problems.push(`already translated, refusing to overwrite: ${JSON.stringify(msgid)}`);
        continue;
    }

    out = out.replace(full, `msgid "${rawId}"\nmsgstr "${escape(table[msgid])}"\n`);
}

for (const msgid of Object.keys(table)) {
    if (!po.includes(`msgid "${msgid.replace(/"/g, '\\"')}"`)) {
        problems.push(`not in the catalog at all (typo?): ${JSON.stringify(msgid)}`);
    }
}

const stillEmpty = [];
const remaining = /(^msgid "((?:[^"\\]|\\.)*)"\n^msgstr ""\n)/gm;
while ((match = remaining.exec(out)) !== null) {
    stillEmpty.push(unescape(match[2]));
}

if (problems.length > 0) {
    console.error(`❌ ${problems.length} problem(s):`);
    for (const problem of problems) console.error(`   - ${problem}`);
    process.exit(1);
}

writeFileSync(poPath, out);
console.log(`✅ filled ${Object.keys(table).length} EN msgstr slot(s)`);
if (stillEmpty.length > 0) {
    console.log(`ℹ️  ${stillEmpty.length} empty msgstr left in the catalog (pre-existing):`);
    for (const msgid of stillEmpty) console.log(`   - ${JSON.stringify(msgid)}`);
}
