import { describe, expect, it } from 'vitest';
import { findTranslationGaps, indexMessages, parsePo } from './po-catalog.mjs';

/**
 * The completeness gate of `scripts/check-i18n.mjs`.
 *
 * Why this is a separate module: the guard itself runs `lingui extract`,
 * compiles and calls `process.exit`, so importing it would extract catalogs as a
 * side effect of a test run. Everything that DECIDES is here instead — a pure
 * `parsePo` plus `findTranslationGaps` — and the guard only does file system
 * access, printing and the exit code. The behaviour the build depends on is
 * therefore unit-tested, and the fixtures below are the exact shapes the real
 * catalogs use (obsolete `#~` blocks, ICU plurals in a single `msgstr`,
 * multi-line header, `msgctxt`).
 */
const SOURCE_PO = `msgid ""
msgstr ""
"Project-Id-Version: \\n"
"Language: de\\n"
"Plural-Forms: \\n"

#: src/components/VenueCombobox.tsx
msgid "{name} neu anlegen"
msgstr "{name} neu anlegen"

#: src/components/VenueCombobox.tsx
msgid "{name} reaktivieren"
msgstr "{name} reaktivieren"

#: src/logic/accreditationLabels.ts
msgid "{available, plural, one {# Platz frei} other {# Plätze frei}}"
msgstr "{available, plural, one {# Platz frei} other {# Plätze frei}}"

#: src/pages/admin/Menu.tsx
msgctxt "menu"
msgid "Abbrechen"
msgstr "Abbrechen"

#: src/pages/admin/Menu.tsx
msgctxt "dialog"
msgid "Abbrechen"
msgstr "Abbrechen"

#: src/pages/admin/Files.tsx
msgid "Datei"
msgid_plural "Dateien"
msgstr[0] "Datei"
msgstr[1] "Dateien"

#: src/pages/admin/Retired.tsx
#~ msgid "Alter Text"
#~ msgstr "Old text"
`;

/** The English catalog as it looked BEFORE this fix: two untranslated rows. */
const EN_PO_WITH_GAPS = `msgid ""
msgstr ""
"Language: en\\n"

#: src/components/VenueCombobox.tsx
msgid "{name} neu anlegen"
msgstr ""

#: src/components/VenueCombobox.tsx
msgid "{name} reaktivieren"
msgstr ""

#: src/logic/accreditationLabels.ts
msgid "{available, plural, one {# Platz frei} other {# Plätze frei}}"
msgstr "{available, plural, one {# slot available} other {# slots available}}"

#: src/pages/admin/Menu.tsx
msgctxt "menu"
msgid "Abbrechen"
msgstr "Cancel"

#: src/pages/admin/Menu.tsx
msgctxt "dialog"
msgid "Abbrechen"
msgstr "Discard"

#: src/pages/admin/Files.tsx
msgid "Datei"
msgid_plural "Dateien"
msgstr[0] "File"
msgstr[1] "Files"

#: src/components/VenueCombobox.tsx
#~ msgid "{0} neu anlegen"
#~ msgstr "{0} (create new)"

#: src/components/VenueCombobox.tsx
#~ msgid "{0} reaktivieren"
#~ msgstr ""
`;

/** Runs the gate exactly as the guard does, against the SOURCE_PO fixture. */
function gapsIn(targetPo: string) {
    return findTranslationGaps(parsePo(targetPo), indexMessages(parsePo(SOURCE_PO)));
}

describe('findTranslationGaps — the hole this gate was written for', () => {
    it('reports an active msgid whose msgstr is empty', () => {
        const gaps = gapsIn(EN_PO_WITH_GAPS);

        // THE regression: `pnpm check:i18n` was green over exactly these two
        // rows, because an empty msgstr still COMPILES — it just falls back to
        // the German source text at runtime.
        expect(gaps).toEqual([
            {
                kind: 'untranslated',
                msgid: '{name} neu anlegen',
                context: null,
                line: 5,
                emptySlots: [0],
                references: ['src/components/VenueCombobox.tsx'],
            },
            {
                kind: 'untranslated',
                msgid: '{name} reaktivieren',
                context: null,
                line: 9,
                emptySlots: [0],
                references: ['src/components/VenueCombobox.tsx'],
            },
        ]);
    });

    it('passes once the two msgstr are filled', () => {
        const filled = EN_PO_WITH_GAPS.replace('msgid "{name} neu anlegen"\nmsgstr ""', 'msgid "{name} neu anlegen"\nmsgstr "Create {name}"').replace(
            'msgid "{name} reaktivieren"\nmsgstr ""',
            'msgid "{name} reaktivieren"\nmsgstr "Reactivate {name}"',
        );

        expect(gapsIn(filled)).toEqual([]);
    });
});

