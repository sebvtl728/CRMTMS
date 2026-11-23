{{--
    « Fiche Cerfa » of an apprenticeship contract (Admissions\Cerfa\CerfaSheet
    → Gotenberg): the headings of the Cerfa, filled or « à compléter ». Every
    value is escaped with {{ }}: never {!! !!} here. Self-contained.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Fiche Cerfa {{ $number }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1f2933; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        .muted { color: #52606d; }
        h2 { font-size: 11pt; margin: 14pt 0 4pt; color: #fff; background: #102a43; padding: 3pt 6pt; }
        table { width: 99.5%; border-collapse: collapse; }
        td { border: 0.6pt solid #9aa5b1; padding: 3pt 6pt; vertical-align: top; }
        td.k { width: 45%; color: #52606d; }
        td.todo { color: #b44d12; font-style: italic; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Contrat d'apprentissage : fiche de pré-remplissage du Cerfa</h1>
    <div class="muted">Candidature {{ $number }} - à recopier sur le portail de l'OPCO ; les champs « à compléter » ne sont pas connus du TMS.</div>
    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            @foreach ($section['rows'] as [$label, $value])
                <tr>
                    <td class="k">{{ $label }}</td>
                    @if ($value === null || $value === '')
                        <td class="todo">à compléter</td>
                    @else
                        <td>{{ $value }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>
