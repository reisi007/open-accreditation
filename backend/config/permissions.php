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
        'accreditations.view',
        'accreditations.manage',
        'mandant.media.manage',
        'teams.media.manage',
        'venues.manage',
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
    ],

    UserRole::USER->value => [
        'accreditations.self',
    ],

    UserRole::VERIFIER->value => [
        'verification.verify',
    ],

];
