<?php
/*
 | Private settings. Copy this file to config.local.php and fill it in.
 | config.local.php is in .gitignore, so your keys and passwords never reach GitHub.
 */
return [
    'app_url' => 'https://gatepass.ejikepius.dev',

    // Paystack dashboard → Settings → API Keys & Webhooks.
    // Start with the test keys; switch to live keys when you're ready for real money.
    // Webhook URL to paste in Paystack: https://gatepass.ejikepius.dev/paystack/webhook
    'paystack' => [
        'public_key' => 'PASTE_YOUR_PUBLIC_KEY_HERE',
        'secret_key' => 'PASTE_YOUR_SECRET_KEY_HERE',
    ],

    // Only if your host has no cron jobs: a long random string. Then call
    // https://gatepass.ejikepius.dev/cron/<token> every 15 minutes from a free service like cron-job.org.
    'cron_token' => '',

    // DirectAdmin: E-mail Manager → create tickets@ejikepius.dev; its settings page
    // shows the exact SMTP host and port to use.
    'mail' => [
        'driver'     => 'smtp',
        'host'       => 'mail.ejikepius.dev',
        'port'       => 465,
        'encryption' => 'ssl',   // ssl for port 465, tls for port 587
        'username'   => 'tickets@ejikepius.dev',
        'password'   => 'the-mailbox-password',
    ],
];
