{{--
    « Tableaux de bord » printed (lot 1e, DashboardExportController::pdf).
    Escaped values only; no external resource.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'">
    <title>Tableaux de bord</title>
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1f2933; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        .muted { color: #52606d; }
        .filters { margin: 6pt 0 12pt; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8pt; }
        .card { border: 0.75pt solid #d9e2ec; border-radius: 4pt; padding: 8pt; page-break-inside: avoid; }
        .card h2 { font-size: 9.5pt; margin: 0; color: #52606d; font-weight: 600; }
        .value { font-size: 15pt; font-weight: 700; margin: 3pt 0; }
        .formula { margin-top: 4pt; border-top: 0.5pt solid #e4e7eb; padding-top: 3pt; font-size: 8pt; color: #52606d; }
        table { border-collapse: collapse; width: 100%; margin-top: 3pt; }
        td { padding: 1pt 0; font-size: 8.5pt; }
        td.r { text-align: right; }
    </style>
</head>
<body>
    <h1>Tableaux de bord - {{ $tab }}</h1>
    <div class="muted">Période {{ $period }}, comparée à la période {{ $previous }} · imprimé le {{ $printed_on }}</div>
    @if ($filters)
        <div class="filters">
            @foreach ($filters as $name => $value)<span>{{ $name }} : {{ $value }}</span>@if (! $loop->last) · @endif @endforeach
        </div>
    @endif
    <div class="grid">
        @forelse ($cards as $card)
            <div class="card">
                <h2>{{ $card['label'] }}</h2>
                <div class="value">{{ $card['value'] === '' ? '-' : $card['value'] }}</div>
                <div class="muted">Période précédente : {{ $card['previous'] === '' ? '-' : $card['previous'] }}</div>
                @if ($card['target'])<div>Objectif : {{ $card['target'] }}</div>@endif
                @if ($card['breakdown'])
                    <table>
                        @foreach ($card['breakdown'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="r">{{ $row['value'] === '' ? '-' : $row['value'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
                @if ($card['note'])<div class="muted">{{ $card['note'] }}</div>@endif
                <div class="formula">{{ $card['formula'] }}</div>
            </div>
        @empty
            <p>Aucun indicateur à afficher avec vos droits.</p>
        @endforelse
    </div>
</body>
</html>
