{{-- Convocation of a trainee (DocumentPdf::htmlForEnrolment). Escaped values only. --}}
@include('documents.partials.training-head')
@include('documents.partials.training-header')

<div class="parties">
    <div style="flex: 0 0 46%; margin-left: auto;">
        <div class="label">Stagiaire</div>
        <div class="name">{{ $recipient['name'] }}</div>
        @if ($recipient['address_line1'])<div>{{ $recipient['address_line1'] }}</div>@endif
        @if ($recipient['address_line2'])<div>{{ $recipient['address_line2'] }}</div>@endif
        @if ($recipient['postal_code'] || $recipient['city'])<div>{{ trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')) }}</div>@endif
    </div>
</div>

@foreach ($blocks as $block)
    @if ($loop->first)
        <section><div class="text" style="margin-top: 14pt">{{ $block['text'] }}</div></section>
        <section>
            <h2>{{ $session_label }}</h2>
            @if ($schedule !== [])
                <table class="grid">
                    <thead><tr><th>Date</th><th>Horaires</th><th>Lieu</th></tr></thead>
                    <tbody>
                    @foreach ($schedule as $s)
                        <tr><td>{{ $s['date'] }}</td><td>{{ $s['hours'] }}</td><td>{{ $s['place'] ?? $place ?? '-' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p>{{ $period }}@if ($place), {{ $place }}@endif</p>
            @endif
        </section>
    @else
        <section>
            <h2>{{ $block['title'] }}</h2>
            <div class="text">{{ $block['text'] }}</div>
        </section>
    @endif
@endforeach

<div class="signatures">
    <div style="flex: 0 0 46%; margin-left: auto;">
        <div><strong>Pour {{ $company['name'] }}</strong></div>
        <div class="muted small">Le {{ $issued_on }}</div>
        @if ($company['signature'])<img src="{{ $company['signature'] }}" alt="Signature et cachet">@endif
    </div>
</div>

@if ($company['legal'])<footer class="legal small muted">{{ $company['legal'] }}</footer>@endif
</body>
</html>
