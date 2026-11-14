<?php
declare(strict_types=1);

// Fills a local database with demo organizers, events and sales, for development
// and screenshots. Never run this on the live site.
//   php bin/seed.php

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connection();
if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    exit("The database already has data. Delete data/gatepass.sqlite first if you want a fresh demo.\n");
}

$GLOBALS['config']['mail']['driver'] = 'log';
$local = fn (string $rel, string $tz = 'Africa/Lagos') => (new DateTimeImmutable($rel, new DateTimeZone($tz)))->format('Y-m-d\TH:i');

$pius = users()->create('ejike_pius', 'pius@example.com', 'password123', 'Ejike Pius');
users()->updateProfile($pius, 'Ejike Pius', 'Full-stack developer in Lagos. Building one product a day in public: The Daily Ship.');
$tade = users()->create('techhub_lagos', 'hub@example.com', 'password123', 'TechHub Lagos');
$ama = users()->create('amaka_events', 'amaka@example.com', 'password123', 'Amaka Live');

$make = function (int $user, array $e) {
    [$data, $errors] = Events::validate($e + ['status' => 'published', 'timezone' => 'Africa/Lagos', 'online_url' => '', 'description' => ''],
        config('timezones'), 6);
    if ($errors) {
        throw new RuntimeException(json_encode($errors));
    }
    return events()->create($user, $data);
};

$e1 = $make($pius, [
    'title' => 'Lagos Builders Meetup: Ship Something in a Day',
    'description' => "A relaxed evening for developers, designers and founders who build in public.\n\nShort demos from people shipping side projects, a live build on stage, and plenty of time to meet people.\n\nBring your laptop if you want to demo. Small chops and drinks are on us.",
    'venue' => 'The Zone Tech Park, Gbagada, Lagos',
    'start_local' => $local('+9 days 16:00'), 'end_local' => $local('+9 days 20:00'),
    'type_name' => ['Regular', 'VIP (front row + dinner)', 'Student'], 'type_price' => ['5000', '15000', '0'], 'type_qty' => ['150', '20', '40'],
]);
$e2 = $make($tade, [
    'title' => 'Intro to Laravel Workshop',
    'description' => 'A hands-on afternoon: build and deploy a small Laravel app from scratch.',
    'venue' => '', 'online_url' => 'https://meet.google.com/abc-defg-hij',
    'start_local' => $local('+3 days 14:00'), 'end_local' => $local('+3 days 17:00'),
    'type_name' => ['Online seat'], 'type_price' => ['0'], 'type_qty' => ['300'],
]);
$e3 = $make($ama, [
    'title' => 'Afrobeats Night at the Rooftop',
    'description' => 'DJs, live percussion and the best view of the island.',
    'venue' => 'Rooftop Lounge, Victoria Island, Lagos',
    'start_local' => $local('+16 days 21:00'), 'end_local' => $local('+17 days 03:00'),
    'type_name' => ['Early bird', 'Regular', 'Table for 6'], 'type_price' => ['8000', '12000', '150000'], 'type_qty' => ['50', '200', '10'],
]);
$e4 = $make($tade, [
    'title' => 'Women in Tech Breakfast',
    'description' => 'Breakfast and honest conversations about building a career in tech.',
    'venue' => 'Yaba, Lagos',
    'start_local' => $local('+1 days 09:00'), 'end_local' => $local('+1 days 11:30'),
    'type_name' => ['Seat'], 'type_price' => ['3000'], 'type_qty' => ['12'],
]);

// Sales over the last few weeks, so the dashboard chart has something to show.
$names = ['Chioma Okafor', 'Tunde Bakare', 'Aisha Bello', 'Emeka Nwosu', 'Funke Adeyemi', 'Ibrahim Musa', 'Ngozi Eze', 'Seyi Ojo',
          'Kemi Lawal', 'Uche Obi', 'Bola Ahmed', 'Zainab Yusuf', 'David Okon', 'Grace Akpan', 'Femi Adebayo', 'Halima Sani'];
$sell = function (int $eventId, int $orders, int $days) use ($names, $db) {
    $event = events()->find($eventId);
    $types = events()->types($eventId);
    for ($i = 0; $i < $orders; $i++) {
        $t = $types[array_rand($types)];
        $name = $names[array_rand($names)];
        $email = strtolower(str_replace(' ', '.', $name)) . $i . '@example.com';
        try {
            $o = orders()->reserve($event, $name, $email, [$t['id'] => random_int(1, 3)], 10);
            orders()->markPaid($o['reference'], (int) $o['total'], 'demo');
            $ago = random_int(0, $days * 24 * 60);
            $db->prepare("UPDATE orders SET paid_at = datetime('now', ?), created_at = datetime('now', ?) WHERE reference = ?")
               ->execute(["-$ago minutes", "-$ago minutes", $o['reference']]);
        } catch (DomainException) {
            // sold out: fine
        }
    }
};
$sell($e1, 46, 26);
$sell($e2, 30, 10);
$sell($e3, 25, 20);
$sell($e4, 6, 5);

// A few people already checked in to the first event, for the guest list.
$codes = $db->query("SELECT t.code FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.event_id = $e1 LIMIT 9")->fetchAll(PDO::FETCH_COLUMN);
foreach ($codes as $c) {
    orders()->checkIn($e1, $c);
}

echo "Demo data ready. Log in as ejike_pius / password123\n";
