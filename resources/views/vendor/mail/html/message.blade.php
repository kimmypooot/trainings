{{--
    The preheader is handed down explicitly. A Blade component has its own
    scope, so view data passed to this markdown view does not reach the layout
    inside it — without this the value sits in $data unused and the inbox
    preview falls back to the greeting.
--}}
@props(['preheader' => null])
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
{{--
    The short wordmark, not config('app.name').

    APP_NAME is "CSC - Training Information Management System", which set
    against the subtitle underneath produced a masthead that said "Training
    Information Management System" twice in two sizes. This is the same pairing
    the site's own logo uses — short mark, then the body that issues the mail.
--}}
<x-mail::header :url="config('app.url')">
CSC TIMS
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{--
    Footer.

    Names the office that actually sent the message rather than the product,
    and says why the message arrived. Both matter more in government
    correspondence than the copyright line that used to stand here alone: a
    participant who cannot tell where a message came from, or why, is a
    participant who reports it as phishing — and a training notice that lands in
    a spam folder costs someone their slot.

    Everything comes from config/office.php, so a deployment corrects its own
    footer without a template edit. Anything unset is simply omitted.
--}}
<x-slot:footer>
<x-mail::footer>
{{--
    Each part of the address block gets its own line.

    The separator is "\" followed by a newline — a CommonMark hard line break.
    Markdown folds a plain newline into a space, which is why the office name
    and its street address arrived run together as one long line. A <br> is not
    an option: Illuminate\Mail\Markdown parses with html_input set to 'escape',
    so the tag would be printed literally. Two trailing spaces are the other
    legal form of a hard break, and are exactly the kind of whitespace an editor
    strips without telling anyone.

    Assembled in PHP rather than with @if between the lines. Written inline, a
    Blade conditional eats the newline it sits on, and with no phone configured
    that swallowed the blank line below — which markdown then read as the same
    paragraph, gluing the address to the sentence that follows it. Building the
    list first keeps the blank line a blank line no matter which parts are set.
--}}
@php
    $addressLines = array_values(array_filter([
        '**'.config('office.name').'**',
        config('office.address'),
        config('office.phone'),
    ]));
@endphp
{!! implode("\\\n", $addressLines) !!}

You are receiving this because you hold an account on the CSC Training Information Management System. This mailbox is not monitored — please write to [{{ config('office.email') }}](mailto:{{ config('office.email') }}) if you need to reach us.

[Visit CSC TIMS]({{ url('/') }}) · [Privacy Policy]({{ url('/privacy-policy') }}) · [Terms of Service]({{ url('/terms-of-service') }})

© {{ date('Y') }} {{ config('office.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
