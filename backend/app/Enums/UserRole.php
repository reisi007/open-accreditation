<?php

namespace App\Enums;

/**
 * The five system roles. Stored as `roles.slug` (single source of truth for
 * the pivot `role_user.role_id`). `super_admin` is global (no mandant scope);
 * all other roles are scoped via `role_user.mandant_id`.
 */
enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case MANDANT_ADMIN = 'mandant_admin';
    case TEAM_ADMIN = 'team_admin';
    case USER = 'user';
    case VERIFIER = 'verifier';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::MANDANT_ADMIN => 'Mandant Admin',
            self::TEAM_ADMIN => 'Team Admin',
            self::USER => 'User',
            self::VERIFIER => 'Verifier',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Global administrator, not scoped to a mandant.',
            self::MANDANT_ADMIN => 'Administrator of a single mandant (Verband).',
            self::TEAM_ADMIN => 'Administrator of a team within a mandant (P2).',
            self::USER => 'Regular accredited user.',
            self::VERIFIER => 'Door/check-in verifier at events.',
        };
    }
}
