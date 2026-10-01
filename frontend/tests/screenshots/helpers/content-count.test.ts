import { describe, expect, it } from 'vitest';
import { contentCountFrom } from './content-count';

/**
 * The rule that turns a content marker's text into the number a sidecar records
 * — and, just as importantly, the rule that refuses to invent one.
 *
 * ## Why this is a unit test and not only a screenshot run
 *
 * The defect (MEASURED 2026-10-01) was that `contentCount` was a hardcoded `1`
 * for every `text` postcondition, so all four `/konto` EMPTY captures claimed a
 * quantity the page contradicts (`0 Anträge` on screen, `1` in the sidecar).
 * Caught in a full capture run that is four red-looking-but-green rows in a JSON
 * file nobody reads line by line — and the run itself was GREEN, because the
 * postcondition did its job (the marker was on screen). The number was wrong, not
 * the wait, so nothing about the run was shaped to notice.
 *
 * So the decision is a pure function and is driven here with the exact strings the
 * manifest uses. The manifest's own entries are pinned in
 * `tests/screenshots/content-count.spec.ts`, next to the file that declares them.
 */
describe('contentCountFrom — the number a sidecar is allowed to claim', () => {
    it('reads the quantity out of a COUNT marker instead of reporting presence', () => {
        // The two `/konto` states, verbatim from `ui-review.config.ts`. `0` is the
        // whole point: it is the value the old hardcoded `1` contradicted.
        expect(contentCountFrom('1 Antrag', { text: '1 Antrag', textCount: /(\d+)/ }, '"konto" (filled)')).toBe(1);
        expect(contentCountFrom('0 Anträge', { text: '0 Anträge', textCount: /(\d+)/ }, '"konto" (empty)')).toBe(0);
    });

    it('reads a multi-digit quantity, and ignores the unit that follows it', () => {
        expect(contentCountFrom('759 Nutzer', { textCount: /(\d+)/ }, '"admin-users" (filled)')).toBe(759);
        expect(contentCountFrom('12 Anträge', { textCount: /(\d+)/ }, '"admin-freigaben" (filled)')).toBe(12);
    });

    it('records null for a marker that is about PRESENCE — not a fabricated 1', () => {
        // `admin-freigaben`'s empty marker: the page says there is nothing to
        // review. `1` would read as "one thing on screen" and `0` would say the
        // marker did not match — so `null`, which means exactly "no count here".
        expect(contentCountFrom('Keine Anträge vorhanden.', { text: 'Keine Anträge vorhanden.' }, '"x" (empty)')).toBeNull();
    });

    it('records null for a descriptor that declares no marker at all', () => {
        // The `role`-marker shape has no text to parse; its count comes from the
        // match count instead, so this helper is never asked. Answering null
        // rather than throwing keeps the two marker shapes on one path.
        expect(contentCountFrom('', {}, '"home" (filled)')).toBeNull();
    });

    it('trims surrounding whitespace, because innerText is not a declared literal', () => {
        // Playwright's `innerText()` returns what the page rendered, which is not
        // byte-equal to the manifest string when a template introduces a newline.
        expect(contentCountFrom('  0 Anträge\n', { textCount: /(\d+)/ }, '"konto" (empty)')).toBe(0);
    });

    it('THROWS when a declared count marker carries no number — rather than falling back', () => {
        // The fail-closed direction, and the one that matters: a `textCount` that
        // cannot be read must stop the capture, because the alternatives (null, or
        // a declared 1) are exactly the defect this function removes.
        expect(() => contentCountFrom('Keine Anträge vorhanden.', { textCount: /(\d+)/ }, '"konto" (empty)')).toThrow(
            /declares textCount/,
        );
        expect(() => contentCountFrom('Keine Anträge vorhanden.', { textCount: /(\d+)/ }, '"konto" (empty)')).toThrow(
            /"konto" \(empty\)/,
        );
    });

    it('THROWS when the captured group is not a plain integer', () => {
        // A formatted quantity ("1.234", "1 234") is not a count this sidecar can
        // record; guessing would put a wrong number next to the pixels.
        expect(() => contentCountFrom('1.234 Anträge', { textCount: /([\d.,]+)/ }, '"konto" (empty)')).toThrow(
            /not a plain integer/,
        );
    });

    it('is a function of the marker alone — a shared /g pattern does not resume mid-string', () => {
        // `exec` on a global regexp continues at `lastIndex`. The manifest's
        // patterns are shared across every capture of a route, so without the
        // reset the second capture of the same route would read the FIRST match
        // again — or, past the end of the string, nothing at all. The manifest
        // documents "no g/y flag"; this pins that the code does not depend on it.
        const shared = /(\d+)/g;
        expect(contentCountFrom('0 Anträge', { textCount: shared }, '"konto" (empty)')).toBe(0);
        expect(contentCountFrom('7 Anträge', { textCount: shared }, '"konto" (again)')).toBe(7);
        expect(contentCountFrom('3', { textCount: shared }, '"konto" (again)')).toBe(3);
    });
});
