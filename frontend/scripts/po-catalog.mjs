/**
 * gettext `.po` reader for the i18n guard (`check-i18n.mjs`).
 *
 * PURE on purpose: no file system, no child process, no `process.exit`. The
 * guard itself extracts, compiles and exits, so it can never be imported by a
 * test — keeping the classification in this side-effect-free module is what
 * makes "an untranslated msgid fails the build" a unit test instead of a
 * manual `pnpm check:i18n` run.
 *
 * Only the subset the catalogs actually use is modelled, but each part of that
 * subset is modelled PROPERLY, because the guard has to keep working across a
 * catalog cleanup:
 *
 *  - **Obsolete entries** (`#~ msgid` / `#~ msgstr`, every line of the entry
 *    prefixed) are parsed and flagged, never reported as an untranslated
 *    message. Lingui keeps them forever with an EMPTY msgstr; requiring one
 *    would turn every removal into a build failure.
 *  - **gettext plurals** (`msgstr[0]`, `msgstr[1]`, …) are separate slots, so
 *    a half-translated plural is one finding ("incomplete") instead of
 *    compiling into a message with a missing branch.
 *  - **`msgctxt`** takes part in a message's identity. Two entries sharing a
 *    msgid under different contexts are two messages, so the context belongs
 *    in the lookup key — without it a context-only translation would look
 *    both missing and present.
 *  - **Continuation lines**: a value may be split over several `"…"` lines
 *    (the catalog header always is), so a quoted line after a keyword line is
 *    appended to that keyword's value rather than parsed as a new entry.
 *
 * What is parsed but NOT enforced: `#, fuzzy`. Lingui's `format-po` writes no
 * fuzzy flags and the catalogs carry none; if a future catalog ever did, an
 * empty msgstr would already fail this check, which is the failure a fuzzy
 * flag exists to prevent.
 */

/**
 * @typedef {object} PoSlot
 * @property {number} index `0` for a plain `msgstr`, `N` for `msgstr[N]`.
 * @property {string} value  Unescaped translation, `''` when untranslated.
 */

/**
 * @typedef {object} PoEntry
 * @property {number} line             1-based line the entry starts on.
 * @property {boolean} obsolete        `true` for a `#~` (Lingui-obsolete) entry.
 * @property {string|null} context     `msgctxt`, `null` when the entry has none.
 * @property {string|null} msgid       `null` when the entry has no `msgid` line.
 * @property {string|null} msgidPlural
 * @property {PoSlot[]} slots          `msgstr` / `msgstr[N]` values, in file order.
 * @property {string[]} references     `#:` source locations, e.g. `src/App.tsx`.
 * @property {string[]} flags          `#,` flags, e.g. `fuzzy`.
 */

/**
 * @typedef {object} TranslationGap
 * @property {'untranslated'|'incomplete'|'missing'} kind
 * @property {string} msgid
 * @property {string|null} context
 * @property {number} line                    1-based line inside the target `.po`.
 * @property {number[]} emptySlots            `msgstr[N]` indices left empty/absent.
 * @property {string[]} references            Source files, from the `#:` lines.
 */

/** The escapes gettext allows inside a quoted value. */
const STRING_ESCAPES = { n: "\n", t: "\t", r: "\r", b: "\b", f: "\f", '"': '"', "\\": "\\" };

/**
 * `msgctxt|msgid_plural|msgid|msgstr[N]` followed by a quoted value. The longer
 * keywords come first so `msgid_plural` never degrades to `msgid`.
 */
const KEYWORD_LINE = /^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)[ \t]*("(?:[^"\\]|\\.)*")$/;

/** Unescape the body of a quoted `.po` string (the text between the quotes). */
function unescapePoString(body) {
    return body.replace(/\\(.)/g, (whole, ch) => (ch in STRING_ESCAPES ? STRING_ESCAPES[ch] : whole));
}

/** `"…"` → the unescaped text; `null` when the line is not one quoted string. */
function readQuoted(text) {
    const match = /^"((?:[^"\\]|\\.)*)"$/.exec(text);
    return match === null ? null : unescapePoString(match[1]);
}

