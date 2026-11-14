<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
if (!defined('DB_PATH')) {
    define('DB_PATH', getenv('GATEPASS_DB') ?: ROOT . '/data/gatepass.sqlite');
}

$GLOBALS['config'] = require __DIR__ . '/config.php';
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require __DIR__ . '/Database.php';
require __DIR__ . '/Users.php';
require __DIR__ . '/Events.php';
require __DIR__ . '/Orders.php';
require __DIR__ . '/Qr.php';
require __DIR__ . '/Ics.php';
require __DIR__ . '/Covers.php';
require __DIR__ . '/Paystack.php';
require __DIR__ . '/Mailer.php';
require __DIR__ . '/Notify.php';
require __DIR__ . '/helpers.php';

if (PHP_SAPI !== 'cli') {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    // Basic hardening for every page.
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}
