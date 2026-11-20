{{--
    Invoice / credit note PDF (InvoicePdf::html → Gotenberg), the charter of
    the quotes. Every typed value is escaped with {{ }}: never {!! !!} here.
    Self-contained: inline styles, system fonts, logo as a data URI; the CSP
    below forbids any fetch. Mentions of spec 0.9 § 4.4.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>{{ $title === 'AVOIR' ? 'Avoir' : 'Facture' }} {{ $invoice['number'] }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 9.5pt; line-height: 1.4; color: #1f2933;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .watermark {
            position: fixed; top: 42%; left: 0; right: 0; text-align: center;
            transform: rotate(-30deg); font-size: 72pt; font-weight: 700; letter-spacing: 4pt;
            color: rgba(200, 30, 30, 0.12); z-index: 10;
        }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; }
        .issuer { max-width: 55%; }
        .issuer img { max-height: 60pt; max-width: 180pt; margin-bottom: 6pt; display: block; }
        .issuer .name { font-size: 12pt; font-weight: 700; }
        .muted { color: #52606d; }
        .small { font-size: 8pt; }
        .title { text-align: right; }
        .title h1 { margin: 0 0 4pt; font-size: 20pt; letter-spacing: 1pt; }
        .title table td { padding: 1pt 0 1pt 10pt; text-align: right; }
        .recipient {
            margin: 18pt 0 0 auto; width: 46%; padding: 8pt 10pt;
            border: 0.75pt solid #cbd2d9; border-radius: 3pt;
        }
        .recipient .name { font-weight: 700; }
        .subject { margin: 18pt 0 8pt; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        table.lines th {
            background: #f0f4f8; text-align: left; font-weight: 700; font-size: 8.5pt;
            padding: 5pt 6pt; border-bottom: 0.75pt solid #9aa5b1;
        }
        table.lines td { padding: 5pt 6pt; border-bottom: 0.5pt solid #e4e7eb; vertical-align: top; }
        table.lines th.num, table.vat th.num { text-align: right; }
        table.lines tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .description { color: #52606d; font-size: 8.5pt; white-space: pre-line; }
        .summary { display: flex; justify-content: space-between; align-items: flex-start; gap: 24pt; margin-top: 14pt; page-break-inside: avoid; }
        table.vat { border-collapse: collapse; font-size: 8.5pt; }
        table.vat th, table.vat td { padding: 3pt 6pt; border-bottom: 0.5pt solid #e4e7eb; }
        table.vat th { text-align: left; color: #52606d; font-weight: 400; }
        table.totals { border-collapse: collapse; margin-left: auto; min-width: 42%; }
        table.totals td { padding: 4pt 6pt; }
        table.totals tr.ttc td { font-weight: 700; font-size: 11pt; border-top: 1pt solid #1f2933; }
        .notice { margin-top: 8pt; font-style: italic; }
        section.block { margin-top: 16pt; page-break-inside: avoid; }
        section.block h2 { font-size: 9.5pt; margin: 0 0 3pt; }
        .pre { white-space: pre-line; }
        .references { margin: 18pt 0 8pt; }
        .references div { margin: 1pt 0; }
        footer.legal { margin-top: 20pt; padding-top: 6pt; border-top: 0.5pt solid #cbd2d9; text-align: center; }
    </style>
</head>
<body>
@if ($watermark)
    <div class="watermark">BROUILLON</div>
@endif

<header>
    <div class="issuer">
        @if ($company['logo'])
            <img src="{{ $company['logo'] }}" alt="">
        @endif
        <div class="name">{{ $company['name'] }}</div>
        @foreach ($company['address'] as $line)
            <div>{{ $line }}</div>
        @endforeach
        @if ($company['phone'])<div class="muted">Tél. {{ $company['phone'] }}</div>@endif
        @if ($company['email'])<div class="muted">{{ $company['email'] }}</div>@endif
        @if ($company['siret'])<div class="muted">SIRET {{ $company['siret'] }}</div>@endif
        @if ($company['vat_number'])<div class="muted">N° TVA intracommunautaire : {{ $company['vat_number'] }}</div>@endif
        @if ($company['nda_number'])<div class="muted">Déclaration d'activité n° {{ $company['nda_number'] }}</div>@endif
    </div>
    <div class="title">
        <h1>{{ $title }}</h1>
        <table>
            <tr><td class="muted">N°</td><td><strong>{{ $invoice['number'] }}</strong></td></tr>
            <tr><td class="muted">Date d'émission</td><td>{{ $invoice['issued_on'] }}</td></tr>
            @if ($invoice['due_on'] && $title === 'FACTURE')
                <tr><td class="muted">Date d'échéance</td><td>{{ $invoice['due_on'] }}</td></tr>
            @endif
        </table>
    </div>
</header>

<div class="recipient">
    <div class="name">{{ $recipient['name'] }}</div>
    @if ($recipient['address_line1'])<div>{{ $recipient['address_line1'] }}</div>@endif
    @if ($recipient['address_line2'])<div>{{ $recipient['address_line2'] }}</div>@endif
    @if ($recipient['postal_code'] || $recipient['city'])<div>{{ trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')) }}</div>@endif
    @if ($recipient['siren'])<div class="muted">SIREN {{ $recipient['siren'] }}</div>@endif
    @if ($recipient['vat_number'])<div class="muted">N° TVA : {{ $recipient['vat_number'] }}</div>@endif
</div>

<div class="references">
    @if ($invoice['corrected'])
        <div><strong>Avoir sur la facture</strong> {{ $invoice['corrected']['number'] }}@if ($invoice['corrected']['issued_on']) du {{ $invoice['corrected']['issued_on'] }}@endif</div>
    @endif
    @if ($recipient['dossier_number'])<div><strong>Dossier :</strong> {{ $recipient['dossier_number'] }}</div>@endif
    @if ($recipient['agreement_number'])<div><strong>Financement - n° d'accord :</strong> {{ $recipient['agreement_number'] }}</div>@endif
    @if ($invoice['buyer_reference'])<div><strong>N° d'engagement :</strong> {{ $invoice['buyer_reference'] }}</div>@endif
    @if ($invoice['service_code'])<div><strong>Code service :</strong> {{ $invoice['service_code'] }}</div>@endif
    @if ($invoice['period'])<div><strong>Période de formation :</strong> {{ $invoice['period'] }}</div>@endif
    @if ($invoice['grouping'])<div>Facture émise par {{ $company['name'] }}, mandataire du groupement.</div>@endif
</div>

<table class="lines">
    <thead>
        <tr>
            <th>Désignation</th>
            <th class="num">Qté</th>
            <th>Unité</th>
            <th class="num">Prix unitaire HT</th>
            @unless ($invoice['exempt'])<th class="num">TVA</th>@endunless
            <th class="num">Total HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>
                    <div>{{ $line['label'] }}</div>
                    @if ($line['description'])<div class="description">{{ $line['description'] }}</div>@endif
                    @if ($line['cotraitant'])<div class="description">Réalisé par {{ $line['cotraitant'] }} (co-traitant)</div>@endif
                </td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td>{{ $line['unit'] }}</td>
                <td class="num">{{ $line['unit_price'] }}</td>
                @unless ($invoice['exempt'])<td class="num">{{ $line['vat_rate'] }}</td>@endunless
                <td class="num">{{ $line['total_ht'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="summary">
    <div>
        @unless ($invoice['exempt'])
            <table class="vat">
                <thead><tr><th>Taux de TVA</th><th class="num">Base HT</th><th class="num">Montant TVA</th></tr></thead>
                <tbody>
                    @foreach ($vatByRate as $row)
                        <tr><td>{{ $row['rate'] }}</td><td class="num">{{ $row['base'] }}</td><td class="num">{{ $row['vat'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endunless
    </div>
    <table class="totals">
        <tr><td>Total HT</td><td class="num">{{ $totals['ht'] }}</td></tr>
        <tr><td>Total TVA</td><td class="num">{{ $totals['vat'] }}</td></tr>
        <tr class="ttc"><td>{{ $invoice['exempt'] ? 'Net à payer' : 'Total TTC' }}</td><td class="num">{{ $totals['ttc'] }}</td></tr>
    </table>
</div>
@if ($invoice['vat_mention'])
    <p class="notice pre">{{ $invoice['vat_mention'] }}</p>
@endif

@if ($payment)
    <section class="block">
        <h2>Conditions de paiement</h2>
        <div class="small">{{ $payment['terms'] }} {{ $payment['no_discount'] }}</div>
        @if ($payment['penalties'])<div class="small">{{ $payment['penalties'] }}</div>@endif
    </section>
@endif

<footer class="legal small muted">
    @if ($company['footer'])<div class="pre">{{ $company['footer'] }}</div>@endif
    <div>{{ $company['legal'] }}</div>
    @if ($company['vat_number'] || $company['nda_number'])
        <div>
            @if ($company['vat_number'])N° TVA intracommunautaire : {{ $company['vat_number'] }}@endif
            @if ($company['vat_number'] && $company['nda_number']) – @endif
            @if ($company['nda_number'])Déclaration d'activité enregistrée sous le n° {{ $company['nda_number'] }}. {{ $company['nda_notice'] }}@endif
        </div>
    @endif
</footer>
</body>
</html>
