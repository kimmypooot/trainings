@php
    $code = 429;
    $heading = 'Slow down';
    $message = 'Too many requests in a short time. Wait a moment and try again.';
    $tone = 'warning';
@endphp
@include('errors.error-shell')