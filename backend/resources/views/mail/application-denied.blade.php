<x-mail::message>
# Dein Antrag wurde abgelehnt

Hallo {{ $userName }},

leider wurde dein Antrag für **{{ $categoryName }}** abgelehnt.

**Begründung:** {{ $reason }}

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
