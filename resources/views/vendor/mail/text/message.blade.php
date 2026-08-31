<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{--
    Footer.

    Kept in step with the HTML version deliberately. The plain-text part is what
    a screen reader in text mode, a locked-down government mail client, or a
    spam filter comparing the two parts actually reads — a text alternative that
    says materially less than the HTML is both worse for those readers and a
    small deliverability signal against us.
--}}
<x-slot:footer>
<x-mail::footer>
{{ config('office.name') }}
@if (config('office.address')){{ config('office.address') }}@endif
@if (config('office.phone')){{ config('office.phone') }}@endif

You are receiving this because you hold an account on the CSC Training Information Management System. Replies to this address are not monitored — contact the office at {{ url('/#contact') }} if you need to reach us.

{{ url('/') }}

© {{ date('Y') }} {{ config('office.name') }}. @lang('All rights reserved.')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
