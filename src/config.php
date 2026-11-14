<?php
declare(strict_types=1);

/*
 | Gatepass settings. Passwords and secret keys go in config.local.php (not on GitHub).
 */
$config = [
    'app_name'   => 'Gatepass',
    // Public URL with no trailing slash. null = detect from the request.
    'app_url'    => getenv('GATEPASS_URL') ?: null,

    'creator'    => 'Ejike Pius',
    'creator_x'  => 'ejike_pius',
    'github_url' => 'https://github.com/pius-developer/gatepass',

    // Money is stored in the smallest unit (kobo), so ₦5,000 is 500000.
    'currency'        => 'NGN',
    'currency_symbol' => '₦',

    // Paystack. Leave both keys empty to use the built-in demo checkout
    // (no real money; handy for local development and for showing the app off).
    // Test keys (sk_test_..., pk_test_...) give the real Paystack page with test cards.
    'paystack' => [
        'public_key' => '',
        'secret_key' => '',
    ],

    'hold_minutes'     => 15,   // unpaid tickets are held for this long, then released
    'max_per_order'    => 10,   // most tickets one person can buy in one order
    'max_events'       => 30,   // per organizer
    'max_ticket_types' => 6,    // per event
    'remind_hours'     => 24,   // reminder email goes out this long before the event

    'timezones' => [
        'Africa/Lagos'        => 'Lagos, Abuja (WAT)',
        'Africa/Accra'        => 'Accra (GMT)',
        'Africa/Nairobi'      => 'Nairobi (EAT)',
        'Africa/Johannesburg' => 'Johannesburg (SAST)',
        'Europe/London'       => 'London',
        'Europe/Berlin'       => 'Berlin, Paris',
        'America/New_York'    => 'New York',
        'America/Chicago'     => 'Chicago',
        'America/Los_Angeles' => 'Los Angeles',
        'Asia/Dubai'          => 'Dubai',
        'UTC'                 => 'UTC',
    ],

    // Secret for the web cron URL (/cron/<token>), for hosts without a real cron job.
    // Leave empty to turn that URL off. Set a long random value in config.local.php.
    'cron_token' => '',

    'reserved_usernames' => ['admin', 'gatepass', 'login', 'logout', 'signup', 'dashboard', 'settings', 'api',
                             'cron', 'assets', 'uploads', 'events', 'explore', 'help', 'about', 'tickets', 'pay'],

    'mail' => [
        'driver'     => 'log',          // log | mail | smtp
        'from_email' => 'tickets@ejikepius.dev',
        'from_name'  => 'Gatepass',
        'reply_to'   => 'tickets@ejikepius.dev',
        'host'       => '',
        'port'       => 465,
        'encryption' => 'ssl',
        'username'   => '',
        'password'   => '',
    ],
];

$local = dirname(__DIR__) . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
