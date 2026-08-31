@props(['url'])
{{--
    The masthead, built to survive an inbox that blocks images.

    Most mail clients hide remote images until the reader asks for them, so a
    header that is only a logo arrives as an empty box above the greeting. The
    wordmark underneath is therefore real text, not part of the image: with
    pictures off this still reads "CSC TIMS / Training Information Management
    System" in brand blue, which is all the identification the message needs.

    The seal itself is embedded rather than linked — see App\Support\MailBranding
    for why an <img src="https://…"> was the wrong default, and how it came to
    be missing entirely on any host the public internet cannot reach.

    Three bands, in the site header's own order: the GOVPH ribbon, a hairline of
    brand red, then the seal and wordmark on white. They sit inside the message
    card rather than floating above it, so the whole email reads as one document
    instead of a masthead with a separate sheet of paper below it.
--}}
<tr>
<td class="gov-ribbon">
Republic of the Philippines
</td>
</tr>
<tr>
<td class="brand-rule">&nbsp;</td>
</tr>
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ \App\Support\MailBranding::logoSrc() }}" class="logo" width="52" height="49" alt="Civil Service Commission">
<span class="wordmark">{!! $slot !!}</span>
<span class="wordmark-sub">{{ config('office.name') }}</span>
</a>
</td>
</tr>
