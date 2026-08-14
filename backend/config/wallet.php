<?php

/*
|--------------------------------------------------------------------------
| Wallet Configuration (P6 — Apple Wallet .pkpass + Google Wallet)
|--------------------------------------------------------------------------
|
| All values are optional and read from env. **No real certificates or
| credentials are committed to this repository** — without them the services
| degrade as documented:
|
|   Apple:  an UNSIGNED .pkpass bundle. The structure (pass.json, icons,
|           manifest.json, zip) is fully valid, but the `signature` file is
|           missing — iOS refuses to install an unsigned pass. The bundle
|           stays usable for structure/debug tooling and for the E2E contract
|           of the download endpoint.
|   Google: without a service-account key the "save to wallet" endpoint
|           answers a structurally valid EventTicketObject JSON instead of a
|           signed JWT (Google's saveWallet API needs the JWT, so the raw
|           object is only a preview/debug representation).
|
| Apple Wallet signing requires a pass-signing certificate (`WALLET_CERT`),
| its private key (`WALLET_KEY`, optional `WALLET_KEY_PASSWORD`) and the Apple
| WWDR intermediate certificate (`WALLET_WWDR`). Google requires the service
| account email + a private key (`GOOGLE_SERVICE_ACCOUNT_KEY`, either a PEM
| string, a path to a PEM file, or a JWK) and the issuer id.
|
*/

return [

    'apple' => [

        /*
        |--------------------------------------------------------------------------
        | Pass type identifier (reverse-DNS, e. g. `pass.accriditation.test`)
        |--------------------------------------------------------------------------
        */

        'pass_type_id' => env('WALLET_PASS_TYPE_ID', 'pass.accriditation.test'),

        /*
        |--------------------------------------------------------------------------
        | Apple team identifier (10-char hex team id)
        |--------------------------------------------------------------------------
        */

        'team_id' => env('WALLET_TEAM_ID'),

        /*
        |--------------------------------------------------------------------------
        | Organization name shown on the pass. Null → the owning mandant's name.
        |--------------------------------------------------------------------------
        */

        'organization_name' => env('WALLET_ORG_NAME'),

        /*
        |--------------------------------------------------------------------------
        | Signing certificates (paths to PEM files, nullable)
        |--------------------------------------------------------------------------
        */

        'cert' => env('WALLET_CERT'),
        'key' => env('WALLET_KEY'),
        'key_password' => env('WALLET_KEY_PASSWORD'),
        'wwdr' => env('WALLET_WWDR'),
    ],

    'google' => [

        /*
        |--------------------------------------------------------------------------
        | Google Pay issuer id (`GOOGLE_ISSUER_ID`, set in the Google Wallet
        | API console)
        |--------------------------------------------------------------------------
        */

        'issuer_id' => env('GOOGLE_ISSUER_ID'),

        /*
        |--------------------------------------------------------------------------
        | Service account email + private key. `service_account_key` accepts a
        | PEM string, a path to a PEM file, or a JWK JSON — the service
        | normalizes all three.
        |--------------------------------------------------------------------------
        */

        'service_account_email' => env('GOOGLE_SERVICE_ACCOUNT_EMAIL'),
        'service_account_key' => env('GOOGLE_SERVICE_ACCOUNT_KEY'),

        /*
        |--------------------------------------------------------------------------
        | Class id suffix. Combined into the full class id
        | `{issuerId}.{classId}`; object ids become `{issuerId}.{classId}.{id}`.
        |--------------------------------------------------------------------------
        */

        'class_id' => env('GOOGLE_CLASS_ID', 'accriditation'),
    ],
];
