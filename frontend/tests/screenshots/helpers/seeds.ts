import { uiReviewDataset } from './dataset';
import type { ReviewCredentials, UiReviewDataset } from './dataset';

/**
 * Per-route seed selectors over the run's ONE dataset (`helpers/dataset.ts`).
 *
 * These used to be creators: `ensurePrimaryMandantAccreditation()` per call, a
 * fresh badge template per call, a freshly registered user per test. Measured
 * consequence: three runs of unchanged code produced 35 → 45 → 66 section bands,
 * because every run added rows and the pages grew. A reviewer could not tell a
 * layout fix from yesterday's leftovers — and a finding from the first loop of a
 * session could not be reproduced at all.
 *
 * A seed is now a *selector*: it reads what the dataset built once per run and
 * hands the spec the ids/credentials that route needs. Names, shapes and return
 * keys are unchanged, so `ui-review.config.ts` and its route notes keep their
 * meaning — what changed is WHERE the data comes from, not what the routes need.
 *
 * Note the deliberate absence of any per-test registration here: the review's
 * users are created once (they cannot be deleted again — there is no route for
 * it) and reused, so the admin users list stops growing per run.
 */

type SeedFn = () => Promise<Record<string, unknown>>;

function credentials(value: ReviewCredentials): Record<string, unknown> {
    return { email: value.email, password: value.password };
}

async function dataset(): Promise<UiReviewDataset> {
    return uiReviewDataset();
}

/** The shared event-scoped accreditation: public list, apply page, approvals. */
export const seedAccreditation: SeedFn = async () => {
    const data = await dataset();
    return {
        accreditationId: data.accreditation.id,
        categoryId: data.accreditation.categoryId,
        categoryName: data.accreditation.categoryName,
    };
};

/** The portal calendar event: home, event detail. */
export const seedPortalEvent: SeedFn = async () => {
    const data = await dataset();
    return {
        eventId: data.portalEvent.id,
        // The exact accessible name of the calendar card link. Needed because the
        // calendar lists every event of the mandant (three in the dataset), so a
        // nameless click is a list-order dependency — see the route's `note`.
        eventName: data.portalEvent.title,
        teamId: data.portalEvent.teamId,
        mandantName: data.portalEvent.mandantName,
    };
};

/** The primary mandant — the admin mandant detail deep link. */
export const seedPrimaryMandant: SeedFn = async () => {
    const data = await dataset();
    return { id: data.primaryMandantId };
};

/** The list route's badge template row (legacy three-field layout). */
export const seedBadgeTemplate: SeedFn = async () => {
    const data = await dataset();
    return { templateName: data.badgeTemplates.list.name, templateId: data.badgeTemplates.list.id };
};

/**
 * The complete schema-v2 template — the mandant's DEFAULT, the one the editor
 * routes open and the one the print check renders. `templateName` is what the
 * editor routes scope their "Bearbeiten" click to: the list is sorted newest
 * first, so clicking "the first Bearbeiten button" used to open whichever
 * template happened to have the highest id at that moment (a timing dependency,
 * i.e. one editor capture could show the three-field legacy layout and the next
 * one the nine-box layout).
 */
export const seedBadgeTemplateSchemaV2: SeedFn = async () => {
    const data = await dataset();
    return { templateName: data.badgeTemplates.editor.name, templateId: data.badgeTemplates.editor.id };
};

/** An approved application with a real QR token — the public verify result page. */
export const seedApprovedApplicationCached: SeedFn = async () => {
    const data = await dataset();
    const application = data.print.applications[0];
    if (application === undefined) {
        throw new Error('The dataset holds no approved application for the verify route');
    }
    return { token: application.token };
};

/** The accreditation + the applicant who has a REQUESTED application for it. */
export const seedApplyFilled: SeedFn = async () => {
    const data = await dataset();
    return {
        accreditationId: data.accreditation.id,
        categoryName: data.accreditation.categoryName,
        ...credentials(data.users.apply),
    };
};

/** A user with one requested application — "Meine Akkreditierungen" filled. */
export const seedMyAccreditationsFilled: SeedFn = async () => {
    const data = await dataset();
    return credentials(data.users.applicant);
};

/**
 * A mandant-scoped user — the admin users list filled. The list renders every
 * user the dev DB holds, so its row count is dominated by leftovers this
 * harness cannot reclaim (no delete route); what this suite guarantees is that
 * it does not make that number worse.
 */
export const seedUsersFilled: SeedFn = async () => {
    const data = await dataset();
    return credentials(data.users.reviewer);
};

/** One requested application — the approvals view filled. */
export const seedFreigabenFilled: SeedFn = async () => {
    const data = await dataset();
    return credentials(data.users.freigaben);
};

/** A mandant member with NO application — "Meine Akkreditierungen" empty. */
export const seedUserWithoutApplication: SeedFn = async () => {
    const data = await dataset();
    return credentials(data.users.empty);
};
