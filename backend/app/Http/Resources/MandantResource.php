<?php

namespace App\Http\Resources;

use App\Models\MandantDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'logo_url' => $this->logo_path !== null ? route('api.admin.mandants.logo', ['mandant' => $this->id]) : null,
            'header_url' => $this->header_path !== null ? route('api.admin.mandants.header', ['mandant' => $this->id]) : null,
            'impressum_text' => $this->impressum_text,
            'privacy_text' => $this->privacy_text,
            'smtp_config' => $this->smtpConfig(),
            'smtp_has_password' => $this->smtpHasPassword(),
            'teams_enabled' => (bool) $this->teams_enabled,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'domains' => $this->domainsList(),
            'teams_count' => (int) ($this->teams_count ?? $this->teams()->count()),
        ];
    }

    /**
     * The SMTP config without the `password` key. Null when no config exists.
     *
     * @return array<string, mixed>|null
     */
    private function smtpConfig(): ?array
    {
        $config = $this->smtp_config;

        if (! is_array($config)) {
            return null;
        }

        unset($config['password']);

        return $config;
    }

    private function smtpHasPassword(): bool
    {
        $config = $this->smtp_config;

        return is_array($config) && ! empty($config['password']);
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
