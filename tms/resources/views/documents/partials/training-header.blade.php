{{-- Shared header: issuer, title, number, date, dossier. --}}
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
