<?php

// Maps an ISO 3166-1 alpha-2 region code (the region half of a browser's
// Accept-Language tag, e.g. the "AR" in "es-AR") to the ISO 4217 currency
// code most customers in that region expect to see prices in.
//
// Used only to *guess* a sensible default currency for a first-time visitor
// to the public menu — never authoritative. If a region isn't listed here,
// or the currency it maps to isn't enabled in config/currencies.php, the
// menu falls back to the restaurant's own currency.
//
// To support guessing for a new currency, add the relevant region(s) here
// once that currency exists in config/currencies.php. No code changes needed.

return [
    // NZD
    'NZ' => 'NZD',

    // AUD
    'AU' => 'AUD',

    // USD
    'US' => 'USD',
    'PR' => 'USD',
    'EC' => 'USD',
    'SV' => 'USD',
    'PA' => 'USD',

    // CAD
    'CA' => 'CAD',

    // EUR (eurozone)
    'AD' => 'EUR', 'AT' => 'EUR', 'BE' => 'EUR', 'CY' => 'EUR', 'DE' => 'EUR',
    'EE' => 'EUR', 'ES' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR', 'GR' => 'EUR',
    'HR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LT' => 'EUR', 'LU' => 'EUR',
    'LV' => 'EUR', 'MC' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR',
    'SI' => 'EUR', 'SK' => 'EUR', 'SM' => 'EUR', 'VA' => 'EUR',

    // GBP
    'GB' => 'GBP',

    // JPY
    'JP' => 'JPY',

    // CNY
    'CN' => 'CNY',

    // KRW
    'KR' => 'KRW',

    // THB
    'TH' => 'THB',

    // SGD
    'SG' => 'SGD',

    // HKD
    'HK' => 'HKD',

    // CHF
    'CH' => 'CHF',
    'LI' => 'CHF',

    // SEK
    'SE' => 'SEK',

    // NOK
    'NO' => 'NOK',

    // DKK
    'DK' => 'DKK',

    // INR
    'IN' => 'INR',

    // MXN
    'MX' => 'MXN',

    // BRL
    'BR' => 'BRL',

    // AED
    'AE' => 'AED',
];
