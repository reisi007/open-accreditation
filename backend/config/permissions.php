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
| user and verifier hold no media permission at all.
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
    ],

    UserRole::TEAM_ADMIN->value => [
        'teams.view',
        'teams.manage',
        'categories.manage',
        'events.manage',
        'accreditations.manage',
        'accreditations.view',
    ],

    UserRole::USER->value => [
        'accreditations.self',
    ],

    UserRole::VERIFIER->value => [
        'verification.verify',
    ],

];
