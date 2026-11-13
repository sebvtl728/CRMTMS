<!DOCTYPE html>
<html lang="fr">
<body style="font-family: sans-serif; color: #111827; line-height: 1.5;">
<p>Bonjour {{ $name }},</p>

@foreach (['overdue' => ['En retard', '#b91c1c'], 'today' => ['Aujourd\'hui', '#111827']] as $key => [$title, $color])
    @if (count($$key) > 0)
        <h2 style="font-size: 16px; color: {{ $color }}; margin-bottom: 4px;">{{ $title }} ({{ count($$key) }})</h2>
        <ul style="margin-top: 0;">
            @foreach ($$key as $item)
                <li>
                    {{ $item['subject'] }}
                    @if ($item['fiche'] !== '' && ! str_contains($item['subject'], $item['fiche']))
                        — {{ $item['fiche'] }}
                    @endif
                    <span style="color: #6b7280;">
                        ({{ $key === 'overdue' ? $item['date'].' ' : '' }}{{ $item['time'] }})
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
@endforeach

<p><a href="{{ $url }}">Voir mes actions à faire</a></p>

<p style="color: #6b7280; font-size: 12px;">
    Vous recevez ce récapitulatif car vous l'avez activé dans votre profil (« Recevoir le récapitulatif quotidien »).
</p>
<p>Cordialement,</p>
</body>
</html>
