{{--
    A trainee's form issued from the dossier (DocumentPdf::htmlForEnrolment):
    individual contract, internal rules, image rights. Escaped values only.
    Parameters: those of htmlForEnrolment, plus $articles (numbered titles),
    $consent (the image rights' choice boxes).
--}}
@include('documents.partials.training-head')
@include('documents.partials.training-header')

<div class="parties">
    <div>
        <div class="label">L'organisme de formation</div>
        <div class="name">{{ $company['name'] }}</div>
        @foreach ($company['address'] as $line)<div>{{ $line }}</div>@endforeach
        @if ($company['siret'])<div class="muted small">SIRET {{ $company['siret'] }}</div>@endif
    </div>
    <div>
        <div class="label">Le stagiaire</div>
        <div class="name">{{ $recipient['name'] }}</div>
        @if ($recipient['address_line1'])<div>{{ $recipient['address_line1'] }}</div>@endif
        @if ($recipient['address_line2'])<div>{{ $recipient['address_line2'] }}</div>@endif
        @if ($recipient['postal_code'] || $recipient['city'])<div>{{ trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')) }}</div>@endif
    </div>
</div>

<section>
    <h2>Action de formation</h2>
    <table class="grid">
        <tbody>
            <tr><td class="muted" style="width: 30%">Formation</td><td>{{ $training }}</td></tr>
            <tr><td class="muted">Session</td><td>{{ $session_label }}</td></tr>
            <tr><td class="muted">Dates</td><td>{{ $period }}</td></tr>
            @if ($planned)<tr><td class="muted">Durée prévue</td><td>{{ $planned }}</td></tr>@endif
            @if ($place)<tr><td class="muted">Lieu</td><td>{{ $place }}</td></tr>@endif
            @if ($employer)<tr><td class="muted">Entreprise</td><td>{{ $employer }}</td></tr>@endif
        </tbody>
    </table>
</section>

@foreach ($blocks as $i => $block)
    <section>
        <h2>@if ($articles)Article {{ $i + 1 }} - @endif{{ $block['title'] }}</h2>
        <div class="text">{{ $block['text'] }}</div>
    </section>
@endforeach

@if ($consent)
    <section>
        <h2>Choix du stagiaire</h2>
        <p>Cochez la case de votre choix :</p>
        <p>&#9744;&nbsp; J'autorise {{ $company['name'] }} à fixer, reproduire et diffuser mon image et ma voix dans les conditions ci-dessus.</p>
        <p>&#9744;&nbsp; Je n'autorise pas {{ $company['name'] }} à fixer, reproduire et diffuser mon image et ma voix.</p>
        <p class="small muted">Signature électronique : signer ce document vaut autorisation. Si vous refusez, ne signez pas et informez-en {{ $company['name'] }}.</p>
    </section>
@endif

<div class="signatures">
    <div>
        <div><strong>Pour {{ $company['name'] }}</strong></div>
        <div class="muted small">Fait @if ($city) à {{ $city }}, @endif le {{ $issued_on }}</div>
        @if ($company['signature'])<img src="{{ $company['signature'] }}" alt="Signature et cachet">@endif
    </div>
    <div>
        <div><strong>Le stagiaire</strong></div>
        <div class="muted small">Date, signature, précédées de la mention « Lu et approuvé »</div>
        <div class="signature-slot"></div>
    </div>
</div>

@if ($company['legal'])<footer class="legal small muted">{{ $company['legal'] }}</footer>@endif
</body>
</html>
