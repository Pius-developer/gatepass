<?php
declare(strict_types=1);

/*
 | Gatepass tests. Run:  php tests/run.php
 | Uses a temporary database, so nothing real is touched. No packages needed.
 */
define('DB_PATH', sys_get_temp_dir() . '/gatepass-test-' . getmypid() . '.sqlite');
require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['config']['mail']['driver'] = 'log';

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "  ✓ $name\n"; }
    catch (Throwable $e) { $failed++; echo "  ✗ $name\n    " . $e->getMessage() . "\n"; }
}
function eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
}
function throws(callable $fn, string $contains = ''): void
{
    try { $fn(); } catch (DomainException $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) throw new Exception('wrong message: ' . $e->getMessage());
        return;
    }
    throw new Exception('expected an error');
}

$tz = config('timezones');
$future = fn (string $rel) => (new DateTimeImmutable($rel, new DateTimeZone('Africa/Lagos')))->format('Y-m-d\TH:i');
function form(array $over = []): array
{
    global $future;
    return $over + [
        'title' => 'Test Night', 'description' => '', 'venue' => 'Yaba, Lagos', 'online_url' => '', 'timezone' => 'Africa/Lagos',
        'start_local' => $future('+5 days 18:00'), 'end_local' => $future('+5 days 21:00'), 'status' => 'published',
        'type_id' => [0, 0], 'type_name' => ['Regular', 'VIP'], 'type_price' => ['5,000', '20000'], 'type_qty' => ['3', '1'],
    ];
}

echo "\nQR codes\n";
test('Reed–Solomon matches the worked example in the QR spec', function () {
    // ISO 18004 Annex I: "01234567" version 1-M → these 10 EC codewords
    eq(Qr::reedSolomon([16, 32, 12, 86, 97, 128, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17], 10),
       [165, 36, 212, 193, 237, 54, 199, 135, 44, 85]);
});
test('a ticket code fits the smallest symbol (21×21)', fn () => eq(count(Qr::matrix('K7Q2-M9XD-4TPA')), 21));
test('long text picks a bigger version', fn () => eq(count(Qr::matrix(str_repeat('a', 100))), 17 + 4 * 6));
test('finder patterns sit in three corners', function () {
    $m = Qr::matrix('HELLO');
    $n = count($m);
    foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
        eq($m[$y][$x] && $m[$y + 6][$x + 6] && $m[$y + 3][$x + 3] && !$m[$y + 1][$x + 1], true);
    }
});
test('PNG output is a real PNG', fn () => eq(substr(Qr::png('X'), 0, 8), "\x89PNG\r\n\x1a\n"));

echo "\nEvent form validation\n";
test('a valid form passes and stores kobo + UTC', function () use ($tz) {
    [$d, $errors] = Events::validate(form(), $tz, 6);
    eq($errors, []);
    eq($d['types'][0]['price'], 500000);
    eq(substr($d['starts_at'], 11), '17:00:00', 'Lagos 18:00 is 17:00 UTC');
});
test('end before start is rejected', function () use ($tz, $future) {
    eq(isset(Events::validate(form(['end_local' => $future('+5 days 17:00')]), $tz, 6)[1]['end_local']), true);
});
test('a past start is rejected for new events', function () use ($tz, $future) {
    eq(isset(Events::validate(form(['start_local' => $future('-1 day 10:00')]), $tz, 6)[1]['start_local']), true);
});
test('needs a venue or an online link', function () use ($tz) {
    eq(isset(Events::validate(form(['venue' => '']), $tz, 6)[1]['venue']), true);
    eq(Events::validate(form(['venue' => '', 'online_url' => 'https://meet.example.com/x']), $tz, 6)[1], []);
});
test('online links must be http(s)', function () use ($tz) {
    eq(isset(Events::validate(form(['online_url' => 'javascript:alert(1)']), $tz, 6)[1]['online_url']), true);
});
test('paid tickets under ₦100 are rejected, free is fine', function () use ($tz) {
    eq(isset(Events::validate(form(['type_price' => ['50', '0']]), $tz, 6)[1]['types']), true);
    eq(Events::validate(form(['type_price' => ['0', '0']]), $tz, 6)[1], []);
});
test('an impossible date (31 Feb) is rejected', function () use ($tz) {
    eq(Events::toUtc('2027-02-31T10:00', 'Africa/Lagos'), null);
});

