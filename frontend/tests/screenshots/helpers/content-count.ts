/**
 * What the sidecar's `contentCount` records, and what it refuses to invent.
 *
 * ## The bug this exists for (MEASURED 2026-10-01)
 *
 * `waitForContent()` used to `return 1` for every `text` postcondition, so the
 * number in `<name>.meta.json` was a CONSTANT, not a measurement. On the real
 * store all four `/konto` **empty** captures carried `contentCount: 1` while the
 * page displayed **0 Anträge** — and `/konto` is the route that was added
 * precisely so that the applications count would be auditable. `admin-freigaben`'s
 * pre-existing empty marker (`Keine Anträge vorhanden.`) carried the same `1`.
 *
 * So the number now comes out of the text the page actually rendered
 * (`UiReviewContent.textCount`), and a marker that carries no number records
 * `null` instead of a fabricated `1`.
 *
 * ## Why `null` and not `1` for a presence marker
 *
 * A `text` postcondition without `textCount` measures PRESENCE: "this text is on
 * screen". That is not a claim about quantity, and the sidecar is the one artefact
 * a reviewer reads instead of the pixels — so the honest value is "there is no
 * count here", which JSON can say and a reader cannot confuse with anything else.
 *
 * `1` would keep the field's type and lose its meaning, and it would keep exactly
 * the number the empty page does NOT show: `admin-freigaben` empty asserts
 * "Keine Anträge vorhanden.", so `contentCount: 1` there reads as "one thing on
 * screen" where the truth is "nothing to review". `0` is not available either —
 * the marker DID match, and reporting `0` would say the page showed no content.
 *
 * ## Why this file takes a structural type and not the manifest's
 *
 * It is imported by `pnpm test:run` (vitest), and `ui-review.config.ts` drags in
 * the seeds, the dataset lock and `@playwright/test`. `ContentCountDescriptor` is
 * the two fields this rule reads; `UiReviewContent` satisfies it structurally, so
 * the manifest never has to be imported to test the rule — and the rule never has
 * to be re-implemented to be tested.
 */
export interface ContentCountDescriptor {
    /** The exact plain-text marker the postcondition waits for. */
    readonly text?: string;
    /**
     * Makes the `text` marker a COUNT marker. The FIRST capture group must wrap
     * the number the page displays; that number is what the sidecar records.
     */
    readonly textCount?: RegExp;
}

/** A count group must be a plain integer — `1.5`, `1,5` and ` 1 ` are not counts. */
const DIGITS_ONLY = /^\d+$/;

/**
 * The number `contentCountFrom` is willing to write into a sidecar, or `null`
 * when the marker is about presence rather than quantity.
 *
 * Fails LOUDLY when `textCount` is declared but the matched text carries no
 * number: the alternative — falling back to `null` or to a declared `1` — is
 * precisely the defect this function was written to remove, and it would do it
 * silently. `where` names the capture, so the message says which one.
 *
 * @param text   the text of the element the postcondition matched, as rendered
 * @param content the manifest entry's content descriptor
 * @param where  `"<route>" (<state>)` — used in the error message only
 */
export function contentCountFrom(text: string, content: ContentCountDescriptor, where: string): number | null {
    const pattern = content.textCount;
    if (pattern === undefined) {
        return null;
    }

    // `exec` on a /g- or /y-flagged regexp resumes at `lastIndex`, so a shared
    // pattern object would make the answer depend on how many captures ran
    // before this one. The number must be a function of the marker alone.
    pattern.lastIndex = 0;
    const match = pattern.exec(text.trim());
    if (match === null) {
        throw new Error(
            `The content postcondition of ${where} declares textCount=${String(pattern)}, but the marker it ` +
                `matched — "${text}" — contains no match for it. A count marker whose number cannot be read ` +
                'is a postcondition that would go on reporting a number nobody measured, which is the defect ' +
                'this function exists to remove: fix the pattern, or drop `textCount` if the marker is about ' +
                'presence rather than quantity.',
        );
    }

    const raw = match[1] ?? match[0];
    if (!DIGITS_ONLY.test(raw)) {
        throw new Error(
            `The content postcondition of ${where} declares textCount=${String(pattern)}, whose captured ` +
                `group "${raw}" is not a plain integer. The sidecar records counts, not formatted quantities ` +
                '— narrow the pattern so the group holds the digits and nothing else.',
        );
    }
    // `raw` survived `DIGITS_ONLY`, so it is `\d+` — never empty, never exponential,
    // never past `Number.MAX_SAFE_INTEGER`'s integer precision in a form the pattern
    // would have rejected. The radix is explicit because this file never relies on a
    // parse mode it has not stated.
    return Number.parseInt(raw, 10);
}
