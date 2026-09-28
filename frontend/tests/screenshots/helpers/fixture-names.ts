/**
 * The ui-review harness' fixture NAMESPACE — one file, one answer.
 *
 * ## Why this file exists
 *
 * The harness used to name its fixtures inside the E2E suite's namespace
 * (`E2E Akkreditierung ui-review`, `E2E Ausweis-Schema-v2 ui-review`, …) and
 * then lean on `purgeAllE2EArtifacts()` to reclaim them. That coupled two suites
 * that never agreed to be coupled, and it failed in both directions — MEASURED:
 *
 * - **E2E run → screenshot dataset destroyed.** `badge.spec.ts:16-29` swept
 *   every `E2E Ausweis*` template, so one full E2E run deleted the review's two
 *   template rows (321/322 → 0).
 * - **Screenshot run → E2E suite broken.** The review left two `E2E Ausweis*`
 *   rows behind, and the next full E2E run then failed at `badge.spec.ts:70`
 *   with a StrictMode violation (2 rows matching `/E2E Ausweis/`).
 * - **Screenshot run → the E2E suite's own data deleted.** The review called
 *   `purgeAllE2EArtifacts()` itself, so a design-QA run silently reaped
 *   whatever the E2E suite had left in the shared dev database.
 *
 * Worse, the two *within* names collided with each other:
 * `E2E Akkreditierung ui-review` is a substring of
 * `E2E Akkreditierung ui-review Druck`, and `hasText` is a substring match — so
 * the desktop capture clicked the Druck row (id 201) and the mobile capture,
 * which loads the route by URL, showed id 200. Two artifacts of ONE route, two
 * datasets, and nothing in the `.meta.json` said so.
 *
 * ## The two rules that make the next name safe
 *
 * 1. **Own your namespace, own your purge.** Every name below is built from
 *    `REVIEW_NAME_PREFIX` / `REVIEW_SLUG_PREFIX`, which no E2E marker can match
 *    (all of them are `E2E …` / `Portal-Test …` / `e2e-…`), and the harness
 *    reclaims ONLY its own rows — never the shared `purgeAllE2EArtifacts()`.
 * 2. **No review name may CONTAIN another review name.** Not "the names the
 *    harness happens to address today" — ALL of them. MEASURED, a narrowed
 *    version of this rule (only the addressed names) passed while
 *    `UI-Review Akkreditierung Presse Ausdruck` sat next to
 *    `UI-Review Akkreditierung Presse`: the collision was real, the rule did not
 *    see it, and only the URL postcondition caught the resulting wrong capture.
 *    Two names where neither contains the other cannot be confused by ANY
 *    string-matching locator, and that is a property worth more than a list of
 *    the names somebody remembered to list.
 *
 * Neither rule depends on somebody remembering it: `tests/e2e/namespace-isolation.spec.ts`
 * imports THIS file and checks both properties against the E2E suite's own
 * marker table, so a name that drifts into the other namespace fails CI instead
 * of failing a reviewer.
 */

/**
 * Display-name prefix of every review fixture. Renders in the UI under review,
 * so it stays readable — but it must not start with any E2E marker.
 */
export const REVIEW_NAME_PREFIX = 'UI-Review';

/** Slug prefix of every review fixture. Must not start with the E2E slug marker `e2e-`. */
export const REVIEW_SLUG_PREFIX = 'ui-review';

/** Local-part prefix of every review user address. Must not start with an E2E marker. */
export const REVIEW_USER_PREFIX = 'ui-review';

/** `.test` is the reserved TLD the E2E helpers already use. */
export const REVIEW_EMAIL_DOMAIN = 'example.test';

function reviewName(subject: string): string {
    return `${REVIEW_NAME_PREFIX} ${subject}`;
}

function reviewSlug(subject: string): string {
    return `${REVIEW_SLUG_PREFIX}-${subject}`;
}

/**
 * Fixture display names. The two categories are deliberately NOT a name and its
 * extension: `UI-Review Akkreditierung Presse` vs
 * `UI-Review Akkreditierung Orchester` cannot be confused by a substring match,
 * which is exactly what the old `… ui-review` / `… ui-review Druck` pair did.
 */
export const REVIEW_FIXTURE_NAMES = {
    /** Public accreditation: shown on the accreditations list, the apply page, the approvals list. */
    category: reviewName('Akkreditierung Presse'),
    categoryPrint: reviewName('Akkreditierung Orchester'),
    event: reviewName('Event Presse'),
    eventPrint: reviewName('Event Orchester'),
    /** Portal calendar entry: home page + event detail. */
    portalEvent: reviewName('Spieltag München'),
    competition: reviewName('Wettbewerb'),
    team: reviewName('FC'),
    venueHome: reviewName('Stadion'),
    venuePortal: reviewName('Halle'),
    /** The list route's row (legacy three-field layout). */
    templateList: reviewName('Ausweis Standard'),
    /** The schema-v2 layout the editor routes show AND the print check renders. */
    templateEditor: reviewName('Ausweis Schema v2'),
} as const;

export const REVIEW_FIXTURE_SLUGS = {
    category: reviewSlug('akkreditierung-presse'),
    categoryPrint: reviewSlug('akkreditierung-orchester'),
    team: reviewSlug('fc'),
} as const;

/** The review's own venues — NOT the E2E suite's `E2E Heimstadion` / `E2E Portal Arena`. */
export const REVIEW_VENUE_NAMES = {
    home: REVIEW_FIXTURE_NAMES.venueHome,
    portal: REVIEW_FIXTURE_NAMES.venuePortal,
} as const;

/** Review user addresses. Seven throwaway accounts on the reserved `.test` TLD. */
export const REVIEW_USER_EMAILS = {
    reviewer: `${REVIEW_USER_PREFIX}-reviewer@${REVIEW_EMAIL_DOMAIN}`,
    applicant: `${REVIEW_USER_PREFIX}-applicant@${REVIEW_EMAIL_DOMAIN}`,
    apply: `${REVIEW_USER_PREFIX}-apply@${REVIEW_EMAIL_DOMAIN}`,
    freigaben: `${REVIEW_USER_PREFIX}-freigaben@${REVIEW_EMAIL_DOMAIN}`,
    empty: `${REVIEW_USER_PREFIX}-empty@${REVIEW_EMAIL_DOMAIN}`,
    print: [
        `${REVIEW_USER_PREFIX}-druck-1@${REVIEW_EMAIL_DOMAIN}`,
        `${REVIEW_USER_PREFIX}-druck-2@${REVIEW_EMAIL_DOMAIN}`,
    ],
} as const;

/** Every name the review writes into the database, for the namespace invariant. */
export const ALL_REVIEW_NAMES: readonly string[] = Object.values(REVIEW_FIXTURE_NAMES);

/** Every slug the review writes into the database, for the namespace invariant. */
export const ALL_REVIEW_SLUGS: readonly string[] = Object.values(REVIEW_FIXTURE_SLUGS);

/** Every local part the review registers, for the namespace invariant. */
export const ALL_REVIEW_USER_PREFIXES: readonly string[] = [
    `${REVIEW_USER_PREFIX}-reviewer`,
    `${REVIEW_USER_PREFIX}-applicant`,
    `${REVIEW_USER_PREFIX}-apply`,
    `${REVIEW_USER_PREFIX}-freigaben`,
    `${REVIEW_USER_PREFIX}-empty`,
    `${REVIEW_USER_PREFIX}-druck-1`,
    `${REVIEW_USER_PREFIX}-druck-2`,
];