echo "\nSelling tickets\n";
$host = users()->create('host1', 'host1@example.com', 'password123', 'Host One');
[$data] = Events::validate(form(), $tz, 6);
$eventId = events()->create($host, $data);
$event = events()->find($eventId);
[$regular, $vip] = events()->types($eventId);

test('an event gets a readable unique link', fn () => eq((bool) preg_match('/^test-night-[a-z0-9]{4}$/', $event['slug']), true));
test('reserving holds tickets and totals the price', function () use ($event, $regular, $vip) {
    $o = orders()->reserve($event, 'Ada', 'ada@example.com', [$regular['id'] => 2, $vip['id'] => 1], 10);
    eq($o['status'], 'pending');
    eq((int) $o['total'], 2 * 500000 + 2000000);
    eq(events()->types((int) $event['id'])[0]['left'], 1);
});
test('can\'t buy more than are left (held tickets count)', function () use ($event, $regular, $vip) {
    throws(fn () => orders()->reserve($event, 'Bo', 'bo@example.com', [$regular['id'] => 2], 10), 'Only 1');
    throws(fn () => orders()->reserve($event, 'Bo', 'bo@example.com', [$vip['id'] => 1], 10), 'sold out');
});
test('per-order limit and empty orders are refused', function () use ($event, $regular) {
    throws(fn () => orders()->reserve($event, 'Bo', 'bo@example.com', [$regular['id'] => 1], 0), 'up to 0');
    throws(fn () => orders()->reserve($event, 'Bo', 'bo@example.com', [], 10), 'Choose');
});
test('a ticket type from another event is refused', function () use ($event) {
    throws(fn () => orders()->reserve($event, 'Bo', 'bo@example.com', [999 => 1], 10), 'no longer available');
});
test('expired holds release their tickets', function () use ($event, $regular) {
    Database::connection()->exec("UPDATE orders SET expires_at = datetime('now', '-1 minute') WHERE status = 'pending'");
    eq(orders()->expireStale(), 1);
    eq(events()->types((int) $event['id'])[0]['left'], 3);
});