/** @returns {PoEntry} */
function blankEntry(line) {
    return { line, obsolete: false, context: null, msgid: null, msgidPlural: null, slots: [], references: [], flags: [] };
}

/**
 * @param {string} source Raw `.po` content.
 * @returns {PoEntry[]} One entry per block, header and obsolete ones included.
 */
export function parsePo(source) {
    /** @type {PoEntry[]} */
    const entries = [];
    /** The entry being read, or `null` between blocks. */
    let current = null;
    /**
     * Comment-only block waiting for the entry it belongs to. Its line number
     * is where the reported entry starts, so an error points at the `#:`
     * reference rather than at a bare `msgid`.
     */
    let pending = null;
    /** The keyword a bare `"…"` continuation line belongs to, if any. */
    let continuation = null;

    const finish = () => {
        if (current !== null) {
            // A comment block with no `msgid` is not an entry; drop it rather
            // than report a message that does not exist.
            if (current.msgid !== null || current.msgidPlural !== null) {
                entries.push(current);
            }
        }
        current = null;
        pending = null;
        continuation = null;
    };

    const lines = source.split("\n");

    for (let i = 0; i < lines.length; i += 1) {
        const lineNumber = i + 1;
        const raw = lines[i];
        const line = raw.trim();

        if (line === "") {
            finish();
            continue;
        }

        // `#~` prefixes EVERY line of an obsolete entry, comments excluded
        // (gettool keeps the `#.` / `#:` lines unprefixed, so they are
        // collected below as ordinary comments).
        const obsoletePrefix = /^#~\s?(.*)$/.exec(line);
        const body = obsoletePrefix === null ? line : obsoletePrefix[1];

        if (body.startsWith("#")) {
            // A comment attaches to the entry being read, or — before any
            // `msgid` — to the next one.
            const target = current ?? (pending ??= blankEntry(lineNumber));
            if (body.startsWith("#:")) {
                target.references.push(body.slice(2).trim());
            } else if (body.startsWith("#,")) {
                target.flags = body
                    .slice(2)
                    .split(",")
                    .map((flag) => flag.trim())
                    .filter((flag) => flag !== "");
            }
            // `#.` (extracted comment) and `#|` (previous msgid) carry no
            // information this check reports, so they are skipped.
            continue;
        }

        const keyword = KEYWORD_LINE.exec(body);
        if (keyword === null) {
            // Not a keyword line → it can only be a continuation of the last
            // one. An orphan quote is malformed input, not a reason to throw:
            // the guard's job is to report translation gaps, and a catalog that
            // cannot be parsed at all fails the earlier compiled-catalog check.
            const chunk = readQuoted(body);
            if (chunk !== null && continuation !== null && current !== null) {
                appendTo(continuation, chunk);
            }
            continue;
        }

        const value = readQuoted(keyword[3]);
        if (value === null) {
            continue;
        }

        // First keyword of a new entry: promote the buffered comment block (if
        // any) so the reported line points at the `#:` reference.
        if (current === null) {
            current = pending ?? blankEntry(lineNumber);
            pending = null;
        }
        if (obsoletePrefix !== null) {
            current.obsolete = true;
        }

        const [, name, pluralIndex] = keyword;
        switch (name) {
            case "msgctxt":
                current.context = value;
                continuation = "context";
                break;
            case "msgid":
                current.msgid = value;
                continuation = "msgid";
                break;
            case "msgid_plural":
                current.msgidPlural = value;
                continuation = "msgidPlural";
                break;
            default: {
                const index = pluralIndex === undefined ? 0 : Number(pluralIndex);
                const existing = current.slots.find((slot) => slot.index === index);
                // A repeated `msgstr[N]` continues the previous value.
                if (existing === undefined) {
                    current.slots.push({ index, value });
                } else {
                    existing.value += value;
                }
                continuation = `msgstr:${index}`;
                break;
            }
        }
    }

    finish();

    /**
     * @param {string} target
     * @param {string} chunk
     */
    function appendTo(target, chunk) {
        if (target === "context") {
            current.context = (current.context ?? "") + chunk;
        } else if (target === "msgid") {
            current.msgid = (current.msgid ?? "") + chunk;
        } else if (target === "msgidPlural") {
            current.msgidPlural = (current.msgidPlural ?? "") + chunk;
        } else if (target.startsWith("msgstr:")) {
            const index = Number(target.slice("msgstr:".length));
            const slot = current.slots.find((candidate) => candidate.index === index);
            if (slot !== undefined) {
                slot.value += chunk;
            }
        }
    }

    return entries;
}

