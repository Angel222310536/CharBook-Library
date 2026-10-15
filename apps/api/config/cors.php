<?php

/**
 * CORS — CharBook Library
 * Allowed origins se controla con FRONTEND_URL en .env.
 * Vercel previews se soportan vía el regex pattern.
 */

return [

    'paths' => ['api/*', 'auth/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        env('FRONTEND_URL'),               // ej: https://charbook.app
    ]),

    'allowed_origins_patterns' => [
        // Previews de Vercel: https://charbook-xxx.vercel.app
        '#^https?://.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    'supports_credentials' => true,
];
