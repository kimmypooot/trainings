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

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-white font-sans text-csc-ink antialiased">
        @inertia
    </body>
</html>
