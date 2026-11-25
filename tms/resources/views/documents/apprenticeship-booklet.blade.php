{{--
    Apprenticeship booklet (Admissions\Booklet\BookletPdf → Gotenberg), part
    « front » (cover → planning), « follow » (monthly pages, grids, visits)
    or « all ». Every value is escaped with {{ }}: never {!! !!} here.
    Self-contained: inline styles, system fonts, logo as a data URI.
--}}
@php
    $front = $part !== 'follow';
    $follow = $part !== 'front';
    $v = fn (?string $value) => $value === null || $value === '' ? '' : $value;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:">
    <title>Livret d'apprentissage {{ $apprentice['name'] }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1f2933; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .page { page-break-before: always; }
        .page:first-child { page-break-before: auto; }
        h1 { font-size: 22pt; margin: 0 0 6pt; letter-spacing: 1pt; }
        h2 { font-size: 13pt; margin: 0 0 8pt; color: #102a43; border-bottom: 1pt solid #102a43; padding-bottom: 3pt; }
        h3 { font-size: 10.5pt; margin: 12pt 0 4pt; color: #102a43; }
        .muted { color: #52606d; }
        .small { font-size: 8pt; }
        /* 99.5 %: a 100 % table loses its right border at the edge of the page. */
        table { width: 99.5%; border-collapse: collapse; }
        thead { display: table-header-group; }
        td, th { border: 0.6pt solid #9aa5b1; padding: 3pt 5pt; vertical-align: top; text-align: left; }
        th { background: #e4e7eb; font-weight: 700; }
        td.k { width: 34%; color: #52606d; }
        .cover { text-align: center; padding-top: 60pt; }
        .cover img { max-height: 70pt; max-width: 220pt; }
        .cover .box { margin: 22pt auto; width: 80%; border: 1pt solid #102a43; padding: 12pt; text-align: left; }
        .cover .box div { margin: 4pt 0; }
        .write { height: 105pt; }
        .write-sm { height: 34pt; }
        .visit { height: 60pt; }
        .grid td.mark { width: 22pt; text-align: center; }
        td.text { white-space: pre-line; vertical-align: top; font-size: 9pt; }
        td.sig { vertical-align: top; }
        td.sig img { max-width: 100%; max-height: 40pt; display: block; }
        .grid tr { page-break-inside: avoid; }
        .at { background: #102a43; color: #fff; font-weight: 700; }
        .cp { background: #e4e7eb; font-weight: 700; }
        .check { display: inline-block; width: 9pt; height: 9pt; border: 0.8pt solid #1f2933; text-align: center; line-height: 8pt; font-size: 8pt; }
    </style>
</head>
<body>
@if ($front)
    {{-- Cover --}}
    <section class="page cover">
        @if ($company && $company['logo'])
            <img src="{{ $company['logo'] }}" alt="">
        @endif
        <h1>LIVRET D'APPRENTISSAGE</h1>
        <div class="muted">Centre de formation / apprenti.e / entreprise</div>
        <div class="box">
            <div><strong>Entreprise :</strong> {{ $employer['label'] ?? '' }}</div>
            <div><strong>Apprenti.e :</strong> {{ $apprentice['first_name'] ?? '' }} {{ $apprentice['last_name'] ?? '' }}</div>
            <div><strong>Période du contrat :</strong> du {{ $contract['starts_on'] }} au {{ $contract['ends_on'] }}</div>
            <div><strong>Métier / diplôme / certification visé :</strong> {{ $training['title'] }}{{ $training['rncp_code'] ? ' ('.$training['rncp_code'].')' : '' }}</div>
        </div>
        @if ($company)
            <div class="small muted">{{ $company['name'] }} - {{ $company['address'] }}{{ $company['phone'] ? ' - '.$company['phone'] : '' }}{{ $company['email'] ? ' - '.$company['email'] : '' }}</div>
            @if ($company['siret'] || $company['nda'])
                <div class="small muted">{{ $company['siret'] ? 'SIRET '.$company['siret'] : '' }}{{ $company['nda'] ? ' - Déclaration d\'activité n° '.$company['nda'] : '' }}</div>
            @endif
        @endif
        @if ($director_note)
            <p style="margin-top: 30pt; white-space: pre-line;">{{ $director_note }}</p>
        @endif
    </section>

    {{-- Partners --}}
    <section class="page">
        <h2>Présentation des partenaires de la formation</h2>
        <h3>Apprenti.e</h3>
        <table>
            <tr><td class="k">Nom</td><td>{{ $v($apprentice['last_name']) }}</td></tr>
            <tr><td class="k">Prénom(s)</td><td>{{ $v($apprentice['first_name']) }}</td></tr>
            <tr><td class="k">Né(e) le</td><td>{{ $v($apprentice['birth_date']) }}</td></tr>
            <tr><td class="k">À</td><td></td></tr>
            <tr><td class="k">Adresse</td><td>{{ $v($apprentice['address']) }}</td></tr>
            <tr><td class="k">Téléphone</td><td>{{ $v($apprentice['phone']) }}</td></tr>
            <tr><td class="k">E-mail</td><td>{{ $v($apprentice['email']) }}</td></tr>
            <tr><td class="k">Contrat</td><td>{{ $contract['type'] }} du {{ $contract['starts_on'] }} au {{ $contract['ends_on'] }}</td></tr>
        </table>

        <h3>Parent ou représentant légal</h3>
        <table>
            <tr><td class="k">Nom</td><td>{{ $legal['last_name'] ?? '' }}</td></tr>
            <tr><td class="k">Prénom</td><td>{{ $legal['first_name'] ?? '' }}</td></tr>
            <tr><td class="k">Lien avec l'apprenti.e</td><td>{{ $legal['relationship'] ?? '' }}</td></tr>
            <tr><td class="k">Adresse (si différente)</td><td></td></tr>
            <tr><td class="k">Téléphone</td><td>{{ $legal['phone'] ?? '' }}</td></tr>
            <tr><td class="k">E-mail</td><td>{{ $legal['email'] ?? '' }}</td></tr>
        </table>

        <h3>Employeur</h3>
        <table>
            <tr><td class="k">Raison sociale</td><td>{{ $employer['label'] ?? '' }}</td></tr>
            <tr><td class="k">Activité principale</td><td>{{ $employer['sector'] ?? '' }}</td></tr>
            <tr><td class="k">Adresse</td><td>{{ $employer['address'] ?? '' }}</td></tr>
            <tr><td class="k">Téléphone</td><td>{{ $employer['phone'] ?? '' }}</td></tr>
            <tr><td class="k">E-mail</td><td>{{ $employer['email'] ?? '' }}</td></tr>
            <tr><td class="k">Nom et prénom du dirigeant</td><td></td></tr>
            <tr><td class="k">Maître d'apprentissage</td><td>{{ $tutor['label'] ?? '' }}</td></tr>
            <tr><td class="k">Téléphone</td><td>{{ $tutor['phone'] ?? '' }}</td></tr>
            <tr><td class="k">E-mail</td><td>{{ $tutor['email'] ?? '' }}</td></tr>
            <tr><td class="k">OPCO</td><td>{{ $v($opco) }}</td></tr>
        </table>

        <h3>Centre de formation</h3>
        <table>
            <tr><td class="k">Raison sociale</td><td>{{ $company['name'] ?? '' }}</td></tr>
            <tr><td class="k">Adresse</td><td>{{ $company['address'] ?? '' }}</td></tr>
            <tr><td class="k">Téléphone</td><td>{{ $company['phone'] ?? '' }}</td></tr>
            <tr><td class="k">E-mail</td><td>{{ $company['email'] ?? '' }}</td></tr>
        </table>
    </section>

    {{-- Team --}}
    <section class="page">
        <h2>Équipe pédagogique</h2>
        @if ($team !== [])
            <table>
                <tr><th>Rôle</th><th>Nom</th><th>Téléphone</th><th>E-mail</th></tr>
                @foreach ($team as $member)
                    <tr><td>{{ $member['role'] }}</td><td>{{ $member['name'] }}</td><td>{{ $member['phone'] ?? '' }}</td><td>{{ $member['email'] ?? '' }}</td></tr>
                @endforeach
            </table>
        @endif
        <h3>Formateurs et domaines</h3>
        @if ($schedule !== null && $schedule['teachers'] !== [])
            <table>
                <tr><th>Formateur.trice</th><th>Domaine(s)</th></tr>
                @foreach ($schedule['teachers'] as $trainer)
                    <tr><td>{{ $trainer['name'] }}</td><td>{{ implode(', ', $trainer['modules']) }}</td></tr>
                @endforeach
            </table>
        @else
            <p class="muted">Les formateurs apparaîtront une fois les séances de la session planifiées.</p>
        @endif
    </section>

    {{-- Individual pathway --}}
    <section class="page">
        <h2>Proposition de parcours individualisé de formation</h2>
        <table>
            <tr><td class="k">Apprenti.e</td><td>{{ $apprentice['first_name'] ?? '' }} {{ $apprentice['last_name'] ?? '' }}</td></tr>
            <tr><td class="k">Formation</td><td>{{ $training['title'] }}</td></tr>
            <tr><td class="k">Début du parcours</td><td>{{ $contract['starts_on'] }}</td></tr>
            <tr><td class="k">Fin du parcours</td><td>{{ $contract['ends_on'] }}</td></tr>
            @if ($schedule !== null)
                <tr><td class="k">Session</td><td>{{ $schedule['session'] }}</td></tr>
                <tr><td class="k">Durée en centre (séances prévues)</td><td>{{ $schedule['centre_hours'] ?? '0' }} h</td></tr>
            @endif
        </table>
        @if ($modules !== [])
            <h3>Modules</h3>
            <table>
                <tr><th>Module / compétence professionnelle</th><th style="width: 70pt;">Durée</th><th style="width: 80pt;">Dispensé</th></tr>
                @foreach ($modules as $module)
                    <tr>
                        <td>{{ $module['title'] }}</td>
                        <td>{{ $module['hours'] !== null ? $module['hours'].' h' : '' }}</td>
                        <td>{{ $module['dispensed'] ? 'Oui' : 'Non' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </section>

    {{-- Planning --}}
    <section class="page">
        <h2>Planning prévisionnel d'alternance</h2>
        <table>
            <tr><th>Mois</th><th>Centre de formation</th><th>Entreprise</th></tr>
            @foreach ($months as $month)
                <tr>
                    <td>{{ $month['label'] }}</td>
                    <td>{{ $month['centre_hours'] !== null ? $month['centre_hours'].' h' : '' }}</td>
                    <td>Oui</td>
                </tr>
            @endforeach
        </table>
        @if ($schedule !== null && $schedule['placements'] !== [])
            <h3>Périodes en entreprise</h3>
            <table>
                <tr><th>Période</th><th>Du</th><th>Au</th><th>Entreprise</th></tr>
                @foreach ($schedule['placements'] as $placement)
                    <tr><td>{{ $placement['label'] }}</td><td>{{ $placement['from'] }}</td><td>{{ $placement['to'] }}</td><td>{{ $placement['host'] ?? '' }}</td></tr>
                @endforeach
            </table>
        @endif
    </section>
@endif

@if ($follow)
    {{-- Monthly follow-up pages (filled with the online follow-up, part B) --}}
    @foreach ($months as $month)
        @foreach ($month['places'] as $place)
            @php($where = $place === 'company' ? 'entreprise' : 'centre')
            @php($page = $month['pages'][$place])
            <section class="page">
                <h2>Suivi de l'alternance - {{ $month['label'] }}</h2>
                <h3>Travaux effectués {{ $where === 'entreprise' ? 'en entreprise' : 'en centre de formation' }}</h3>
                <p class="small muted">
                    Le {{ $where === 'entreprise' ? 'tuteur ou la tutrice' : 'formateur ou la formatrice' }} veille à faire remplir les cadres ci-dessous par l'apprenti.e :
                    activités, travaux réalisés, responsabilités confiées, initiatives prises, observations diverses.
                </p>
                <table>
                    <tr><th style="width: 70pt;"></th><th>Apprenti.e</th><th>Observations du {{ $where === 'entreprise' ? 'tuteur' : 'formateur' }}</th></tr>
                    @foreach (['w1' => 'Semaine 1', 'w2' => 'Semaine 2', 'w3' => 'Semaine 3', 'w45' => 'Semaine 4-5'] as $week => $weekLabel)
                        <tr>
                            <td>{{ $weekLabel }}</td>
                            <td class="write text">{{ $page['entries'][$week] ?? '' }}</td>
                            <td class="write text">{{ $page['observations'][$week] ?? '' }}</td>
                        </tr>
                    @endforeach
                </table>
                <h3>Signatures</h3>
                <table>
                    <tr><th>Tuteur.trice</th><th>Représentant légal</th><th>Apprenti.e</th><th>Formateur.trice</th></tr>
                    <tr>
                        @foreach ([$page['tutor'], null, $page['apprentice']] as $i => $sig)
                            <td class="write-sm sig">
                                @if ($sig !== null && $sig['status'] === 'signed')
                                    @if ($sig['drawing'])<img src="{{ $sig['drawing'] }}" alt="Signature">@endif
                                    <div class="small">Signé en ligne le {{ $sig['at'] }}@if ($sig['name']) par {{ $sig['name'] }}@endif</div>
                                @elseif ($sig !== null && $sig['status'] === 'paper')
                                    <div class="small muted">Signé sur papier</div>
                                @endif
                            </td>
                        @endforeach
                        <td class="write-sm sig">
                            @if ($page['visa'])
                                <div class="small">Visé par {{ $page['visa']['by'] ?? '' }} le {{ $page['visa']['at'] }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </section>
        @endforeach
    @endforeach

    {{-- Competence grids --}}
    @if ($grid !== [])
        <section class="page">
            <h2>Grilles d'évaluation - {{ $training['title'] }}</h2>
            <p class="small muted">A : acquis - ECA : en cours d'acquisition - NA : non acquis</p>
            <table class="grid">
                <thead>
                    <tr><th></th><th colspan="3" style="text-align: center;">Centre de formation</th><th colspan="3" style="text-align: center;">Entreprise</th></tr>
                    <tr><th></th><th>A</th><th>ECA</th><th>NA</th><th>A</th><th>ECA</th><th>NA</th></tr>
                </thead>
                @foreach ($grid as $i => $activity)
                    <tr><td colspan="7" class="at">Activité type {{ $i + 1 }} - {{ $activity['title'] }}</td></tr>
                    @foreach ($activity['competences'] as $competence)
                        <tr><td colspan="7" class="cp">{{ $competence['title'] }}</td></tr>
                        @foreach ($competence['skills'] as $skill)
                            <tr>
                                <td>{{ $skill['title'] }}</td>
                                @foreach (['centre', 'company'] as $side)
                                    @foreach (['A', 'ECA', 'NA'] as $level)
                                        <td class="mark">{{ $skill[$side] === $level ? 'X' : '' }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            </table>
        </section>
    @endif

    {{-- Company visits --}}
    <section class="page">
        <h2>Suivis en entreprise et accompagnements</h2>
        <table>
            <tr><td class="k">Apprenti.e</td><td>{{ $apprentice['first_name'] ?? '' }} {{ $apprentice['last_name'] ?? '' }}</td></tr>
            <tr><td class="k">Entreprise</td><td>{{ $employer['label'] ?? '' }}</td></tr>
            <tr><td class="k">Formation</td><td>{{ $training['title'] }}</td></tr>
        </table>
        <h3>Visites</h3>
        <table>
            <tr><th style="width: 60pt;">Date</th><th>Points abordés</th><th>Formateur.trice et émargement</th><th>Tuteur.trice, émargement et cachet</th></tr>
            @foreach ($visits as $visit)
                <tr><td class="visit">{{ $visit['visited_on'] }}</td><td class="visit text">{{ $visit['points'] }}</td><td class="visit">{{ $visit['trainer'] ?? '' }}</td><td class="visit"></td></tr>
            @endforeach
            @for ($i = 0; $i < max(2, 6 - count($visits)); $i++)
                <tr><td class="visit"></td><td class="visit"></td><td class="visit"></td><td class="visit"></td></tr>
            @endfor
        </table>
    </section>
@endif
</body>
</html>
