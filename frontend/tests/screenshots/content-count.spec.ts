import { expect, test } from '@playwright/test';
import { contentCountFrom } from './helpers/content-count';
import { routes } from './ui-review.config';
import type { UiReviewContent, UiReviewRoute, UiReviewState } from './ui-review.config';

/**
 * What the ui-review manifest declares, pinned where the manifest lives.
 *
 * `helpers/content-count.test.ts` pins the RULE (what `contentCountFrom` does
 * with a string). This file pins the DECLARATIONS — because the defect it fixes
 * (MEASURED 2026-10-01) was a declaration problem as much as a code problem: the
 * sidecar recorded a hardcoded `1` for every `text` marker, so `/konto`'s empty
 * captures claimed `1` while the page showed `0 Anträge`. A rule that works and a
 * manifest that does not use it still produce the wrong sidecar.
 *
 * ## The invariant that keeps this from rotting
 *
 * Every `textCount` in the manifest must match the marker right next to it. A
 * `textCount` that does not match makes the CAPTURE fail (fail-closed,
 * `helpers/content-count.ts`), which is loud but late: it would cost a reviewer a
 * whole capture run to find out that a pattern has a typo in it. Here it is a
 * millisecond, with no browser.
 *
 * Browser-free and fixture-free on purpose: it reads a module, it does not
 * capture anything, and it must not depend on the dataset lock or a dev server.
 */
test.describe('the ui-review manifest and the content-count rule agree', () => {
    const konto = routes.find((route) => route.name === 'konto');
    const freigaben = routes.find((route) => route.name === 'admin-freigaben');

    test('konto records the quantity it was captured for: 1 filled, 0 empty', () => {
        expect(konto, 'the /konto route must be in the manifest').toBeDefined();
        const filled = konto?.content.filled;
        const empty = konto?.content.empty;
        expect(filled?.text, 'konto/filled is a count marker, not a heading').toBe('1 Antrag');
        expect(empty?.text).toBe('0 Anträge');

        // The value the sidecar gets, computed from the marker the manifest
        // declares — which is byte-equal to what `getByText(..., {exact: true})`
        // matches and therefore to the element's `innerText()`.
        expect(contentCountFrom(filled?.text ?? '', filled ?? {}, '"konto" (filled)')).toBe(1);
        expect(contentCountFrom(empty?.text ?? '', empty ?? {}, '"konto" (empty)')).toBe(0);
    });

    test('a marker with no quantity records null, and /konto is the only count marker', () => {
        // The other half of the decision, pinned on the one PRE-EXISTING text
        // marker: `admin-freigaben`'s empty state is a presence postcondition
        // ("Keine Anträge vorhanden.") and must not be turned into a quantity.
        expect(freigaben?.content.empty?.text).toBe('Keine Anträge vorhanden.');
        expect(contentCountFrom(freigaben?.content.empty?.text ?? '', freigaben?.content.empty ?? {}, '"x" (empty)')).toBeNull();

        // And the shape of the rule across the whole manifest: a `textCount`
        // exists exactly where the marker's point IS a quantity. If a second
        // count marker is added, this list is where it gets declared.
        const countMarkers = allMarkers().filter((marker) => marker.content.textCount !== undefined);
        expect(
            countMarkers.map((marker) => `${marker.route}/${marker.state}`),
        ).toEqual(['konto/filled', 'konto/empty']);
    });

    test('every declared textCount matches the marker it is declared next to', () => {
        // The fail-closed contract, checked before a capture run would hit it.
        for (const marker of allMarkers()) {
            if (marker.content.text === undefined || marker.content.textCount === undefined) {
                continue;
            }
            const measured = contentCountFrom(marker.content.text, marker.content, `"${marker.route}" (${marker.state})`);
            expect(
                typeof measured === 'number' && Number.isInteger(measured) && measured >= 0,
                `${marker.route}/${marker.state} declares textCount=${String(marker.content.textCount)}, which ` +
                    `does not yield a count from its own marker "${marker.content.text}"`,
            ).toBe(true);
        }
    });

    test('every text marker either declares a quantity or deliberately does not', () => {
        // Named rather than left implicit: a text marker WITHOUT `textCount`
        // records `null`, i.e. "presence measured, quantity not". That is a
        // decision about a marker, so the two kinds are listed here where the
        // next person adding a route will read them.
        const presenceOnly = allMarkers()
            .filter((marker) => marker.content.text !== undefined && marker.content.textCount === undefined)
            .map((marker) => `${marker.route}/${marker.state}`);
        expect(presenceOnly.sort()).toEqual(['admin-freigaben/empty']);
    });
});

interface Marker {
    route: string;
    state: UiReviewState;
    content: UiReviewContent;
}

/** Every declared route × state content postcondition, in manifest order. */
function allMarkers(): Marker[] {
    return routes.flatMap((route: UiReviewRoute) =>
        route.states.flatMap((state) => {
            const content = route.content[state];
            // A state without a postcondition is a HARD ERROR in the capture spec
            // (`waitForContent`), not a skipped wait. Not this file's rule to
            // enforce — so it does not assert absence either.
            return content === undefined ? [] : [{ route: route.name, state, content }];
        }),
    );
}