describe('findTranslationGaps — what must NOT fail', () => {
    it('exempts obsolete entries, including one with an empty msgstr', () => {
        const gaps = gapsIn(EN_PO_WITH_GAPS);
        const obsolete = parsePo(EN_PO_WITH_GAPS).filter((entry) => entry.obsolete);

        // Lingui keeps removed messages forever as `#~` history and may well
        // leave their msgstr empty — a catalog cleanup must not turn into a
        // build failure. `{0} reaktivieren` is blanked on purpose.
        expect(obsolete).toHaveLength(2);
        expect(obsolete.map((entry) => entry.msgid)).toEqual(['{0} neu anlegen', '{0} reaktivieren']);
        expect(obsolete[1]?.slots).toEqual([{ index: 0, value: '' }]);
        expect(gaps.map((gap) => gap.msgid)).not.toContain('{0} reaktivieren');
    });

    it('ignores the catalog header and every translated message', () => {
        expect(gapsIn(EN_PO_WITH_GAPS).every((gap) => gap.msgid !== '')).toBe(true);
    });

    it('does not confuse the two messages that share the msgid "Abbrechen"', () => {
        // Both `msgctxt` variants ARE translated, so a key that ignored the
        // context would still pass here — the discrimination is covered by
        // `parsePo` below, where one of the two is left empty.
        const oneContextEmpty = EN_PO_WITH_GAPS.replace(
            'msgctxt "dialog"\nmsgid "Abbrechen"\nmsgstr "Discard"',
            'msgctxt "dialog"\nmsgid "Abbrechen"\nmsgstr ""',
        );

        const gaps = gapsIn(oneContextEmpty);
        expect(gaps).toHaveLength(3);
        expect(gaps.filter((gap) => gap.msgid === 'Abbrechen')).toEqual([
            { kind: 'untranslated', msgid: 'Abbrechen', context: 'dialog', line: 22, emptySlots: [0], references: ['src/pages/admin/Menu.tsx'] },
        ]);
    });
});

describe('findTranslationGaps — plurals', () => {
    it('reports a plural whose second slot is still empty as incomplete', () => {
        const halfTranslated = EN_PO_WITH_GAPS.replace('msgstr[1] "Files"', 'msgstr[1] ""');
        const gaps = gapsIn(halfTranslated);
        const pluralGap = gaps.find((gap) => gap.msgid === 'Datei');

        // `incomplete`, not `untranslated`: one branch is there, so a
        // translator knows exactly which one is missing instead of redoing
        // the whole message.
        expect(pluralGap).toEqual({
            kind: 'incomplete',
            msgid: 'Datei',
            context: null,
            line: 27,
            emptySlots: [1],
            references: ['src/pages/admin/Files.tsx'],
        });
    });

    it('accepts a fully translated plural', () => {
        expect(gapsIn(EN_PO_WITH_GAPS).filter((gap) => gap.msgid === 'Datei')).toEqual([]);
    });
});

describe('findTranslationGaps — a target msgid the source no longer has', () => {
    it('is reported as missing so lingui extract can clean it up', () => {
        const stale = `${EN_PO_WITH_GAPS}\n#: src/pages/admin/Ghost.tsx\nmsgid "Veraltet"\nmsgstr "Outdated"\n`;
        const gaps = gapsIn(stale);

        expect(gaps.filter((gap) => gap.kind === 'missing')).toEqual([
            { kind: 'missing', msgid: 'Veraltet', context: null, line: 41, emptySlots: [], references: ['src/pages/admin/Ghost.tsx'] },
        ]);
    });
});

describe('parsePo', () => {
    it('skips the header entry', () => {
        const [header, ...rest] = parsePo(SOURCE_PO);

        // `msgid ""` is the header, not a message: every real entry has a
        // non-empty msgid, and treating the header as one would make every
        // catalog look untranslated.
        expect(header?.msgid).toBe('');
        expect(header?.slots[0]?.value).toContain('Language: de');
        expect(rest.every((entry) => entry.msgid !== '')).toBe(true);
    });

    it('joins multi-line values', () => {
        const entries = parsePo('msgid "erste\\n"\n"zweite"\nmsgstr "a"\n"b"\n');
        const entry = entries[0];

        expect(entry?.msgid).toBe('erste\nzweite');
        expect(entry?.slots).toEqual([{ index: 0, value: 'ab' }]);
    });

    it('unescapes quotes and backslashes', () => {
        const entries = parsePo('msgid "a \\"b\\" c"\nmsgstr "d \\\\ e"\n');

        expect(entries[0]?.msgid).toBe('a "b" c');
        expect(entries[0]?.slots[0]?.value).toBe('d \\ e');
    });

    it('reads a real catalog without inventing or dropping entries', () => {
        // Guards the parser against the real files: every `msgid "…"` line that
        // is not obsolete and not the header has to come out exactly once.
        const entries = parsePo(SOURCE_PO);
        const active = entries.filter((entry) => !entry.obsolete && entry.msgid !== '');

        expect(active).toHaveLength(6);
        expect(active.map((entry) => entry.msgid)).toEqual([
            '{name} neu anlegen',
            '{name} reaktivieren',
            '{available, plural, one {# Platz frei} other {# Plätze frei}}',
            'Abbrechen',
            'Abbrechen',
            'Datei',
        ]);
        expect(active.filter((entry) => entry.msgid === 'Abbrechen').map((entry) => entry.context)).toEqual(['menu', 'dialog']);
    });
});
