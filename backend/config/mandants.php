<?php

/*
|--------------------------------------------------------------------------
| Mandant (Multi-Tenancy) Configuration
|--------------------------------------------------------------------------
|
| Mandant = Verband mit eigener Domain; kein Theme, nur Logo/Header-Bilder
| und Legal-Texte (Impressum/Datenschutz). Mandanten selbst liegen in der
| Datenbank (siehe Migration `create_mandants_tables`); diese Datei hält nur
| die Basis-Konfiguration für die Auflösung.
|
| Der Host-Header des Requests wird über `mandant_domains.hostname` auf einen
| Mandant gemappt (gleiches Muster wie `brands.php` im Portal-Projekt, aber
| DB-gestützt und themenfrei).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Wie lange eine Host→Mandant-Auflösung (hostname → mandant_id, Cache-Key
    | `mandant.domain.{host}`) im Cache gehalten wird, bevor erneut aus der
    | Datenbank gelesen wird. Negativ-Auflösungen (unbekannter Host) werden
    | kürzer gecacht (siehe `MandantContext::NEGATIVE_CACHE_TTL_SECONDS`).
    |
    | Hinweis: Der Mandant-Datensatz selbst sowie der Primary-Mandant
    | (`MandantContext::default()`) werden NIE gecacht — nur die
    | Host→Mandant-Zuordnung.
    |
    */

    'cache_ttl' => (int) env('MANDANTS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Fallback Mandant (nullable slug)
    |--------------------------------------------------------------------------
    |
    | Wird als Fallback verwendet, wenn kein Primary-Mandant (`is_primary`)
    | existiert. Gilt NICHT für die Host-Auflösung: ein unbekannter Host
    | liefert weiterhin 404.
    |
    */

    'fallback_mandant' => env('MANDANTS_FALLBACK_MANDANT'),

    /*
    |--------------------------------------------------------------------------
    | Defaults for new mandants (P2 admin UI)
    |--------------------------------------------------------------------------
    |
    | Werte, mit denen in späteren Phasen neu angelegte Mandanten initialisiert
    | werden (Teams-Support, Aktivierung).
    |
    */

    'defaults' => [
        'teams_enabled' => false,
        'is_active' => true,
    ],
];
