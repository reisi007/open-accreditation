<?php

use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| Role → Permission Matrix (Authorization, P1d)
|--------------------------------------------------------------------------
|
| Single source of truth for the authorization layer. The matrix maps each
| role to the permissions it holds; the *scope* of every permission is enforced
| by the gate logic in `User::hasPermission()` and
| `App\Providers\AuthServiceProvider::boot()`:
|
| - `super_admin` is global and bypasses every gate (`Gate::before` → `true`),
|   regardless of the current mandant. `'*'` is a marker, not a permission.
| - `mandant_admin` permissions apply within the current mandant only
|   (`MandantContext`); a foreign mandant → deny.
| - `team_admin` permissions are scoped to the team of his role assignment
|   (`role_user.team_id`). Gates accept an optional `team_id` argument that must
|   match the assignment; without an argument the own team is used. Without a
|   team assignment (P2) → deny.
| - `user` (own accreditations) and `verifier` (door check-in) are mandant-scoped.
|
| `mandants.manage` and `teams.manage` (tenant/team CRUD) are super_admin-only —
| mandant admins manage their own mandant's content, not teams (Portal pattern,
| D2). `teams.view` (P2b-F1) opens the read-only team list for mandant_admin
| (whole mandant) and team_admin (own teams only — scoped inside the
| controller). `categories.manage` is additionally granted to team_admin for
| his own team's categories (team-scoped by the gate and re-enforced inside the
| P2b controllers — mandant-level categories stay read-only for him).
| `accreditations.view` for team_admin is the read-only D7 view on the
| Verband's accreditations of the team's persons (person scope follows in P3).
| `mandant.media.manage` (P8b) is mandant_admin-only: he manages the logo and
| header image of his OWN mandant through the self-scoped `/api/mandant/logo|header`
| surface (mandant always derived from MandantContext — never a request
| parameter, so no IDOR). super_admin keeps full control over every mandant's
| media through the existing admin surface (`mandants.manage`). team_admin,
| user and verifier hold no *mandant* media permission at all.
|
| `teams.media.manage` (W4-F1) opens the team logo writes. Unlike the tenant
| team *CRUD* (`teams.manage`, super_admin-only), the logo is staffed
| hierarchically: mandant_admin manages the logos of every team of HIS mandant
| (team resolved via the mandant-scoped route binding, foreign mandant → 404),
| team_admin only the logo of his own team(s) (`role_user.team_id`, sibling
| team → 403). super_admin manages every team globally; user and verifier hold
| no team media permission and are denied at the route gate.
|
| `users.manage` is ROLE ASSIGNMENT, `users.delete` is ACCOUNT TERMINATION —
| two different kinds of authority, and therefore two permissions.
| `users.manage` is held by `mandant_admin` (whole mandant) AND by
| `team_admin` (**his own team(s) only** — see below); `users.delete` is held by
| `mandant_admin` alone. `super_admin` holds `'*'`, which `Gate::before` grants
| mandant-independently, so cross-mandant deletion is a consequence of the
| existing bypass rather than a second, deletion-only rule. `user` and
| `verifier` hold neither and are denied at the route gate. Self-service
| deletion (`DELETE /api/user/account`) is NOT a role question at all and
| deliberately carries no gate: its target is `$request->user()`.
|
| **Why `team_admin` gets `users.manage` (Nutzerentscheid 2026-10-06).** Until
| then the separation above was a DECLARATION WITHOUT A CARRIER: `users.manage`
| and `users.delete` had identical holders, so swapping the delete route's gate
| back to `can:users.manage` was invisible (measured: 30/30 and 64/64 green).
| A `team_admin` is the smallest holder that makes the two sets differ: he
| appoints and dismisses the admins of HIS club, and cannot end anyone's
| account. The rejected alternative was a new "Mandant-Manager" role — a role
| nobody holds would have been the same missing carrier one level down.
|
| **The scope is a NEW restriction, because `users.manage` used to be
| mandant-wide.** `User::hasPermission()` returns early for every role that is
| not `team_admin` (`User.php:436-438`), so a `mandant_admin` assignment grants
| it over the whole mandant and ignores the gate's `team_id` argument. Handing
| the permission to `team_admin` therefore REQUIRES narrowing it, and that
| happens in `UserController` exactly like every other team_admin grant
| (`categories.manage`, `events.manage`): the gate passes, the controller
| re-enforces. Concretely, and **fail-closed**:
|
| - the LIST shows only the users holding a team-scoped `role_user` row in one
|   of his own teams — the only place the schema records that a user belongs to
|   a team at all (`role_user.team_id`, which `validatedRoleEntries()` reserves
|   for `team_admin` assignments and no other role);
| - the WRITE may only carry `team_admin` entries for one of his own teams; a
|   foreign `team_id` or any mandant-level role (`mandant_admin`, `user`,
|   `verifier`) is 403, not 422 — it is an authority question, not a malformed
|   payload, and `assertOwnership()` already answers it that way;
| - the REPLACE is scoped to his own teams as well, so it can neither strip the
|   target's mandant-level roles nor a sibling club's `team_admin` row;
| - a target outside that roster is 404, the same "not in your scope" answer
|   `assertMandantScopedTarget()` gives.

