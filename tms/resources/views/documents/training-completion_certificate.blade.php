{{-- Attestation de fin de formation et d'assiduité (DocumentPdf::htmlForEnrolment). Escaped values only. --}}
@include('documents.partials.training-head')
@include('documents.partials.training-header')

<section style="margin-top: 18pt">
    <table class="grid">
        <tbody>
            <tr><td class="muted">Stagiaire</td><td><strong>{{ $recipient['name'] }}</strong></td></tr>
            @if ($employer)<tr><td class="muted">Entreprise</td><td>{{ $employer }}</td></tr>@endif
            <tr><td class="muted">Formation</td><td>{{ $training }}</td></tr>
            <tr><td class="muted">Période</td><td>{{ $period }}</td></tr>
            <tr><td class="muted">Heures réalisées</td><td>{{ $realised }}@if ($planned) sur {{ $planned }} prévues @endif</td></tr>
        </tbody>
    </table>
</section>

@foreach ($blocks as $block)
    <section>
        <h2>{{ $block['title'] }}</h2>
        <div class="text">{{ $block['text'] }}</div>
    </section>
@endforeach

<div class="signatures">
    <div style="flex: 0 0 46%; margin-left: auto;">
        <div>Fait @if ($city) à {{ $city }}, @endif le {{ $issued_on }}</div>
        <div><strong>Pour {{ $company['name'] }}</strong></div>
        @if ($company['signature'])<img src="{{ $company['signature'] }}" alt="Signature et cachet">@endif
    </div>
</div>

@if ($company['legal'])<footer class="legal small muted">{{ $company['legal'] }}</footer>@endif
</body>
</html>
