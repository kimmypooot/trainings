@php
    $code = 404;
    $heading = 'Page not found';
    $message = 'We could not find that page. It may have moved or never existed.';
    $actionHref = '/';
    $actionLabel = 'Go home';
@endphp
@include('errors.error-shell')