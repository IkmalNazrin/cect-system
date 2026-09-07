<?php
// config/google_config.php

require_once __DIR__ . '/load_env.php';

define('GOOGLE_CLIENT_ID', cect_env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', cect_env('GOOGLE_CLIENT_SECRET', ''));
define('GOOGLE_REDIRECT_URI', cect_env('GOOGLE_REDIRECT_URI', ''));

// Include Google Client Library via Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';
?>
