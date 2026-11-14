<?php
declare(strict_types=1);

// Run by cron every 15 minutes, e.g. in DirectAdmin → Cron Jobs:
//   */15 * * * *  /usr/local/bin/php /home/USER/domains/gatepass.ejikepius.dev/bin/remind.php
//
// 1. Releases tickets held by checkouts that were never paid.
// 2. Emails each buyer their tickets again about a day before the event.

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

echo json_encode(Notify::runReminders()), "\n";
