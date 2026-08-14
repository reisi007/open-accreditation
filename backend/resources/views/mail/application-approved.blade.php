<x-mail::message>
# Dein Antrag wurde freigegeben

Hallo {{ $userName }},

dein Antrag für **{{ $categoryName }}** wurde freigegeben.

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Deinen Ausweis kannst du über den folgenden Link abrufen:

<x-mail::button :url="$verifyUrl">
Ausweis anzeigen
</x-mail::button>

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
