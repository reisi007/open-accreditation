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
| D2). `accreditations.view` for team_admin is the read-only D7 view on the
| Verband's accreditations of the team's persons (person scope follows in P3).
|
*/

return [

    UserRole::SUPER_ADMIN->value => [
        '*',
    ],

    UserRole::MANDANT_ADMIN->value => [
        'categories.manage',
        'events.manage',
        'users.manage',
        'accreditations.view',
        'accreditations.manage',
    ],

    UserRole::TEAM_ADMIN->value => [
        'teams.manage',
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
