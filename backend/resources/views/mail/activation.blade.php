<x-mail::message>
# Willkommen bei der Akkreditierung!

Hallo {{ $name }},

dein Konto wurde angelegt. Bitte aktiviere es über den folgenden Link, damit
du dich anmelden kannst:

<x-mail::button :url="$activationUrl">
Konto aktivieren
</x-mail::button>

Der Link ist **{{ $validityHours }} Stunden** gültig.

Falls du dich nicht registriert hast, kannst du diese E-Mail ignorieren.

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