| `mails.dlq.manage` (Position 45, 2026-10-02) opens the dead-letter queue
| (`failed_jobs`) of undelivered MANDANT mails: list + manual requeue. It is
| held by `mandant_admin` only (`super_admin` bypasses, as always). The scope is
| enforced inside `FailedMailController`, NOT by the gate: a `mandant_admin` is
| narrowed to `failed_jobs.mandant_id = <current mandant>`, a foreign dead
| letter is a 404 — the same "foreign → 404" shape the tenant CRUD uses. Why a
| permission and not `accreditations.manage`: a `team_admin` holds the latter
| but must never read or requeue a Verband-wide delivery error list (a failure
| list carries recipient addresses; that is exactly the cross-mandant
| information the isolation rules keep apart). `team_admin`, `user` and
| `verifier` are denied at the route gate.


| `venues.manage` (W12) is held by mandant_admin AND team_admin — the same
| pair that holds `categories.manage`, and for the same reason. A venue
| (Spielstätte) is mandant-wide master data referenced by BOTH `teams.venue_id`
| and `events.venue_id`, so a row is never team-owned and the write is
| mandant-scoped, NOT team-scoped. The team_admin grant is not a privilege on
| other teams' data: it is the *picker* behind the forms he is already allowed
| to edit. The venue combobox in the team form and in the event form reads
| `GET /api/admin/venues` and offers an inline create ("Ort kann auch GUI
| mäßig mit erstellt werden"); both writes that reference a venue —
| `teams.manage` and `events.manage`, which team_admin holds — are therefore
| dead ends without it: he could assign a venue but never create one, and the
| combobox would render empty. This is precisely the reasoning that already
| gave team_admin `categories.manage` for the category picker in the same
| forms. `user` and `verifier` hold no venue permission and are denied at the
| route gate; mandant isolation is unchanged (a foreign venue is 404).
|
*/

return [

    UserRole::SUPER_ADMIN->value => [
        '*',
    ],

    UserRole::MANDANT_ADMIN->value => [
        'teams.view',
        'categories.manage',
        'events.manage',
        'users.manage',
        'users.delete',
        'accreditations.view',
        'accreditations.manage',
        'mandant.media.manage',
        'teams.media.manage',
        'venues.manage',
        // Position 45: the dead-letter queue of undelivered mandant mails.
        'mails.dlq.manage',
    ],

    UserRole::TEAM_ADMIN->value => [
        'teams.view',
        'teams.manage',
        'teams.media.manage',
        'categories.manage',
        'events.manage',
        'accreditations.manage',
        'accreditations.view',
        // W12: the venue picker behind the team and event forms this role may
        // already edit — mandant-scoped like `categories.manage`, granted so
        // the inline create never 403s. See the header note.
        'venues.manage',
        // F4 (Nutzerentscheid 2026-10-06): role assignment for HIS OWN
        // team(s). The bearer that makes `users.manage` and `users.delete`
        // differ — without it the separation was a declaration without a
        // carrier. `users.delete` is deliberately NOT here: appointing the
        // admins of his club does not let him end an account. The gate passes;
        // `UserController` narrows to his teams (header note, and the route
        // block in `routes/api.php`).
        'users.manage',
    ],

    UserRole::USER->value => [
        'accreditations.self',
    ],

    UserRole::VERIFIER->value => [
        'verification.verify',
    ],

];
