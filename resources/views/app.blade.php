<!DOCTYPE html>
{{-- The person's Appearance (dark when unset) and brand color, so the page draws
     in them from the first frame -- base/_tokens.scss reads data-theme and the
     --brand-* variables (app/Support/BrandPalette). --}}
<html lang="en" data-theme="{{ in_array($page['props']['auth']['user']['theme'] ?? null, ['light', 'system'], true) ? $page['props']['auth']['user']['theme'] : 'dark' }}" style="{{ \App\Support\BrandPalette::style($page['props']['brand'] ?? \App\Support\BrandPalette::for(null)) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Studio PM') }}</title>

    @routes
    @viteReactRefresh
    @vite(['resources/scss/app.scss', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
