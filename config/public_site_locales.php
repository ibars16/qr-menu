<?php

// Supported languages for the public marketing site (home, and any other
// page under App\Controller\HomeController / PagesController that opts in).
// Deliberately a separate list from config/admin_locales.php — same 6
// languages today by coincidence, not by coupling: the admin panel's UI
// language and the marketing site's language are different concerns and are
// free to diverge later. See src/Service/PublicSiteLocaleResolver.php.
//
// To add a new site language:
//   1. Add an entry below with its ISO 639-1 code, native name and flag.
//   2. Create translations/public_site.{code}.yaml with the translated
//      strings. Copy an existing locale's file as a starting point.

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
