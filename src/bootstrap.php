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
$mongoStaffWeb = getenv('SAA_WEB_MODE') === 'mongo_staff';
session_name($mongoStaffWeb ? 'SAA_MONGO_STAFF' : session_name());
session_start();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Services.php';

if ($mongoStaffWeb) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    return;
}

$pdo = Database::connect();
Database::migrate($pdo);
Database::seed($pdo);
