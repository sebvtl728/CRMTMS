{{--
    Summary of the Qualiopi audit file (QualityExport → Gotenberg). Every
    value is escaped with {{ }}: never {!! !!} here. Self-contained: inline
    styles, no external resource.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Dossier Qualiopi — {{ $organisme }}</title>
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1f2933; margin: 0; }
        h1 { font-size: 17pt; margin: 0 0 4pt; }
        h2 { font-size: 12.5pt; margin: 16pt 0 6pt; border-bottom: 0.75pt solid #9aa5b1; padding-bottom: 2pt; }
        .muted { color: #52606d; }
        .notice { border: 0.75pt solid #e0b252; background: #fff8e6; padding: 5pt 7pt; margin: 8pt 0; font-size: 8.5pt; }
        .indicator { border: 0.75pt solid #cbd2d9; padding: 6pt 8pt; margin-bottom: 7pt; page-break-inside: avoid; }
        .head { display: flex; justify-content: space-between; gap: 12pt; }
        .title { font-weight: 700; }
        .text { white-space: pre-wrap; margin: 3pt 0; }
        .state { font-size: 8.5pt; margin-top: 3pt; }
        .na { color: #b42318; font-weight: 700; }
        ul { margin: 3pt 0 0 14pt; padding: 0; }
        li { margin: 1pt 0; }
        .warn { color: #b54708; } .ok { color: #2f7d32; }
        .label { font-weight: 700; font-size: 8.5pt; margin-top: 4pt; }
    </style>
</head>
<body>
    <h1>Dossier Qualiopi</h1>
    <div>{{ $organisme }} — édité le {{ $date }}</div>
    <div class="muted">Référentiel : {{ $decree }}</div>
    <div class="notice">{{ $notice }}</div>
    <p><strong>Avancement :</strong> {{ $progress['ready'] }} indicateur(s) prêt(s) sur {{ $progress['applicable'] }} applicable(s).</p>

    @foreach ($criteria as $criterion => $indicators)
        <h2>Critère {{ $criterion }} — {{ $criteriaTitles[$criterion] }}</h2>
        @foreach ($indicators as $i)
            <div class="indicator">
                <div class="head">
                    <span class="title">Indicateur {{ $i['number'] }} — {{ $i['title'] }}</span>
                    <span>@if ($i['applicable']){{ $i['status_label'] }}@else<span class="na">Non applicable</span>@endif</span>
                </div>
                <div class="text">{{ $i['text'] }}</div>
                <div class="state">
                    @unless ($i['applicable'])<div><strong>Justification :</strong> {{ $i['not_applicable_reason'] }}</div>@endunless
                    @if ($i['responsible'])<div><strong>Responsable :</strong> {{ $i['responsible'] }}</div>@endif
                    @if ($i['review_on'])<div><strong>Revue prévue le :</strong> {{ $i['review_on'] }}</div>@endif
                    @if ($i['notes'])<div class="text"><strong>Notes :</strong> {{ $i['notes'] }}</div>@endif
                </div>
                @if ($i['measures'] !== [])
                    <div class="label">Mesures automatiques</div>
                    <ul>
                        @foreach ($i['measures'] as $m)
                            <li>{{ $m['label'] }} : <span class="{{ $m['tone'] }}">{{ $m['value'] }}</span>@if ($m['detail']) <span class="muted">— {{ $m['detail'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
                <div class="label">Preuves</div>
                @if ($i['proofs'] === [])
                    <div class="muted">Aucune preuve.</div>
                @else
                    <ul>
                        @foreach ($i['proofs'] as $p)
                            <li>
                                {{ $p['title'] }} <span class="muted">({{ $p['date'] }})</span>
                                @if ($p['kind'] === 'file')
                                    — @if ($p['entry']) fichier {{ $p['entry'] }} @else <span class="warn">fichier introuvable</span> @endif
                                @elseif ($p['kind'] === 'link')
                                    — {{ $p['url'] }}
                                @else
                                    <div class="text">{{ $p['note'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endforeach
</body>
</html>
