<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#2a338f">

        {{--
            All of these are declared on purpose. Browsers request /favicon.ico
            blind, so it has to be a real file; the PNGs are what modern
            browsers prefer, and offering a 192 lets a high-DPI tab strip or an
            installed shortcut pick a rendition it does not have to enlarge.
        --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48">
        <link rel="icon" href="{{ asset('images/favicon.png') }}" type="image/png" sizes="32x32">
        <link rel="icon" href="{{ asset('images/favicon-192.png') }}" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

        {{--
            The landing-page hero photo is the largest paint above the fold, so
            the browser should fetch it before it has finished parsing CSS —
            otherwise a CSS background discovered late delays first paint. WebP
            is the format every modern browser will pick from the <picture> in
            Home.vue; the JPEG stays as the fallback, not the preload.
        --}}
        <link rel="preload" href="{{ asset('images/cscbg_facade.webp') }}" as="image" fetchpriority="high">

        {{--
            Organisation-level schema, served in the initial HTML (not injected
            by the client-side Inertia head) so a crawler that never executes
            JavaScript still sees it. A <script> in a Vue client template is
            ignored by the compiler, which is why this lives here and not in
            Home.vue.

            The schema.org context key is spelled as a concatenation rather
            than written out. Blade compiles the template *source*, so a
            literal '@context' is matched as the @context directive even inside
            a PHP string — it compiled to a raw `<?php $__contextArgs = [] ...`
            that was emitted into the JSON on every page. Splitting the "@"
            off leaves nothing for the directive pattern to match.
        --}}
        <script type="application/ld+json">
            {{-- The office is config, not a literal: this codebase ships one
                 copy per regional office, and a hard-coded name here tells
                 every search engine that Region V's portal belongs to Region
                 VIII. array_filter drops alternateName when no short name is
                 set, rather than publishing a null. --}}
            {!! json_encode(array_filter([
                '@'.'context' => 'https://schema.org',
                '@type' => 'GovernmentOrganization',
                'name' => config('office.name'),
                'alternateName' => config('office.short_name'),
                'url' => url('/'),
                'logo' => url('/images/csc-logo-512.png'),
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
        </script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-white font-sans text-csc-ink antialiased" @if ($gaId = config('services.ga4.measurement_id')) data-ga-measurement-id="{{ $gaId }}" @endif>
        @inertia
    </body>
</html>
