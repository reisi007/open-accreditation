<?php

/*
|--------------------------------------------------------------------------
| Mandant Definitions
|--------------------------------------------------------------------------
|
| Mandanten (Verbände) werden über ihre Domain (Host-Header) dem aktuellen
| Request zugeordnet — gleiches Muster wie `brands.php` im Portal-Projekt,
| aber OHNE Themes: es gibt nur Logo/Header-Bilder und Legal-Texte.
|
| Platzhalter-Struktur. Pro Mandant:
|   - domain → Mandant-Zuordnung (Host-Header → Mandant)
|   - slug/name → Identität (URL-Segmente, UI-Labels)
|   - logo_path/header_path → Branding-Bilder
|   - legal → Impressum/Datenschutz/AGB (Text oder URL)
|
| Die echten Mandanten kommen in P1 (Migration/Modell + MandantContext-
| Middleware). Diese Datei bleibt die statische Fallback-/Dev-Konfiguration.
|
*/

return [
    'default' => 'accriditation',

    'mandants' => [
        'accriditation' => [
            'slug' => 'accriditation',
            'name' => 'Open Accriditation',
            'domain' => 'localhost',
            'logo_path' => null,
            'header_path' => null,
            'legal' => [
                'impressum' => null,
                'privacy' => null,
                'terms' => null,
            ],
            'features' => [
                'teams_enabled' => false,
            ],
            'is_active' => true,
        ],
    ],
];
