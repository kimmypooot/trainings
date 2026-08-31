@props(['preheader' => null])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">

{{--
    Poppins in mail, so a notification reads like the app it came from.
    Webfonts are best-effort in email: Apple Mail and iOS honour the @import,
    Gmail's web client ignores it and falls back down the stack in
    themes/default.css. Both are fine. Outlook on Windows is the one client
    that misbehaves — it cannot load the font *and* renders an unknown first
    family as Times New Roman — so the mso block below pins it to Arial
    instead of leaving it with a serif it never asked for.
--}}
<!--[if !mso]><!-->
<style>
@import url('https://fonts.bunny.net/css?family=poppins:400,500,600,700');
</style>
<!--<![endif]-->
<!--[if mso]>
<style>
* { font-family: Arial, Helvetica, sans-serif !important; }
</style>
<![endif]-->

<style>
/*
    Second line of defence for the font.

    The theme stylesheet is inlined and removed before sending, so anything its
    inliner cannot resolve is simply lost — which is how the stack used to
    disappear entirely. The theme now names concrete selectors, and this rule
    catches whatever those miss in the clients that honour a <style> block at
    all. Between the two, no element is left to fall back to the client's
    default serif.
*/
body, table, td, div, p, a, span, h1, h2, h3, li {
font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}

/*
    Desktop gutters are generous because a 570px card on a wide screen can
    afford them. On a phone the card is the screen, and the same 36px each
    side spends a fifth of the line length on empty margin.
*/
.body {
padding: 16px 10px 0 !important;
}

.content-cell {
padding: 28px 22px 30px !important;
}

.header {
padding: 22px 20px 20px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

{{--
    The inbox preview line.

    Every mail client shows a snippet beside the subject, taken from the first
    text it finds in the body. Until now that was "Hello Juan," on every single
    message — the one piece of copy that is identical across all of them, spent
    on the most valuable real estate in the inbox. This puts the actual point of
    the message there instead, so a participant scanning a list can tell a
    certificate release from a payment reminder without opening either.

    ParticipantNotification sets it; anything that does not falls back to
    nothing rendered, which leaves the old behaviour rather than an empty
    preview.
--}}
@if (filled($preheader))
<div class="preheader" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}</div>
{{-- Zero-width spaces stop the client padding the preview out with body copy. --}}
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{!! str_repeat('&#8203;&nbsp;', 60) !!}</div>
@endif

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">

{{--
    Email Body.

    The masthead is a set of rows inside this card, not a band above it. Sat
    outside, the ribbon and seal ran the full width of the client's window while
    the message floated on a narrow sheet below — two unrelated objects sharing a
    background. One card carrying its own letterhead is both the more modern
    shape and the more honest one: it is a single piece of correspondence.
--}}
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
