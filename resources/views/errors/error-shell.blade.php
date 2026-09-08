<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading ?? 'Error' }} - CSC TIMS</title>

    {{--
        Error pages are plain Blade, not the Inertia shell, so they do not get
        app.blade.php's @fonts tags for free — without these a 404 renders in a
        system font while every other page is Poppins. The try/catch is
        deliberate: fonts() throws when the Vite build manifest is missing or
        stale, and an error page is exactly where that is most likely to be
        true. A broken build must not turn a tidy 500 into a blank one.
    --}}
    @php
        try {
            $fontTags = app(Illuminate\Foundation\Vite::class)->fonts();
        } catch (\Throwable) {
            $fontTags = '';
        }
    @endphp
    {!! $fontTags !!}

    @php
        /*
         * The same three semantic tones app.css defines as --color-{name},
         * hand-copied because this page cannot load the Tailwind build (that
         * is the whole reason it is plain CSS). Kept in sync by eye rather
         * than by import — the tokens change rarely enough that this is a
         * smaller risk than giving a page whose job is to survive a broken
         * build a dependency on one.
         */
        $tones = [
            'info' => '#2a338f',
            'warning' => '#b45309',
            'danger' => '#c4111f',
        ];
        $accent = $tones[$tone ?? 'info'];
    @endphp

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, Arial, sans-serif;
            background: linear-gradient(to bottom, #ffffff, #eef0f9);
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 1.5rem;
            color: #374151;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            max-width: 26rem;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(17, 24, 39, .06);
        }
        .bar { height: 6px; background: {{ $accent }}; }
        .body { padding: 2.5rem; text-align: center; }
        img.logo { width: 64px; height: 64px; object-fit: contain; margin: 0 auto; }
        .code { color: {{ $accent }}; font-size: 3.5rem; font-weight: 700; line-height: 1; margin-top: 2rem; }
        h1 { font-size: 1.25rem; margin-top: 1.25rem; }
        p { margin-top: .5rem; font-size: .9rem; line-height: 1.5; color: rgba(55, 65, 81, .72); }
        .actions { margin-top: 1.75rem; display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            font-weight: 600;
            font-size: .95rem;
            text-decoration: none;
            padding: .8rem 1.6rem;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            /* A <button> does not inherit the body font on its own. */
            font-family: inherit;
        }
        .primary { background: #2a338f; color: #fff; }
        .primary:hover { background: #c4111f; }
        .ghost { border: 1px solid rgba(42, 51, 143, .3); color: #2a338f; background: transparent; }
        .ghost:hover { background: #eef0f9; }
    </style>
</head>
<body>
    <div class="card">
        {{-- A quiet severity cue above the fold, never the only one — the
             heading below says the same thing in words. --}}
        <div class="bar" aria-hidden="true"></div>

        <div class="body">
            {{-- The 256px rendition, as everywhere else: this renders small, and
                 the 4499×4269 master would be a quarter-megabyte download on a
                 page whose whole point is to load when something is broken. --}}
            <img class="logo" src="/images/csc-logo-256.png" alt="Civil Service Commission">

            <div class="code">{{ $code }}</div>

            <h1>{{ $heading }}</h1>
            <p>{{ $message }}</p>
            <div class="actions">
                @if (! empty($reload))
                    <button class="btn primary" type="button" onclick="window.location.reload()">Reload page</button>
                @elseif (! empty($actionHref))
                    <a class="btn primary" href="{{ $actionHref }}">{{ $actionLabel ?? 'Continue' }}</a>
                @endif
                {{-- The 404 page's own action *is* home, and rendering this as well
                     put two identical "Go home" buttons side by side. Every other
                     error page sets no action at all, so this is their only way
                     out and it has to stay. --}}
                @if (empty($actionHref) || $actionHref !== '/')
                    <a class="btn ghost" href="/">Go home</a>
                @endif
            </div>
        </div>
    </div>
</body>
</html>