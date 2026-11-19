{{--
    Copy of an online positioning test (OnlineAttemptPdf::html → Gotenberg).
    Every value is escaped with {{ }}: never {!! !!} here (the answers are
    typed by the candidate). Self-contained: inline styles, logo as a data URI.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Test de positionnement — {{ $candidate }}</title>
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.4; color: #1f2933; margin: 0; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; }
        header img { max-height: 46pt; max-width: 160pt; display: block; margin-bottom: 4pt; }
        .issuer { font-size: 12pt; font-weight: 700; }
        h1 { margin: 0; font-size: 16pt; text-align: right; }
        .muted { color: #52606d; }
        table.info { border-collapse: collapse; margin: 12pt 0; width: 100%; }
        table.info th { text-align: left; width: 26%; padding: 2pt 6pt 2pt 0; vertical-align: top; }
        .score { font-size: 14pt; font-weight: 700; }
        h2 { font-size: 12pt; margin: 14pt 0 6pt; border-bottom: 0.75pt solid #9aa5b1; padding-bottom: 2pt; }
        .question { border: 0.75pt solid #cbd2d9; padding: 6pt 8pt; margin-bottom: 6pt; page-break-inside: avoid; }
        .label { font-weight: 700; }
        .answer { white-space: pre-wrap; margin-top: 3pt; }
        .meta { font-size: 9pt; margin-top: 3pt; }
        .ok { color: #2f7d32; } .ko { color: #b42318; }
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
    <h1>Test de positionnement</h1>
</header>

<table class="info">
    <tr><th>Candidat</th><td>{{ $candidate }} <span class="muted">({{ $number }})</span></td></tr>
    <tr><th>Test</th><td>{{ $test['title'] }} <span class="muted">(version {{ $test['version'] }})</span></td></tr>
    <tr><th>Commencé le</th><td>{{ $started }}</td></tr>
    <tr><th>Envoyé le</th><td>{{ $submitted ?? 'non envoyé' }}@if ($test['minutes'] !== null) <span class="muted">({{ $test['minutes'] }} min)</span>@endif</td></tr>
    <tr><th>Sorties de la page</th><td>{{ $test['exits'] }} sortie{{ $test['exits'] > 1 ? 's' : '' }} de la page · {{ $test['blocked_pastes'] }} collage{{ $test['blocked_pastes'] > 1 ? 's' : '' }} bloqué{{ $test['blocked_pastes'] > 1 ? 's' : '' }}</td></tr>
    <tr><th>Note</th><td class="score">{{ $score }}</td></tr>
</table>

@foreach ($test['sections'] as $section)
    <h2>{{ $section['title'] }}</h2>
    @foreach ($section['questions'] as $q)
        <div class="question">
            <div class="label">{{ $loop->iteration }}. {{ $q['label'] }}</div>
            <div class="answer">{{ $q['given'] ?? 'Sans réponse' }}</div>
            <div class="meta muted">
                @if ($q['right'] !== null)
                    Attendu : {{ $q['right'] }} ·
                    <span class="{{ (int) $q['earned'] === (int) $q['points'] ? 'ok' : 'ko' }}">{{ (int) $q['earned'] }} / {{ $q['points'] }}</span>
                @elseif ($q['manual'])
                    Note : {{ $q['earned'] === null ? 'à corriger' : $q['earned'] }} / {{ $q['points'] }}
                @else
                    Question de profil
                @endif
            </div>
        </div>
    @endforeach
@endforeach
</body>
</html>
