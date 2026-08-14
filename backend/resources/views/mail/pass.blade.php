<x-mail::message>
# Dein Akkreditierungs-Ausweis

Hallo {{ $userName }},

dein Ausweis für **{{ $categoryName }}** ist fertig.

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Über den folgenden Link kannst du deinen Ausweis anzeigen:

<x-mail::button :url="$verifyUrl">
Ausweis anzeigen
</x-mail::button>

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