$order = orders()->reserve($event, 'Chi', 'chi@example.com', [$regular['id'] => 2], 10);
test('a payment for the wrong amount is refused', function () use ($order) {
    eq(orders()->markPaid($order['reference'], 1000, 'paystack'), null);
    eq(orders()->findByReference($order['reference'])['status'], 'pending');
});
test('a correct payment issues one ticket per seat', function () use ($order) {
    $paid = orders()->markPaid($order['reference'], (int) $order['total'], 'paystack');
    eq($paid['status'], 'paid');
    eq($paid['just_paid'], true);
    $tickets = orders()->tickets((int) $order['id']);
    eq(count($tickets), 2);
    eq((bool) preg_match('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $tickets[0]['code']), true);
});
test('paying twice (callback + webhook) changes nothing', function () use ($order) {
    $again = orders()->markPaid($order['reference'], (int) $order['total'], 'paystack');
    eq($again['just_paid'], false);
    eq(count(orders()->tickets((int) $order['id'])), 2);
});
test('the buyer link needs the secret key', function () use ($order) {
    eq(orders()->findForBuyer($order['reference'], str_repeat('0', 32)), null);
    eq(orders()->findForBuyer($order['reference'], $order['access_key'])['id'], $order['id']);
});
test('free orders are paid straight away', function () use ($tz, $host) {
    [$d] = Events::validate(form(['type_name' => ['Free'], 'type_price' => ['0'], 'type_qty' => ['10'], 'type_id' => [0]]), $tz, 6);
    $e = events()->find(events()->create($host, $d));
    $o = orders()->reserve($e, 'Dee', 'dee@example.com', [events()->types((int) $e['id'])[0]['id'] => 2], 10);
    eq($o['status'], 'paid');
    eq($o['provider'], 'free');
});
test('drafts and cancelled events don\'t sell', function () use ($event, $regular) {
    throws(fn () => orders()->reserve(['status' => 'draft'] + $event, 'E', 'e@example.com', [$regular['id'] => 1], 10), 'isn\'t selling');
    throws(fn () => orders()->reserve(['status' => 'cancelled'] + $event, 'E', 'e@example.com', [$regular['id'] => 1], 10), 'isn\'t selling');
});
test('can\'t lower a ticket type below what\'s sold', function () use ($tz, $event, $regular, $vip) {
    $in = form(['type_id' => [$regular['id'], $vip['id']], 'type_qty' => ['1', '1']]);
    eq(isset(Events::validate($in, $tz, 6, events()->types((int) $event['id']))[1]['types']), true);
});
test('removing a type that sold tickets archives it instead of deleting', function () use ($tz, $event, $regular) {
    [$d, $err] = Events::validate(form(['type_id' => [0], 'type_name' => ['New'], 'type_price' => ['0'], 'type_qty' => ['5']]), $tz, 6, events()->types((int) $event['id']));
    eq($err, []);
    events()->update((int) $event['id'], $d);
    $all = events()->types((int) $event['id'], true);
    // Both were in an order (VIP in one that expired), so both are kept, archived.
    eq(array_column(array_filter($all, fn ($t) => $t['archived']), 'name'), ['Regular', 'VIP']);
    eq(array_column(events()->types((int) $event['id']), 'name'), ['New']);
});
test('events with sales can\'t be deleted', fn () => eq(events()->delete((int) $event['id']), false));

echo "\nCheck-in at the door\n";
$code = orders()->tickets((int) $order['id'])[0]['code'];
test('first scan lets them in', fn () => eq(orders()->checkIn((int) $event['id'], $code)['result'], 'ok'));
test('second scan is caught', fn () => eq(orders()->checkIn((int) $event['id'], $code)['result'], 'already'));
test('codes work typed loosely (lowercase, no dashes)', function () use ($order, $event) {
    $c2 = orders()->tickets((int) $order['id'])[1]['code'];
    eq(orders()->checkIn((int) $event['id'], strtolower(str_replace('-', ' ', $c2)))['result'], 'ok');
});
test('a ticket for another event is refused', fn () => eq(orders()->checkIn((int) $event['id'] + 1, $code)['result'], 'wrong_event'));
test('made-up codes are refused', fn () => eq(orders()->checkIn((int) $event['id'], 'AAAA-BBBB-CCCC')['result'], 'not_found'));
test('stats count the check-ins', fn () => eq(orders()->eventStats((int) $event['id'])['checked_in'], 2));

echo "\nCalendar, payments, emails\n";
test('.ics file has the event, both reminders and folded lines', function () use ($event) {
    $ics = Ics::event(events()->findWithHostById((int) $event['id']), 'https://x.test/e/1', 'x.test', str_repeat('long note ', 20));
    eq(str_contains($ics, 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($event['starts_at'] . ' UTC'))), true);
    eq(substr_count($ics, 'BEGIN:VALARM'), 2);
    foreach (explode("\r\n", $ics) as $line) {
        if (strlen($line) > 75) throw new Exception('unfolded line: ' . $line);
    }
});
test('Paystack webhook signature is checked', function () {
    $p = new Paystack('sk_test_abc');
    $body = '{"event":"charge.success"}';
    eq($p->webhookIsGenuine($body, hash_hmac('sha512', $body, 'sk_test_abc')), true);
    eq($p->webhookIsGenuine($body, hash_hmac('sha512', $body, 'wrong')), false);
    eq($p->webhookIsGenuine($body, ''), false);
});
test('ticket email has a QR per ticket and the calendar file', function () use ($order) {
    @unlink(ROOT . '/data/mail.log');
    $GLOBALS['config']['app_url'] = 'https://gatepass.test';
    eq(Notify::tickets(orders()->findByReference($order['reference'])), true);
    $log = file_get_contents(ROOT . '/data/mail.log');
    eq(str_contains($log, 'inline qr0, inline qr1, event.ics'), true);
    eq(str_contains($log, $order['access_key']), true, 'contains the private ticket link');
});
test('money formatting', function () {
    eq(money(500000), '₦5,000');
    eq(money(0), 'Free');
    eq(money(12345), '₦123.45');
});

@unlink(DB_PATH);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
