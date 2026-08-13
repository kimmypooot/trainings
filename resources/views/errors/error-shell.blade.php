<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading ?? 'Error' }} - CSC TIMS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, Arial, sans-serif;
            background: #eef0f9;
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
            padding: 2.5rem;
            text-align: center;
        }
        img.logo { width: 64px; height: 64px; object-fit: contain; margin: 0 auto; }
        .code { color: #2a338f; font-size: 3.5rem; font-weight: 700; line-height: 1; margin-top: 1.25rem; }
        h1 { font-size: 1.25rem; margin-top: 1rem; }
        p { margin-top: .5rem; font-size: .9rem; line-height: 1.5; color: rgba(55, 65, 81, .72); }
        .actions { margin-top: 1.75rem; display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            font-weight: 600;
            font-size: .95rem;
            text-decoration: none;
            padding: .8rem 1.6rem;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
        }
        .primary { background: #2a338f; color: #fff; }
        .primary:hover { background: #c4111f; }
        .ghost { border: 1px solid rgba(42, 51, 143, .3); color: #2a338f; background: transparent; }
        .ghost:hover { background: #eef0f9; }
    </style>
</head>
<body>
    <div class="card">
        <img class="logo" src="/images/csc-logo.png" alt="Civil Service Commission">
        <div class="code">{{ $code }}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            @if (! empty($reload))
                <button class="btn primary" type="button" onclick="window.location.reload()">Reload page</button>
            @elseif (! empty($actionHref))
                <a class="btn primary" href="{{ $actionHref }}">{{ $actionLabel ?? 'Continue' }}</a>
            @endif
            <a class="btn ghost" href="/">Go home</a>
        </div>
    </div>
</body>
</html>