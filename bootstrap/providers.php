<?php

use App\Providers\AppServiceProvider;
use App\Providers\OfficeSettingsProvider;

return [
    AppServiceProvider::class,
    // After AppServiceProvider, so the office's saved identity is the last
    // word on config('office.*') — see OfficeSettingsProvider.
    OfficeSettingsProvider::class,
];
