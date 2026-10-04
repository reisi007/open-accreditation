<x-mail::message>
# Deine {{ $typeLabel }} wurde freigegeben

Hallo {{ $userName }},

deine {{ $typeLabel }} für **{{ $categoryName }}** wurde freigegeben.

@if ($eventTitle)
Veranstaltung: **{{ $eventTitle }}**
@endif

@if ($teamName)
Verein: **{{ $teamName }}**
@endif

Dein Pass liegt dieser E-Mail als Anhang bei: die `.pkpass`-Datei für Apple
Wallet und die Google-Wallet-Datei. In deinem Wallet-Pass findest du außerdem
den QR-Code zur Prüfung deiner Akkreditierung.

@if ($verifyUrl !== '')
<x-mail::button :url="$verifyUrl">
Zugehörige Akkreditierung anzeigen
</x-mail::button>
@endif

Viele Grüße<br>
Dein Akkreditierungs-Team
</x-mail::message>
