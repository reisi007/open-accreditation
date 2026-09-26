<?php

namespace App\Http\Resources;

use App\Models\MandantDomain;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

/**
 * Public representation of a mandant (Verband) for the Super Admin API.
 *
 * Never serializes the SMTP password — it is only reflected as the boolean
 * `smtp_has_password`; storage paths (`logo_path`/`header_path`) are exposed
 * exclusively through the auth-gated `logo_url`/`header_url` delivery routes.
 */
class MandantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Read the cast ONCE: `smtp_config` is `encrypted:json`, so a row
        // written before that cast throws on every read (F4) — and reading it
        // twice would log the same remediation hint twice per row.
        $smtpConfig = $this->readSmtpConfig();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'logo_url' => $this->logo_path !== null ? route('api.admin.mandants.logo', ['mandant' => $this->id]) : null,
            'header_url' => $this->header_path !== null ? route('api.admin.mandants.header', ['mandant' => $this->id]) : null,
            'impressum_text' => $this->impressum_text,
            'privacy_text' => $this->privacy_text,
            'smtp_config' => $this->smtpConfig($smtpConfig),
            'smtp_has_password' => $this->smtpHasPassword($smtpConfig),
            'teams_enabled' => (bool) $this->teams_enabled,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'domains' => $this->domainsList(),
            'teams_count' => (int) ($this->teams_count ?? $this->teams()->count()),
        ];
    }

    /**
     * The stored `smtp_config`, or null when there is none.
     *
     * F4: `smtp_config` is `encrypted:json` (WP-6-d), so rows written BEFORE that
     * cast hold plain JSON and the encrypter raises `DecryptException` on every
     * read. This resource is serialized for the whole mandant list, so one such
     * row took the ENTIRE admin list down with a 500 — a single unremediated
     * tenant blocked the page for every other tenant.
     *
     * The unreadable config therefore degrades to "no config" (`smtp_config`
     * null, `smtp_has_password` false) and the row is LOGGED, which is the
     * operator's signal that this mandant is still awaiting the documented
     * re-save. The write path degrades the same way; see
     * `MandantController::readStoredSmtpConfig()`.
     *
     * @return array<string, mixed>|null
     */
    private function readSmtpConfig(): ?array
    {
        try {
            $config = $this->smtp_config;
        } catch (DecryptException) {
            Log::warning('A mandant row carries an smtp_config that predates the encrypted cast; it is reported as "no config" until the operator re-saves it.', [
                'mandant_id' => $this->id,
            ]);

            return null;
        }

        return is_array($config) ? $config : null;
    }

    /**
     * The SMTP config without the `password` key. Null when no config exists.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function smtpConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        unset($config['password']);

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function smtpHasPassword(?array $config): bool
    {
        return $config !== null && ! empty($config['password']);
    }

    /**
     * Domain rows, loaded lazily when the relation was not eager-loaded.
     *
     * @return list<array{id: int, hostname: string}>
     */
    private function domainsList(): array
    {
        $domains = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();

        return $domains
            ->map(fn (MandantDomain $domain): array => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
            ])
            ->values()
            ->all();
    }
}
