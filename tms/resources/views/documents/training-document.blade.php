{{--
    A training document (DocumentPdf::html → Gotenberg): convention or
    contract. Every value is escaped with {{ }}: never {!! !!} here (texts
    are typed by the organisme). Self-contained: inline styles, images as
    data URIs; the CSP below forbids any fetch.
    Parameters: $title, $number, $issued_on, $company, $recipient,
    $dossier_number, $lines, $price, $sessions, $trainees, $blocks,
    $partyLabel (« Le contractant » / « Le stagiaire »).
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>{{ $title }} {{ $number }}</title>
    <style>
        @page { size: A4; margin: 16mm 16mm 18mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.45; color: #1f2933; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; }
        .issuer { max-width: 55%; }
        .issuer img { max-height: 60pt; max-width: 180pt; margin-bottom: 6pt; display: block; }
        .issuer .name { font-size: 12pt; font-weight: 700; }
        .muted { color: #52606d; }
        .small { font-size: 8pt; }
        .title { text-align: right; }
        .title h1 { margin: 0 0 4pt; font-size: 17pt; }
        .title table td { padding: 1pt 0 1pt 10pt; text-align: right; }
        .parties { display: flex; gap: 16pt; margin-top: 16pt; }
        .parties > div { flex: 1; border: 0.75pt solid #cbd2d9; border-radius: 3pt; padding: 8pt 10pt; }
        .parties .label { font-size: 8pt; color: #52606d; text-transform: uppercase; letter-spacing: 0.5pt; }
        .parties .name { font-weight: 700; }
        h2 { font-size: 10pt; margin: 14pt 0 3pt; }
        .text { white-space: pre-line; text-align: justify; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        table.grid th { background: #f0f4f8; text-align: left; font-size: 8.5pt; padding: 4pt 6pt; border-bottom: 0.75pt solid #9aa5b1; }
        table.grid td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e4e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        section { page-break-inside: avoid; }
        .signatures { display: flex; gap: 16pt; margin-top: 24pt; page-break-inside: avoid; }
        .signatures > div { flex: 1; border: 0.75pt dashed #9aa5b1; padding: 8pt 10pt; min-height: 90pt; }
        .signatures img { max-height: 70pt; max-width: 200pt; display: block; margin-top: 6pt; }
        footer.legal { margin-top: 18pt; padding-top: 6pt; border-top: 0.5pt solid #cbd2d9; text-align: center; }
    </style>
</head>
<body>
<header>
    <div class="issuer">
        @if ($company['logo'])
            <img src="{{ $company['logo'] }}" alt="">
        @endif
        <div class="name">{{ $company['name'] }}</div>
        @foreach ($company['address'] as $line)
            <div>{{ $line }}</div>
        @endforeach
        @if ($company['nda_number'])<div class="muted small">Déclaration d'activité n° {{ $company['nda_number'] }}</div>@endif
    </div>
    <div class="title">
        <h1>{{ $title }}</h1>
        <table>
            <tr><td class="muted">N°</td><td><strong>{{ $number }}</strong></td></tr>
            <tr><td class="muted">Date</td><td>{{ $issued_on }}</td></tr>
            <tr><td class="muted">Dossier</td><td>{{ $dossier_number }}</td></tr>
        </table>
    </div>
</header>

<div class="parties">
    <div>
        <div class="label">L'organisme de formation</div>
        <div class="name">{{ $company['name'] }}</div>
        @foreach ($company['address'] as $line)<div>{{ $line }}</div>@endforeach
        @if ($company['siret'])<div class="muted small">SIRET {{ $company['siret'] }}</div>@endif
    </div>
    <div>
        <div class="label">{{ $partyLabel }}</div>
        <div class="name">{{ $recipient['name'] }}</div>
        @if ($recipient['attention'])<div>À l'attention de {{ $recipient['attention'] }}</div>@endif
        @if ($recipient['address_line1'])<div>{{ $recipient['address_line1'] }}</div>@endif
        @if ($recipient['address_line2'])<div>{{ $recipient['address_line2'] }}</div>@endif
        @if ($recipient['postal_code'] || $recipient['city'])<div>{{ trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')) }}</div>@endif
    </div>
</div>

<section>
    <h2>Action de formation</h2>
    <table class="grid">
        <thead><tr><th>Session</th><th>Dates</th><th class="num" style="text-align: right">Durée</th><th>Lieu</th></tr></thead>
        <tbody>
        @foreach ($sessions as $s)
            <tr><td>{{ $s['label'] }}</td><td>{{ $s['period'] }}</td><td class="num">{{ $s['hours'] ?? '-' }}</td><td>{{ $s['place'] ?? '-' }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($trainees !== [])
        <p><strong>{{ count($trainees) > 1 ? 'Stagiaires' : 'Stagiaire' }} :</strong> {{ implode(', ', $trainees) }}</p>
    @endif
</section>

@foreach ($blocks as $i => $block)
    <section>
        <h2>Article {{ $i + 1 }} - {{ $block['title'] }}</h2>
        <div class="text">{{ $block['text'] }}</div>
    </section>
@endforeach

<section>
    <h2>Prix</h2>
    <table class="grid">
        <thead><tr><th>Désignation</th><th class="num" style="text-align: right">Montant</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td>{{ $line['label'] }}</td><td class="num">{{ $line['total'] }}</td></tr>
        @endforeach
        <tr><td><strong>Total</strong></td><td class="num"><strong>{{ $price }}</strong></td></tr>
        </tbody>
    </table>
    @if ($company['vat_notice'])<p class="small muted">{{ $company['vat_notice'] }}</p>@endif
</section>

<div class="signatures">
    <div>
        <div><strong>Pour {{ $company['name'] }}</strong></div>
        <div class="muted small">Le {{ $issued_on }}</div>
        @if ($company['signature'])
            <img src="{{ $company['signature'] }}" alt="Signature et cachet">
        @endif
    </div>
    <div>
        <div><strong>{{ $partyLabel }}</strong></div>
        <div class="muted small">Date, signature, précédées de la mention « Lu et approuvé »</div>
    </div>
</div>

@if ($company['legal'])
    <footer class="legal small muted">{{ $company['legal'] }}</footer>
@endif
</body>
</html>