/**
 * Identity of a message inside a catalog. The context is part of the key:
 * gettext keys on `msgctxt` + `msgid`, and Lingui's compiled catalog does the
 * same, so a lookup that ignored the context would confuse two different
 * messages that happen to share a msgid.
 */
function messageKey(context, msgid) {
    return `${context ?? ""} ${msgid}`;
}

/**
 * Index the entries a translation has to cover: the active, non-header
 * messages of a catalog, keyed for {@link findTranslationGaps}.
 *
 * @param {PoEntry[]} entries
 * @returns {Map<string, PoEntry>}
 */
export function indexMessages(entries) {
    const index = new Map();
    for (const entry of entries) {
        if (entry.obsolete || entry.msgid === null || entry.msgid === "") {
            continue;
        }
        index.set(messageKey(entry.context, entry.msgid), entry);
    }
    return index;
}

/**
 * Every active message of `entries` that is not (fully) translated against the
 * `sourceIndex` produced from the SOURCE catalog.
 *
 * Why the source catalog is the reference and not the target alone: a target
 * entry that Lingui has just marked obsolete is skipped, but a target entry the
 * source does not have at all is a different defect — a stale msgid left in the
 * translation — and is reported as `missing` so the cleanup is not a silent
 * no-op.
 *
 * @param {PoEntry[]} entries Target-locale entries.
 * @param {Map<string, PoEntry>} sourceIndex From {@link indexMessages} on the source catalog.
 * @returns {TranslationGap[]}
 */
export function findTranslationGaps(entries, sourceIndex) {
    /** @type {TranslationGap[]} */
    const gaps = [];

    for (const entry of entries) {
        // The exemption is STRUCTURAL, not a list: an obsolete entry is not in
        // the UI, and Lingui deliberately leaves its msgstr empty. A second
        // exemption mechanism (a hand-maintained "skip these" list) would
        // only create a place for a real gap to hide, so there is none.
        if (entry.obsolete) {
            continue;
        }
        // `msgid ""` is the catalog header (POT-Creation-Date, MIME-Version …).
        if (entry.msgid === null || entry.msgid === "") {
            continue;
        }

        const source = sourceIndex.get(messageKey(entry.context, entry.msgid));
        if (source === undefined) {
            gaps.push({ kind: "missing", msgid: entry.msgid, context: entry.context, line: entry.line, emptySlots: [], references: entry.references });
            continue;
        }

        // The slots that have to be filled are the ones the SOURCE entry has:
        // a single `msgstr` for Lingui (an ICU plural lives inside that one
        // string) or `msgstr[0..N]` for a gettext plural. A slot the target
        // added on its own is checked too — an empty extra slot compiles into
        // nothing. A source entry with no msgstr at all still owes slot 0.
        const required = new Set([...source.slots.map((slot) => slot.index), ...entry.slots.map((slot) => slot.index)]);
        if (required.size === 0) {
            required.add(0);
        }

        const filled = new Map(entry.slots.map((slot) => [slot.index, slot.value]));
        const emptySlots = [...required].filter((index) => (filled.get(index) ?? "").trim() === "").sort((a, b) => a - b);
        if (emptySlots.length === 0) {
            continue;
        }

        gaps.push({
            kind: emptySlots.length === required.size ? "untranslated" : "incomplete",
            msgid: entry.msgid,
            context: entry.context,
            line: entry.line,
            emptySlots,
            references: entry.references,
        });
    }

    return gaps;
}
