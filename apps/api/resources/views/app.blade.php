<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- `viewport-fit=cover`, so iOS reports its rounded corners and home bar as `env(safe-area-inset-*)`
             and `.safe-area` (resources/css/app.css) can keep content clear of them. Without it the values
             are 0 and an installed app runs into both. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- The WebSocket server's application key, from this installation's own .env. Public by
             design (resources/js/lib/echo.ts). Here rather than baked into the assets, because an
             installation that pulls its images runs assets CI built, which know no installation's key. --}}
        <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        {{-- The Content-Security-Policy nonce, for the scripts that insert `<style>` elements of their
             own — Vite's dev client reads this tag by name, and resources/js/lib/cspNonce.ts hands it
             to Radix. Empty when the policy is off. --}}
        <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">

        {{-- PWA (`BLUEPRINT.md` §1.4/§17) — installable app shell metadata. Service worker
             registration lives in `resources/js/lib/registerServiceWorker.ts`, called from
             `resources/js/app.tsx`, so it stays testable TypeScript rather than inline script. --}}
        <meta name="theme-color" content="#2563eb">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/icons/icon-192.png">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">

        <title inertia>{{ config('app.name') }}</title>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
