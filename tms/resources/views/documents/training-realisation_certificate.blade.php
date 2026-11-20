{{--
    Certificat de réalisation, after the model of the Ministère du Travail:
    its text is fixed (never the organisme's texts). Escaped values only.
--}}
@include('documents.partials.training-head')
@include('documents.partials.training-header')

<section style="margin-top: 22pt">
    <p>Je soussigné(e), représentant(e) légal(e) du dispensateur de l'action concourant au développement des compétences
        <strong>{{ $company['name'] }}</strong>,</p>
    <p>atteste que <strong>{{ $recipient['name'] }}</strong>@if ($employer), salarié(e) de l'entreprise <strong>{{ $employer }}</strong>@endif,</p>
    <p>a suivi l'action <strong>{{ $training }}</strong></p>
    <p>Nature de l'action concourant au développement des compétences :</p>
    <ul style="list-style: none; padding-left: 8pt">
        <li>☒ Action de formation</li>
        <li>☐ Bilan de compétences</li>
        <li>☐ Action de VAE</li>
        <li>☐ Action de formation par apprentissage</li>
    </ul>
    <p>qui s'est déroulée {{ $period }}, pour une durée de <strong>{{ $realised }}</strong>.</p>
    <p class="small">Sans préjudice des délais imposés par les règles fiscales, comptables ou commerciales, je m'engage à
        conserver l'ensemble des pièces justificatives qui ont permis d'établir le présent certificat pendant une durée de
        3 ans à compter de la fin de l'année du dernier paiement. En cas de cofinancement des fonds européens, la durée de
        conservation est étendue conformément aux obligations conventionnelles spécifiques.</p>
</section>

<div class="signatures">
    <div style="flex: 0 0 52%; margin-left: auto;">
        <div>Fait @if ($city) à {{ $city }}, @endif le {{ $issued_on }}</div>
        <div class="muted small">Cachet et signature du responsable du dispensateur de formation</div>
        @if ($company['signature'])<img src="{{ $company['signature'] }}" alt="Signature et cachet">@endif
    </div>
</div>

@if ($company['legal'])<footer class="legal small muted">{{ $company['legal'] }}</footer>@endif
</body>
</html>
