<x-mail::message>
# Frist läuft bald ab

Hallo {{ $userName }},

die Bewerbungsfrist für **{{ $categoryName }}** läuft am **{{ $deadline }}** ab.

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Reiche deinen Antrag rechtzeitig ein, damit er berücksichtigt werden kann.

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
