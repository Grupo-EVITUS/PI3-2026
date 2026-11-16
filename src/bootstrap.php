<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => strtolower(getenv('SESSION_COOKIE_SECURE') ?: 'false') === 'true',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_name('SAA_SESSION');
session_start();

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Services.php';
