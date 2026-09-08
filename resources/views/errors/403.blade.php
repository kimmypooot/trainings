@php
    $code = 403;
    $heading = 'Access denied';
    $message = 'Your account does not have permission to view this page.';
    $tone = 'danger';
@endphp
@include('errors.error-shell')