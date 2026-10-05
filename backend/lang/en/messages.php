<?php

/**
 * EN translations for the `{message}` bodies the API answers outside the mail
 * surfaces. The DE source strings, the key grouping and everything this file
 * deliberately does NOT cover are documented in `lang/de/messages.php` — read
 * that one first; this file only adds the language.
 *
 * ## NINE of the TEN keys are byte-identical to the literals they replace
 *
 * They are the English the literals in `app/Http/Controllers/Api/` already
 * answered, character for character — which is a CONTRACT, not an accident of
 * typing:
 *
 *  - `frontend/tests/e2e/helpers/ownership.ts` classifies the withdraw 422 by
 *    WHOLE-MESSAGE equality against `'Only pending (requested) applications can
 *    be withdrawn.'` and its sub-row sibling. A reworded EN catalog entry would
 *    turn every carried teardown row into a failed run.
 *  - `ownership.spec.ts` asserts both bodies through that classifier.
 *
 * So the EN wording is pinned, and `Tests\Feature\ApiMessageLocaleTest` pins it
 * too — the DE half is free to be idiomatic German, because the German readers
 * of those specs are the ones the change is FOR.
 *
 * Ten keys, not nine, because two sites that share a sentence still get their
 * own key where the ROUTE differs: `wallet.not_approved` /
 * `wallet.sub_not_approved` and `applications.withdraw_not_pending` /
 * `applications.sub_withdraw_not_pending`. Sharing one key there would tell an
 * English applicant about a sub-application when he clicked the main row's
 * button.
 *
 * ## The one string that is NOT byte-identical to what it replaces
 *
 * `media.no_image` was German on both sides already, so this catalog INVENTS an
 * English variant where none existed. It is here for the same reason the 422
 * bodies are in `mails.php`: the body is what a non-SPA client (curl, a mobile
 * app) reads, and a catalog that answered German under `Accept-Language: en`
 * would be a hole in the very file whose existence is the fix.
 *
 * Nothing in the SPA can read it, and that is stated rather than hidden: the
 * media endpoints are `<img src>` deliveries, so their 404 body reaches the
 * browser and stops there. `MediaField.tsx`'s empty state is the FEATURE's own
 * Lingui string — which is why `admin-mandant.spec.ts:179` pins that and not
 * this body — and the upload/delete errors it renders come from the upload 422
 * and the 204, never from here. DE stays `'Kein Bild hinterlegt.'`
 * byte-identical, so `MediaAccelRedirectTest:338` and
 * `MandantMediaSelfServiceTest:235/240` pass UNTOUCHED by this file — and, after
 * this value stopped being German, they became a real control on it (measured:
 * deleting the DE key turns all four red; see the DE file and the test's
 * fallback note).
 *
 * ## Register, not just words
 *
 * The German `'Du hast dich …'` addresses the applicant, while the admin-facing
 * catalogs use `Sie`. One product, two audiences, and the audience is part of
 * the sentence — so the register is fixed here rather than left to whichever
 * catalog a translator reaches first.
 */
return [
    'accreditations' => [
        'not_open_yet' => 'Applications for this accreditation are not open yet.',
        'deadline_passed' => 'The application deadline for this accreditation has passed.',
        'already_applied' => 'You have already applied for this accreditation.',
    ],

    'applications' => [
        'withdraw_not_pending' => 'Only pending (requested) applications can be withdrawn.',
        'sub_withdraw_not_pending' => 'Only pending (requested) sub-applications can be withdrawn.',
    ],

    'wallet' => [
        'main_revoked' => 'The main accreditation was withdrawn, this wallet pass is no longer valid.',
        'not_approved' => 'Only approved applications can be downloaded as a wallet pass.',
        'sub_not_approved' => 'Only approved sub-applications can be downloaded as a wallet pass.',
    ],

    'badges' => [
        'no_template' => 'No badge template.',
    ],

    'media' => [
        'no_image' => 'No image set.',
    ],
];
