@php
    // Only the French messages our own code passes to abort() are shown (4xx).
    // Framework messages (route not found, model not found, CSRF...) carry a
    // previous exception or an English text and are never displayed, nor is
    // anything from a server error.
    $status = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? $exception->getStatusCode() : ($status ?? 500);
    $detail = null;
    if (isset($exception) && $status < 500
        && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        && $exception->getPrevious() === null
        && $exception->getMessage() !== ''
        && ! str_starts_with($exception->getMessage(), 'The route ')) {
        $detail = $exception->getMessage();
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #171717; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        .code { font-size: .875rem; color: #737373; letter-spacing: .1em; }
        h1 { font-size: 1.5rem; margin: .5rem 0 1rem; }
        p { line-height: 1.5; color: #404040; }
        a { color: #171717; font-weight: 600; }
        @media (prefers-color-scheme: dark) {
            body { background: #0a0a0a; color: #fafafa; }
            p { color: #d4d4d4; } a { color: #fafafa; } .code { color: #a3a3a3; }
        }
    </style>
</head>
<body>
    <main>
        <div class="code">Erreur {{ $status }}</div>
        <h1>@yield('title')</h1>
        <p>{{ $detail ?? View::yieldContent('message') }}</p>
        <p><a href="{{ url('/') }}">Retour à l'accueil</a></p>
    </main>
</body>
</html>
