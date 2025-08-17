<?php

return [
    'api_url'          => env('CHURCHTOOLS_API_URL', ''), // z.B. https://demo.church.tools
    'api_token'        => env('CHURCHTOOLS_API_TOKEN', ''), // Bearer-Token / API-Key
    'upload_endpoint'  => env('CHURCHTOOLS_UPLOAD_ENDPOINT', '/api/media'), // relativer Pfad ohne trailing slash
    'timeout'          => (int) env('CHURCHTOOLS_TIMEOUT', 30),
];