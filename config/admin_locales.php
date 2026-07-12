<?php

// Supported languages for the Admin Panel UI (sidebar, forms, buttons, flash
// messages, etc). This is intentionally separate from config/languages.php,
// which controls the language(s) of the public-facing menu content and must
// never be affected by this setting.
//
// To add a new admin language:
//   1. Add an entry below with its ISO 639-1 code, native name and flag.
//   2. Create translations/admin_*.{code}.yaml files (one per admin domain)
//      with the translated strings. Copy an existing locale's files as a
//      starting point.
// No code changes are required beyond that.

return [
    'es' => [
        'name' => 'Español',
        'flag' => '🇪🇸',
    ],

    'en' => [
        'name' => 'English',
        'flag' => '🇬🇧',
    ],

    'fr' => [
        'name' => 'Français',
        'flag' => '🇫🇷',
    ],

    'de' => [
        'name' => 'Deutsch',
        'flag' => '🇩🇪',
    ],

    'it' => [
        'name' => 'Italiano',
        'flag' => '🇮🇹',
    ],

    'pt' => [
        'name' => 'Português',
        'flag' => '🇵🇹',
    ],
];
