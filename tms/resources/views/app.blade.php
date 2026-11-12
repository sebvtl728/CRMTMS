<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Lets the Vite dev client tag the <style> elements it injects with the CSP nonce. --}}
        <meta property="csp-nonce" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
