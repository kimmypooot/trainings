@php
    $code = 500;
    $heading = 'Something went wrong';
    $message = 'Our server hit an unexpected error. Please try again in a moment.';
    $tone = 'danger';
@endphp
@include('errors.error-shell')