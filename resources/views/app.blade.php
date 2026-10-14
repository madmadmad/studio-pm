<!DOCTYPE html>
{{-- The person's Appearance (light when unset) and brand color, so the page draws
     in them from the first frame -- base/_tokens.scss reads data-theme and the
     --brand-* variables (app/Support/BrandPalette). --}}
<html lang="en" data-theme="{{ in_array($page['props']['auth']['user']['theme'] ?? null, ['dark', 'system'], true) ? $page['props']['auth']['user']['theme'] : 'light' }}" style="{{ \App\Support\BrandPalette::style($page['props']['brand'] ?? \App\Support\BrandPalette::for(null)) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The studio's name (Settings), which heads every page's title (app.jsx). --}}
    <meta name="application-name" content="{{ \App\Models\StudioProfile::brandName() }}">
    <title inertia>{{ \App\Models\StudioProfile::brandName() }}</title>

    {{-- The installable staff app (Safari's Add to Dock, Chrome's Install): its
         name, icon and start page. Not on the client portal or the public
         invoice and proposal links, which aren't the staff app. --}}
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    @unless (request()->is('portal', 'portal/*', 'p/*', 'i/*'))
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="apple-mobile-web-app-title" content="{{ \App\Models\StudioProfile::brandName() }}">
    @endunless

    @routes
    @viteReactRefresh
    @vite(['resources/scss/app.scss', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
