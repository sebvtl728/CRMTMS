{{--
    Attendance sheet PDF (AttendanceSheetPdf::html → Gotenberg). Every typed
    value is escaped with {{ }}: never {!! !!} here. Self-contained: inline
    styles, system fonts, logo as a data URI; the CSP below forbids any fetch.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Feuille d'émargement du {{ $date }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 10pt; line-height: 1.4; color: #1f2933;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; }
        header img { max-height: 50pt; max-width: 160pt; display: block; margin-bottom: 4pt; }
        .issuer { font-size: 12pt; font-weight: 700; }
        h1 { margin: 0; font-size: 18pt; text-align: right; }
        .muted { color: #52606d; }
        table.info { border-collapse: collapse; margin: 14pt 0 10pt; width: 100%; }
        table.info th { text-align: left; width: 22%; font-weight: 700; padding: 2pt 6pt 2pt 0; vertical-align: top; }
        table.info td { padding: 2pt 0; }
        table.sheet { width: 100%; border-collapse: collapse; margin-top: 8pt; }
        table.sheet th { background: #f0f4f8; text-align: left; padding: 5pt 6pt; border: 0.75pt solid #9aa5b1; font-size: 9pt; }
        table.sheet td { border: 0.75pt solid #9aa5b1; padding: 0 6pt; height: 34pt; vertical-align: middle; }
        table.sheet tr { page-break-inside: avoid; }
        td.sign { width: 28%; }
        .trainer-boxes { display: flex; gap: 12pt; margin-top: 18pt; page-break-inside: avoid; }
        .trainer-box { flex: 1; border: 0.75pt solid #9aa5b1; padding: 6pt 8pt; min-height: 60pt; }
        .note { margin-top: 10pt; font-size: 8pt; }
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
    <h1>Feuille d'émargement</h1>
</header>

<table class="info">
    <tr><th>Session</th><td>{{ $session }}</td></tr>
    <tr><th>Date</th><td>{{ $date }}</td></tr>
    @foreach ($slots as $slot)
        <tr>
            <th>@if ($loop->first){{ $single ? 'Créneau' : 'Créneaux' }}@endif</th>
            <td>{{ $slot['start'] }} – {{ $slot['end'] }} : {{ $slot['module'] }}@if ($slot['trainer']) <span class="muted">({{ $slot['trainer'] }})</span>@endif</td>
        </tr>
    @endforeach
    <tr><th>{{ count($trainers) > 1 ? 'Formateurs' : 'Formateur' }}</th><td>{{ count($trainers) > 0 ? implode(', ', $trainers) : 'À préciser' }}</td></tr>
</table>

<table class="sheet">
    <thead>
        <tr>
            <th>Stagiaire</th>
            @if ($morning)<th>Matin (signature)</th>@endif
            @if ($afternoon)<th>Après-midi (signature)</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse ($trainees as $name)
            <tr class="trainee">
                <td>{{ $name }}</td>
                @if ($morning)<td class="sign"></td>@endif
                @if ($afternoon)<td class="sign"></td>@endif
            </tr>
        @empty
            <tr><td colspan="3" class="muted">Aucun stagiaire attendu à cette date.</td></tr>
        @endforelse
    </tbody>
</table>

<div class="trainer-boxes">
    @if ($morning)<div class="trainer-box"><strong>Formateur : matin</strong><br><span class="muted small">Nom et signature</span></div>@endif
    @if ($afternoon)<div class="trainer-box"><strong>Formateur : après-midi</strong><br><span class="muted small">Nom et signature</span></div>@endif
</div>

<p class="note muted">Chaque stagiaire signe dans la case de chaque demi-journée suivie. Scannez la feuille signée et déposez-la dans le TMS.</p>
</body>
</html>
