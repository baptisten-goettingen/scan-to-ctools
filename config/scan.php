<?php
return [
    'in_dir'      => env('SCAN_IN', '/srv/scans/incoming'),
    'allowed_ext' => array_filter(array_map('trim', explode(',', env('ALLOWED_EXT', '')))),
    'min_stable'  => (int) env('MIN_STABLE_SEC', 3),
];