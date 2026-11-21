{{--
    Signed attendance sheet of a month (SignedSheetPdf::html → Gotenberg).
    Every value is escaped with {{ }}: never {!! !!} here. Self-contained:
    inline styles, system fonts, logo and drawings as data URIs; the CSP
    below forbids any fetch.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Feuille d'émargement signée – {{ $month }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 9pt; line-height: 1.35; color: #1f2933;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; }
        header img { max-height: 46pt; max-width: 160pt; display: block; margin-bottom: 4pt; }
        .issuer { font-size: 12pt; font-weight: 700; }
        h1 { margin: 0; font-size: 16pt; text-align: right; }
        h2 { font-size: 11pt; margin: 16pt 0 4pt; page-break-after: avoid; }
        .muted { color: #52606d; }
        table.info { border-collapse: collapse; margin: 12pt 0 4pt; }
        table.info th { text-align: left; font-weight: 700; padding: 1pt 10pt 1pt 0; }
        table.sheet { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        table.sheet th { background: #f0f4f8; text-align: left; padding: 4pt 5pt; border: 0.75pt solid #9aa5b1; font-size: 8.5pt; vertical-align: top; }
        table.sheet td { border: 0.75pt solid #9aa5b1; padding: 2pt 5pt; height: 32pt; vertical-align: middle; }
        table.sheet tr { page-break-inside: avoid; }
        td.sign img { max-height: 28pt; max-width: 110pt; display: block; }
        .validation { display: flex; gap: 12pt; margin-top: 4pt; page-break-inside: avoid; }
        .validation div { flex: 1; border: 0.75pt solid #9aa5b1; padding: 4pt 6pt; }
        .validation img { max-height: 30pt; max-width: 120pt; display: block; }
        .note { margin-top: 12pt; font-size: 8pt; }
        .annex { margin-top: 16pt; font-size: 7pt; page-break-before: auto; }
        .annex code { font-family: "DejaVu Sans Mono", monospace; word-break: break-all; }
    </style>
</head>
<body>
<header>
    <div>
        @if ($company['logo'])
            <img src="{{ $company['logo'] }}" alt="">
        @endif
        <div class="issuer">{{ $company['name'] }}</div>
    </div>
    <h1>Feuille d'émargement signée</h1>
</header>

<table class="info">
    <tr><th>Session</th><td>{{ $session }}</td></tr>
    <tr><th>Période</th><td>{{ $month }} <span class="muted">({{ $closed }})</span></td></tr>
</table>

@forelse ($days as $day)
    <h2>{{ $day['label'] }}</h2>
    @foreach ($day['halves'] as $half)
        <div class="muted">{{ $half['label'] }} : {{ implode(' ; ', $half['slots']) }}</div>
    @endforeach
    <table class="sheet">
        <thead>
        <tr>
            <th>Stagiaire</th>
            @foreach ($day['halves'] as $half)
                <th>{{ $half['label'] }}</th>
            @endforeach
            <th>Présence retenue</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($day['rows'] as $row)
            <tr class="trainee">
                <td>{{ $row['name'] }}</td>
                @foreach ($row['cells'] as $cell)
                    <td class="sign">
                        @if ($cell['state'] === 'signed')
                            @if ($cell['image'])
                                <img src="{{ $cell['image'] }}" alt="">
                            @endif
                            <span class="muted">Signé à {{ $cell['hour'] }}@if ($cell['source'] !== 'online') ({{ $cell['source'] }})@endif</span>
                        @elseif ($cell['state'] === 'absent')
                            Absent
                        @elseif ($cell['state'] === 'pending')
                            <span class="muted">Non validé</span>
                        @else
                            <span class="muted">Émargement papier ou saisie manuelle</span>
                        @endif
                    </td>
                @endforeach
                <td>{{ $row['final'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="validation">
        @foreach ($day['halves'] as $half)
            <div>
                <strong>{{ $half['label'] }}</strong> –
                @if ($half['state'] === 'validated')
                    Validé par {{ $half['validated_by'] }} ({{ $half['validator_role'] }}) le {{ $half['validated_at'] }}
                    @if ($half['validator_signature'])
                        <img src="{{ $half['validator_signature'] }}" alt="">
                    @endif
                @elseif ($half['state'] === 'pending')
                    Non validé
                @else
                    Pas d'émargement en ligne
                @endif
            </div>
        @endforeach
    </div>
@empty
    <p>Aucune séance ce mois-ci.</p>
@endforelse

<p class="note muted">
    Signature électronique simple : chaque stagiaire a signé depuis le lien d'émargement de la demi-journée, ouvert par le formateur le jour même ;
    l'heure de signature est celle du serveur (heure de Paris). Document généré le {{ $generated }}.
</p>

@if (count($annex) > 0)
    <div class="annex">
        <strong>Empreintes SHA-256 des signatures</strong>
        @foreach ($annex as $line)
            <div>{{ $line['when'] }} – {{ $line['name'] }} : <code>{{ $line['sha256'] }}</code></div>
        @endforeach
    </div>
@endif
</body>
</html>
