<x-mail::message>
# Deine {{ $typeLabel }} wurde abgelehnt

Hallo {{ $userName }},

leider wurde deine {{ $typeLabel }} für **{{ $categoryName }}** abgelehnt.

**Begründung:** {{ $reason }}

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Bei Fragen wende dich bitte an deinen Verband.

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
