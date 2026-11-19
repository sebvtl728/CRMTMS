{{-- Shared <head> and styles of the training documents (title: $title $number). --}}
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
