{{-- Approving an MCP client (Claude) to act as you -- Passport's consent
     screen (AppServiceProvider: Passport::authorizationView), drawn like the
     sign-in screens (Layouts/AuthLayout.jsx, layout/_auth-shell.scss) in your
     Appearance and brand color. --}}
@php
    $theme = in_array($user->theme, ['light', 'system'], true) ? $user->theme : 'dark';
    $studio = \App\Models\StudioProfile::current();
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme }}" style="{{ \App\Support\BrandPalette::style(\App\Support\BrandPalette::for($user->brand_color)) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ \App\Models\StudioProfile::brandName() }} | Connect {{ $client->name }}</title>
    @vite(['resources/scss/app.scss'])
</head>
<body>
<div class="auth-shell">
    <div class="auth-shell__inner">
        <div class="auth-shell__header auth-shell__header--brand">
            <h1 class="auth-shell__brand">
                <img src="{{ $studio->logoDarkUrl() }}" alt="{{ \App\Models\StudioProfile::brandName() }}" class="auth-shell__logo auth-shell__logo--on-dark">
                <img src="{{ $studio->logoUrl() }}" alt="" aria-hidden="true" class="auth-shell__logo auth-shell__logo--on-light">
            </h1>
            <div class="auth-shell__subtitle">Connect an app</div>
        </div>

        <div class="card auth-shell__card">
            <p class="auth-shell__title">Allow {{ $client->name }} to use {{ \App\Models\StudioProfile::brandName() }} as you?</p>
            <p class="auth-shell__text">
                Signed in as <strong>{{ $user->name }}</strong> ({{ $user->email }}). It will be able to see the projects, clients,
                tasks and time you can see, and to create and update tasks, log time and save proposal drafts &mdash; never send,
                accept or delete anything. Only allow apps you trust.
            </p>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn btn--primary btn--lg auth-shell__submit auth-shell__full">Allow</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn btn--secondary btn--lg auth-shell__full">Cancel</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
