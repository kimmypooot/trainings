<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | This file exists for one key: the path below. Inertia's shipped default
    | is resource_path('js/pages'), lowercase, and this app's pages have always
    | lived in resources/js/Pages — the capitalised convention, and the one
    | resources/js/app.js resolves against (`./Pages/${name}.vue`).
    |
    | On Windows, where this repo is developed, those two spellings are the
    | same directory, so nothing ever complained. On a case-sensitive
    | filesystem — the ubuntu runner CI uses — 'js/pages' does not exist, the
    | view finder resolves nothing, and every assertInertia()->component(...)
    | fails with "Inertia page component file [Home] does not exist". 68 tests
    | did, for pages that are plainly there. The same lookup guards
    | ResponseFactory::render(), so this is not only a testing concern.
    |
    | The whole `pages` block is restated rather than just the path, because
    | mergeConfigFrom merges only the top level: declaring 'pages' replaces the
    | package's entire array, and a lone 'paths' key would silently drop
    | 'extensions' and leave the finder with nothing to match.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
