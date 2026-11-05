<!DOCTYPE html>
{{-- An error page (404, 403, 419, 500): what happened, and a way on --
     Back, and Home. The installed app (Add to Dock, Home Screen) has no
     address bar, so without these an error is a dead end. Self-contained:
     no database, no build, so it still draws when those are what failed.
     Home is the portal's for a client, My Work (/) for the studio. --}}
@php($home = request()->is('portal', 'portal/*') ? '/portal' : '/')
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style>
        :root {
            --bg: #ffffff;
            --text: #23262e;
            --muted: #595f64;
            --button: #23262e;
            --button-text: #ffffff;
            --border: #d6dae0;
            color-scheme: light dark;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #1b1e23;
                --text: #e6e8eb;
                --muted: #9aa1a9;
                --button: #e6e8eb;
                --button-text: #121418;
                --border: #353a42;
            }
        }
        * { box-sizing: border-box; }
        body {
            display: grid;
            place-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: var(--bg);
        }
        main { max-width: 28rem; text-align: center; }
        .code { margin: 0 0 8px; font-size: 0.875rem; color: var(--muted); letter-spacing: 0.04em; }
        h1 { margin: 0 0 12px; font-size: 1.5rem; line-height: 1.25; }
        p { margin: 0 0 24px; line-height: 1.5; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .button {
            display: inline-block;
            padding: 10px 18px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font: inherit;
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text);
            text-decoration: none;
            background: transparent;
            cursor: pointer;
        }
        .button--primary { color: var(--button-text); background: var(--button); border-color: var(--button); }
    </style>
</head>
<body>
    <main>
        <div class="code">@yield('code')</div>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <button type="button" class="button" onclick="history.length > 1 ? history.back() : location.assign('{{ $home }}')">Go back</button>
            <a href="{{ $home }}" class="button button--primary">{{ $home === '/' ? 'Go to My Work' : 'Go to the portal' }}</a>
        </div>
    </main>
</body>
</html>
