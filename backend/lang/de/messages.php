<?php

/**
 * DE source strings for the remaining `{message}` bodies the API answers.
 *
 * ## Where this file sits next to `mails.php`
 *
 * `mails.php` holds the admin mail surfaces (resend, requeue). This file holds
 * everything else that answers a `{message}` or an `abort()` text which a
 * reader can see: the apply guards, the sub-apply guards, the withdraw guard,
 * the wallet-pass refusals, the badge export's missing template and the media
 * endpoints' missing image. The split is by SURFACE, not by language, so both
 * files are read together when asking "what does this endpoint say".
 *
 * ## Why these strings exist at all
 *
 * The nine sites `features/mail-delivery.md` §8 cites were hardcoded literals
 * in `app/Http/Controllers/Api/`, and EIGHT of them were ENGLISH:
 * `BadgeTest.php:813`, `AccreditationTest.php:270/325/353/537` and
 * `SubAccreditationRevocationTest.php:312/338/379` (nine LINES, eight strings —
 * `:312` and `:338` are the same `WalletController::MAIN_REVOKED`). The ninth,
 * `MediaAccelRedirectTest.php:338`, was already German.
 *
 * Three MORE sites carry the same sentences and are here on purpose, not scope:
 * the main-row wallet 422 (`WalletTest:105`), the sub-row withdraw
 * (`SubApplicationController:60`, listed next to its main sibling in the E2E
 * teardown classifier) and four further `'Kein Bild hinterlegt.'` call sites.
 * Leaving any of them hardcoded would give one endpoint a catalog language and
 * its sibling a literal — the inconsistency §8 of the mail feature called out
 * when it localized the resend 422s. Ten keys in all.
 *
 * The SPA shows these bodies VERBATIM (`ApplyPage`, `MyAccreditationsPage`,
 * `ApprovalsPage` all render `err.message`), so a German reader was told
 * "Applications for this accreditation are not open yet." — the product's own
 * source language answering its users in a foreign one, on the surface the
 * applicant is looking at when a submission was refused.
 *
 * The gap is documented in `features/mail-delivery.md` §8, which lists these as
 * a sample rather than a census: a locale-negotiated string that is NOT in a
 * catalog answers in whatever language its author typed, so every remaining
 * literal is one more exception to this file's existence.
 *
 * ## `sub_accreditations.*` — five more, from a direct twin
 *
 * `SubAccreditationController::apply` is a near-copy of
 * `AccreditationController::apply`, written in a different session: six
 * `abort()` sites, five sentences, ALL of them English, while the main-row twin
 * three lines above them was already cataloged. Both are read by ONE SPA call
 * site — `MyAccreditationsPage` renders `err.message` verbatim on the sub
 * button — so this was a second "the refusal you just got is in a foreign
 * language" surface, invisible in the main-row specs.
 *
 * The group exists rather than reusing `accreditations.*` because every one of
 * the five sentences is about the SUB row, and the main-row keys are not
 * interchangeable: `messages.accreditations.already_applied` would tell a German
 * applicant about the wrong row. Same argument as
 * `applications.sub_withdraw_not_pending` one group down.
 *
 * `not_found` is the one key here that is NOT a refusal the applicant caused:
 * it answers 404 for a sub that is inactive, or whose main accreditation is.
 * It is here anyway, because the SPA can reach it — `MyAccreditationsPage`
 * renders that body verbatim on the same button.
 *
 * It is NOT the body for a foreign sub or a non-existent id, and the
 * difference is measured, not assumed: `SubAccreditation::`
 * `resolveRouteBindingQuery()` scopes the lookup to the host's mandant, so
 * those two 404 with Laravel's own `'No query results for model…'` and never
 * reach the controller (asserted in `SubAccreditationTest::
 * test_sub_apply_inactive_or_foreign_sub_is_404`). A framework body is not
 * ours to translate — the same reason `accreditations.invalid_token` stays out
 * of this file, seen from the other side.
 *
 * ## DE is the source language, and the German answers CHANGE
 *
 * `SetRequestLocale::DEFAULT_LOCALE` is `de`, so the DE catalog is what an
 * ordinary client reads. Eight of the nine cited literals were ENGLISH, so the
 * default answer to a German reader changes with this file — that is the fix,
 * not an accident, and it is why the affected specs assert the German wording
 * now. `media.no_image` was already German and stays byte-identical, so
 * `MediaAccelRedirectTest:338` and `MandantMediaSelfServiceTest:235/240` pass
 * UNTOUCHED by this file.
 *
 * ## That control is real only because of a measurement, and the measurement
 * ## changed the EN side
 *
 * Deleting `media.no_image` from THIS file turns **four** tests red: the
 * byte-for-byte test, the catalog-parity test, and the two pre-existing German
 * media specs. On an earlier draft of this work it turned only ONE red and left
 * both green — because the EN catalog then held the same German string, and
 * `config('app.fallback_locale')` is `en`, so Laravel substituted it for the
 * missing German key instead of failing.
 *
 * That is the reason `lang/en/messages.php` gives this key a real English
 * sentence instead of repeating this one. Keeping the values equal would have
 * worked only while both files stayed untouched, and it would have required a
 * hand-listed exemption in `ApiMessageLocaleTest` — the one place the next real
 * hole hides.
 *
 * ## Keys are grouped by the surface that answers them
 *
 * Nested one level (`badges.*`, `accreditations.*`, `sub_accreditations.*`,
 * `applications.*`, `wallet.*`, `media.*`), because a flat file would name the
 * SENTENCE and a grouped one names the ENDPOINT — and the reader of a 422 body
 * wants the second. Where two branches of the same contract needed different
 * nouns, they got different keys instead of one interpolated string: an English
 * admin must not read "this sub-application" about a main row, in either
 * language. `accreditations.*` and `sub_accreditations.*` are two ENDPOINTS of
 * one UI button, not two languages of one sentence — see the section above.
 *
 * Fifteen keys in six groups.
 *
 * ## What each group does NOT cover, named rather than implied
 *
 * - **`mandant.not_found`** (`'Mandant not found'`, seven controllers plus the
 *   middleware) and **`no_mandant_context`** are internal invariants of the
 *   tenancy guard, not copy: they fire where the code has already proven a
 *   mandant exists. Localizing an invariant message is how a catalog starts
 *   asserting things about itself.
 * - **`accreditations.invalid_token`** (`VerifyController`) is deliberately
 *   absent: the SPA never shows that body — `VerifyPage.tsx` replaces every 404
 *   with its own `Ungültiger Code.` — so a catalog entry would be translated
 *   text nobody can reach.
 */
