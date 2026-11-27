{{--
    Programme of a training (TrainingProgramme → Gotenberg). Every value is
    escaped with {{ }}: never {!! !!} here. Self-contained: inline styles,
    system fonts, logo as a data URI; the CSP below forbids any fetch.
--}}
@php
    $hours = fn (?string $h) => $h === null ? null : rtrim(rtrim(number_format((float) $h, 2, ',', ' '), '0'), ',');
    $money = fn (?string $a) => $a === null ? null : number_format((float) $a, 2, ',', ' ').' € HT';
    $t = $training;
    $sections = [
        'Public visé' => $t['audience'],
        'Prérequis' => $t['prerequisites'],
        'Méthodes et moyens pédagogiques' => $t['methods'],
        'Modalités d\'évaluation' => $t['evaluation'],
        'Sanction de la formation' => $t['sanction'],
        'Modalités et délais d\'accès' => $t['access_terms'],
        'Accessibilité aux personnes en situation de handicap' => $t['accessibility'],
        'Résultats' => $t['results'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Programme {{ $t['title'] }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 9.5pt; line-height: 1.45; color: #1f2933;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; border-bottom: 0.75pt solid #cbd2d9; padding-bottom: 8pt; }
        header img { max-height: 54pt; max-width: 170pt; display: block; margin-bottom: 4pt; }
        .company { font-weight: 700; font-size: 11pt; }
        .muted { color: #52606d; }
        .small { font-size: 8pt; }
        .updated { text-align: right; }
        h1 { font-size: 18pt; margin: 16pt 0 4pt; }
        h2 { font-size: 11pt; margin: 14pt 0 4pt; color: #102a43; border-bottom: 0.5pt solid #e4e7eb; padding-bottom: 2pt; }
        .facts { width: 99.5%; border-collapse: collapse; margin-top: 8pt; }
        .facts td { padding: 3pt 6pt; border: 0.5pt solid #e4e7eb; vertical-align: top; }
        .facts td.k { width: 32%; color: #52606d; }
        ul { margin: 2pt 0; padding-left: 14pt; }
        p { margin: 2pt 0; white-space: pre-line; }
        .module { margin: 6pt 0; page-break-inside: avoid; }
        .module .head { font-weight: 700; }
    </style>
</head>
<body>
    <header>
        <div>
            @if ($company !== null)
                @if ($company['logo'])
                    <img src="{{ $company['logo'] }}" alt="">
                @endif
                <div class="company">{{ $company['name'] }}</div>
                @if ($company['address'] !== '')
                    <div class="muted small">{{ $company['address'] }}</div>
                @endif
                <div class="muted small">
                    {{ implode(' · ', array_filter([$company['phone'], $company['email']])) }}
                </div>
                @if ($company['nda'])
                    <div class="muted small">Déclaration d'activité n° {{ $company['nda'] }}</div>
                @endif
            @endif
        </div>
        <div class="updated">
            <div style="font-weight: 700;">Programme de formation</div>
            <div class="muted small">Mis à jour le {{ $updated }}</div>
        </div>
    </header>

    <h1>{{ $t['title'] }}</h1>
    @if ($t['description'])
        <p>{{ $t['description'] }}</p>
    @endif

    <table class="facts">
        @if ($t['code'])
            <tr><td class="k">Référence</td><td>{{ $t['code'] }}</td></tr>
        @endif
        @if ($t['rncp_code'])
            <tr><td class="k">Certification</td><td>{{ $t['rncp_code'] }}</td></tr>
        @endif
        @if ($t['duration_hours'] !== null || $t['duration_days'] !== null)
            <tr>
                <td class="k">Durée</td>
                <td>{{ implode(' - ', array_filter([$t['duration_hours'] !== null ? $hours($t['duration_hours']).' h' : null, $t['duration_days'] !== null ? $hours($t['duration_days']).' jour(s)' : null])) }}</td>
            </tr>
        @endif
        @if ($t['centre_hours'] !== null || $t['company_hours'] !== null)
            <tr>
                <td class="k">Répartition</td>
                <td>{{ implode(' - ', array_filter([$t['centre_hours'] !== null ? $hours($t['centre_hours']).' h en centre' : null, $t['company_hours'] !== null ? $hours($t['company_hours']).' h en entreprise' : null])) }}</td>
            </tr>
        @endif
        @if ($t['price_company'] !== null)
            <tr><td class="k">Tarif entreprise</td><td>{{ $money($t['price_company']) }} par stagiaire</td></tr>
        @endif
        @if ($t['price_individual'] !== null)
            <tr><td class="k">Tarif particulier</td><td>{{ $money($t['price_individual']) }} par stagiaire</td></tr>
        @endif
        @if ($t['funding_modes'] !== [])
            <tr><td class="k">Financements possibles</td><td>{{ implode(', ', $t['funding_modes']) }}</td></tr>
        @endif
        @foreach ($t['custom_fields'] as $field)
            <tr><td class="k">{{ $field['label'] }}</td><td style="white-space: pre-line;">{{ $field['value'] }}</td></tr>
        @endforeach
    </table>

    @if ($t['objectives'] !== [])
        <h2>Objectifs pédagogiques</h2>
        <ul>
            @foreach ($t['objectives'] as $objective)
                <li>{{ $objective }}</li>
            @endforeach
        </ul>
    @endif

    @if ($modules !== [])
        <h2>Contenu</h2>
        @foreach ($modules as $module)
            <div class="module">
                <div class="head">
                    {{ $module['title'] }}
                    @if ($module['duration_hours'] !== null || $module['modality'] !== null)
                        <span class="muted small">({{ implode(', ', array_filter([$module['duration_hours'] !== null ? $hours($module['duration_hours']).' h' : null, $module['modality']])) }})</span>
                    @endif
                </div>
                @if ($module['content'])
                    <p>{{ $module['content'] }}</p>
                @endif
            </div>
        @endforeach
    @endif

    @foreach ($sections as $heading => $text)
        @if ($text)
            <h2>{{ $heading }}</h2>
            <p>{{ $text }}</p>
        @endif
    @endforeach
</body>
</html>