return [
    'accreditations' => [
        'not_open_yet' => 'Für diese Akkreditierung sind noch keine Anträge möglich.',
        'deadline_passed' => 'Die Antragsfrist für diese Akkreditierung ist abgelaufen.',
        'already_applied' => 'Du hast dich für diese Akkreditierung bereits beworben.',
    ],

    'sub_accreditations' => [
        'not_found' => 'Sub-Akkreditierung nicht gefunden.',
        'main_not_approved' => 'Die Haupt-Akkreditierung muss zuerst freigegeben werden.',
        'not_open_yet' => 'Für diese Sub-Akkreditierung sind noch keine Anträge möglich.',
        'deadline_passed' => 'Die Antragsfrist für diese Sub-Akkreditierung ist abgelaufen.',
        'already_applied' => 'Du hast dich für diese Sub-Akkreditierung bereits beworben.',
    ],

    'applications' => [
        'withdraw_not_pending' => 'Nur Anträge im Status „beantragt“ können zurückgezogen werden.',
        'sub_withdraw_not_pending' => 'Nur Sub-Anträge im Status „beantragt“ können zurückgezogen werden.',
    ],

    'wallet' => [
        'main_revoked' => 'Die Haupt-Akkreditierung wurde zurückgezogen, dieser Wallet-Pass ist nicht mehr gültig.',
        'not_approved' => 'Nur freigegebene Anträge können als Wallet-Pass heruntergeladen werden.',
        'sub_not_approved' => 'Nur freigegebene Sub-Anträge können als Wallet-Pass heruntergeladen werden.',
    ],

    'badges' => [
        'no_template' => 'Kein Ausweis-Template vorhanden.',
    ],

    'media' => [
        'no_image' => 'Kein Bild hinterlegt.',
    ],
];
