<?php
declare(strict_types=1);

define('WEB_ROOT', __DIR__);
require __DIR__ . '/../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

// Local development: let the built-in server send real files (CSS, JS, images...).
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

/* ===========================================================================
 | Home and Explore
 * ========================================================================= */

if ($method === 'GET' && $path === '/') {
    render('home', ['events' => events()->upcoming('', '', '', 6)]);
}

if ($method === 'GET' && $path === '/explore') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $when = (string) ($_GET['when'] ?? '');
    $price = (string) ($_GET['price'] ?? '');
    render('explore', ['events' => events()->upcoming($q, $when, $price, 48), 'q' => $q, 'when' => $when, 'price' => $price]);
}

// JSON for the live search on Explore (app.js).
if ($method === 'GET' && $path === '/api/events') {
    $list = events()->upcoming(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
                               (string) ($_GET['when'] ?? ''), (string) ($_GET['price'] ?? ''), 48);
    json(['events' => array_map('event_card_json', $list)]);
}

/* ===========================================================================
 | Sign up, log in, log out
 * ========================================================================= */

if ($path === '/signup') {
    if (current_user()) redirect('/dashboard');
    $old = ['name' => '', 'username' => '', 'email' => ''];
    $errors = [];

    if ($method === 'POST') {
        verify_csrf();
        $old = [
            'name'     => trim((string) ($_POST['name'] ?? '')),
            'username' => strtolower(ltrim(trim((string) ($_POST['username'] ?? '')), '@')),
            'email'    => strtolower(trim((string) ($_POST['email'] ?? ''))),
        ];
        $password = (string) ($_POST['password'] ?? '');

        if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 60) {
            $errors['name'] = 'Your name or your brand\'s name (2–60 characters).';
        }
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $old['username'])) {
            $errors['username'] = 'Use 3–20 letters, numbers or underscores.';
        } elseif (in_array($old['username'], config('reserved_usernames'), true)) {
            $errors['username'] = 'That name is reserved. Try another.';
        } elseif (users()->exists('username', $old['username'])) {
            $errors['username'] = 'That name is taken.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (users()->exists('email', $old['email'])) {
            $errors['email'] = 'An account with that email already exists.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        }
        if (!$errors && !rate_ok('signup|' . client_ip(), 5, 3600)) {
            $errors['username'] = 'Too many sign-ups from your network. Try again in an hour.';
        }
        if (!$errors) {
            $id = users()->create($old['username'], $old['email'], $password, $old['name']);
            login_user($id);
            flash('Welcome to Gatepass! Create your first event.');
            redirect('/events/new');
        }
    }
    render('signup', compact('old', 'errors'), $errors ? 422 : 200);
}

if ($path === '/login') {
    if (current_user()) redirect('/dashboard');
    $error = null;
    $login = '';
    if ($method === 'POST') {
        verify_csrf();
        $login = trim((string) ($_POST['login'] ?? ''));
        if (!rate_ok('login|' . client_ip(), 10, 900)) {
            $error = 'Too many attempts. Wait 15 minutes and try again.';
        } else {
            $user = users()->findByLogin($login);
            if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                login_user((int) $user['id']);
                $next = $_SESSION['after_login'] ?? '/dashboard';
                unset($_SESSION['after_login']);
                redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/dashboard');
            }
            $error = 'Wrong username/email or password.';
        }
    }
    render('login', compact('error', 'login'), $error ? 422 : 200);
}

if ($method === 'POST' && $path === '/logout') {
    verify_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('/');
}

/* ===========================================================================
 | Organizer dashboard
 * ========================================================================= */

if ($method === 'GET' && $path === '/dashboard') {
    $me = require_login();
    $list = events()->forUser((int) $me['id']);
    $sales = orders()->dailySales((int) $me['id'], 30);
    $totals = [
        'revenue'  => array_sum(array_column($list, 'revenue')),
        'sold'     => array_sum(array_column($list, 'sold')),
        'upcoming' => count(array_filter($list, fn ($e) => in_array(event_phase($e), ['upcoming', 'live'], true))),
        'last30'   => array_sum(array_column($sales, 'tickets')),
    ];
    render('dashboard', ['list' => $list, 'sales' => $sales, 'totals' => $totals,
                         'recent' => orders()->recentForUser((int) $me['id'])]);
}

if ($path === '/settings') {
    $me = require_login();
    $errors = [];
    $old = $me;
    if ($method === 'POST') {
        verify_csrf();
        $old['name'] = trim((string) ($_POST['name'] ?? ''));
        $old['bio'] = trim((string) ($_POST['bio'] ?? ''));
        if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 60) {
            $errors['name'] = 'Use 2–60 characters.';
        }
        if (mb_strlen($old['bio']) > 280) {
            $errors['bio'] = 'Keep it under 280 characters.';
        }
        if (!$errors) {
            users()->updateProfile((int) $me['id'], $old['name'], $old['bio']);
            flash('Profile saved.');
            redirect('/settings');
        }
    }
    render('settings', compact('old', 'errors'), $errors ? 422 : 200);
}

/* ===========================================================================
 | Creating and managing events (owner only)
 * ========================================================================= */

if ($path === '/events/new') {
    $me = require_login();
    $errors = [];
    $old = ['title' => '', 'description' => '', 'venue' => '', 'online_url' => '', 'timezone' => 'Africa/Lagos',
            'start_local' => '', 'end_local' => '', 'status' => 'published',
            'types' => [['id' => 0, 'name' => 'Regular', 'price' => 0, 'quantity' => 100]]];

    if ($method === 'POST') {
        verify_csrf();
        [$old, $errors] = Events::validate($_POST, config('timezones'), (int) config('max_ticket_types'));
        if (!$errors && events()->countForUser((int) $me['id']) >= config('max_events')) {
            $errors['title'] = 'You\'ve reached ' . config('max_events') . ' events. Delete an old one first.';
        }
        $cover = null;
        if (!$errors && !empty($_FILES['cover']['name'])) {
            try {
                $cover = covers()->store($_FILES['cover']);
            } catch (DomainException $ex) {
                $errors['cover'] = $ex->getMessage();
            }
        }
        if (!$errors) {
            $id = events()->create((int) $me['id'], $old);
            if ($cover) events()->setCover($id, $cover);
            flash($old['status'] === 'published' ? 'Your event is live. Share the link below to start selling.' : 'Saved as a draft. Publish it when you\'re ready.');
            redirect('/events/' . $id);
        }
    }
    render('event_form', ['event' => null, 'old' => $old, 'errors' => $errors], $errors ? 422 : 200);
}

if (preg_match('#^/events/(\d+)(/[a-z]+|/attendees\.csv)?$#', $path, $m)) {
    $me = require_login();
    $event = events()->findOwned((int) $m[1], (int) $me['id']);
    if (!$event) render('404', [], 404);
    $id = (int) $event['id'];
    $action = $m[2] ?? '';

    // Manage page: sales, attendees, share link
    if ($method === 'GET' && $action === '') {
        $event = events()->findWithHostById($id);
        render('manage', [
            'event'     => $event,
            'types'     => events()->types($id),
            'stats'     => orders()->eventStats($id),
            'attendees' => orders()->attendees($id),
            'sales'     => orders()->dailySales((int) $me['id'], 14, $id),
        ]);
    }

    if ($action === '/edit') {
        $types = events()->types($id);
        $old = $event + [
            'start_local' => Events::toLocalInput($event['starts_at'], $event['timezone']),
            'end_local'   => Events::toLocalInput($event['ends_at'], $event['timezone']),
            'types'       => $types,
        ];
        $errors = [];
        if ($method === 'POST') {
            verify_csrf();
            [$clean, $errors] = Events::validate($_POST, config('timezones'), (int) config('max_ticket_types'), $types);
            $cover = null;
            if (!$errors && !empty($_FILES['cover']['name'])) {
                try {
                    $cover = covers()->store($_FILES['cover']);
                } catch (DomainException $ex) {
                    $errors['cover'] = $ex->getMessage();
                }
            }
            if (!$errors) {
                events()->update($id, $clean);
                if ($cover) {
                    covers()->delete($event['cover']);
                    events()->setCover($id, $cover);
                } elseif (!empty($_POST['remove_cover'])) {
                    covers()->delete($event['cover']);
                    events()->setCover($id, null);
                }
                flash('Changes saved.');
                redirect('/events/' . $id);
            }
            $old = $clean + ['id' => $id, 'cover' => $event['cover'], 'slug' => $event['slug']];
            // Keep sold counts visible on the rows that already existed.
            $byId = array_column($types, null, 'id');
            $old['types'] = array_map(fn ($t) => $t + ['sold' => $byId[$t['id']]['sold'] ?? 0], $old['types']);
        }
        render('event_form', ['event' => $event, 'old' => $old, 'errors' => $errors], $errors ? 422 : 200);
    }

    if ($method === 'POST' && in_array($action, ['/publish', '/unpublish', '/cancel', '/delete'], true)) {
        verify_csrf();
        if ($action === '/publish' && $event['status'] !== 'cancelled') {
            events()->setStatus($id, 'published');
            flash('Published. Anyone with the link can get tickets now.');
        } elseif ($action === '/unpublish' && $event['status'] === 'published') {
            events()->setStatus($id, 'draft');
            flash('Moved back to draft. Ticket sales are paused and the page is hidden.');
        } elseif ($action === '/cancel' && $event['status'] !== 'cancelled') {
            events()->setStatus($id, 'cancelled');
            $full = events()->findWithHostById($id);
            $n = 0;
            foreach (orders()->paidOrdersForEvent($id) as $o) {
                $n += (int) Notify::cancelled($full, $o);
            }
            flash('Event cancelled.' . ($n ? ' We emailed ' . plural($n, 'buyer') . '.' : ''));
        } elseif ($action === '/delete') {
            if (events()->delete($id)) {
                covers()->delete($event['cover']);
                flash('"' . $event['title'] . '" was deleted.');
                redirect('/dashboard');
            }
            flash('This event has sold tickets, so it can\'t be deleted. Cancel it instead; buyers get an email.');
        }
        redirect('/events/' . $id);
    }

    if ($method === 'GET' && $action === '/attendees.csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="attendees-' . $event['slug'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // so Excel reads ₦ and accents correctly
        fputcsv($out, ['Name', 'Email', 'Ticket', 'Price', 'Code', 'Order', 'Bought (UTC)', 'Checked in (UTC)']);
        foreach (orders()->attendees($id) as $a) {
            // A leading = + - @ would make Excel run it as a formula; prefix with ' to keep it text.
            $row = [$a['buyer_name'], $a['buyer_email'], $a['type_name'], money($a['price'], false), $a['code'],
                    $a['reference'], $a['paid_at'], $a['checked_in_at'] ?? ''];
            fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row));
        }
        exit;
    }

    // Door scanner
    if ($action === '/checkin') {
        if ($method === 'GET') {
            render('checkin', ['event' => $event, 'stats' => orders()->eventStats($id)], 200);
        }
        verify_csrf();
        if (!rate_ok('checkin|' . $me['id'], 120, 60)) {
            json(['result' => 'slow_down'], 429);
        }
        $r = orders()->checkIn($id, (string) ($_POST['code'] ?? ''));
        json($r + ['stats' => orders()->eventStats($id)]);
    }

    render('404', [], 404);
}

/* ===========================================================================
 | Public pages: organizer and event
 * ========================================================================= */

if ($method === 'GET' && preg_match('#^/@([a-z0-9_]{3,20})$#i', $path, $m)) {
    $owner = users()->findByUsername($m[1]);
    if (!$owner) render('404', [], 404);
    render('organizer', ['owner' => $owner, 'events' => events()->upcoming('', '', '', 60, (int) $owner['id'])]);
}

if (preg_match('#^/e/([a-z0-9-]{3,80})(/calendar\.ics|/checkout)?$#', $path, $m)) {
    $event = events()->findBySlug($m[1]);
    $me = current_user();
    $isOwner = $event && $me && (int) $me['id'] === (int) $event['user_id'];
    // Drafts are only visible to their organizer (as a preview).
    if (!$event || ($event['status'] === 'draft' && !$isOwner)) render('404', [], 404);
    $action = $m[2] ?? '';

    if ($method === 'GET' && $action === '/calendar.ics') {
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $event['slug'] . '.ics"');
        echo Ics::event($event, base_url() . '/e/' . $event['slug'], host());
        exit;
    }

    $types = events()->types((int) $event['id']);
    $form = ['name' => '', 'email' => '', 'qty' => []];
    $error = null;

    if ($method === 'POST' && $action === '/checkout') {
        verify_csrf();
        $form = [
            'name'  => trim((string) ($_POST['name'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'qty'   => array_map('intval', (array) ($_POST['qty'] ?? [])),
        ];
        if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 80) {
            $error = 'Enter your full name (it goes on the ticket).';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address. Your tickets are sent there.';
        } elseif (!rate_ok('checkout|' . client_ip(), 20, 600)) {
            $error = 'Too many checkouts from your network. Wait a few minutes and try again.';
        } else {
            try {
                $order = orders()->reserve($event, $form['name'], $form['email'], $form['qty'], (int) config('max_per_order'));
                if ($order['status'] === 'paid') {                       // free tickets
                    Notify::tickets($order);
                    redirect(ticket_url($order) . '?new=1');
                }
                redirect(start_payment($order, $event));
            } catch (DomainException $ex) {
                $error = $ex->getMessage();
            } catch (RuntimeException $ex) {
                error_log('Checkout: ' . $ex->getMessage());
                $error = 'We couldn\'t reach the payment page just now. Please try again in a minute.';
            }
        }
        $types = events()->types((int) $event['id']);
    }

    render('event', [
        'event'   => $event,
        'types'   => $types,
        'form'    => $form,
        'error'   => $error,
        'isOwner' => $isOwner,
        'meta'    => [
            'title'       => $event['title'] . ' · Tickets on Gatepass',
            'description' => event_when($event) . ' · ' . ($event['venue'] !== '' ? $event['venue'] : 'Online') . '. Hosted by ' . $event['host_name'] . '.',
            'image'       => $event['cover'] ? base_url() . cover_url($event['cover']) : base_url() . '/assets/og.png',
        ],
    ], $error ? 422 : 200);
}

/* ===========================================================================
 | Payments
 * ========================================================================= */

// Paystack sends the buyer back here.
if ($method === 'GET' && $path === '/pay/callback') {
    $ref = (string) ($_GET['reference'] ?? ($_GET['trxref'] ?? ''));
    $order = orders()->findByReference($ref);
    $paystack = Paystack::fromConfig();
    if (!$order || !$paystack) render('404', [], 404);
    if ($order['status'] !== 'paid') {
        try {
            $v = $paystack->verify($ref);
            if ($v['paid'] && $v['currency'] === 'NGN') {
                complete_payment($ref, $v['amount'], 'paystack');
            }
        } catch (RuntimeException $ex) {
            error_log('Verify: ' . $ex->getMessage());
        }
    }
    redirect(ticket_url(orders()->findByReference($ref)));
}

// Paystack also tells us directly, in case the buyer closed the tab before coming back.
if ($method === 'POST' && $path === '/paystack/webhook') {
    $raw = (string) file_get_contents('php://input');
    $paystack = Paystack::fromConfig();
    if (!$paystack || !$paystack->webhookIsGenuine($raw, (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? ''))) {
        http_response_code(401);
        exit;
    }
    $payload = json_decode($raw, true);
    if (($payload['event'] ?? '') === 'charge.success') {
        $ref = (string) ($payload['data']['reference'] ?? '');
        // Don't trust the webhook body for the amount either: ask Paystack.
        $v = $paystack->verify($ref);
        if ($v['paid'] && $v['currency'] === 'NGN') {
            complete_payment($ref, $v['amount'], 'paystack');
        }
    }
    http_response_code(200);
    exit;
}

// Demo checkout: only exists when no Paystack keys are set.
if (preg_match('#^/pay/demo/(GP-[A-F0-9]{12})$#', $path, $m)) {
    if (Paystack::fromConfig()) render('404', [], 404);
    $order = orders()->findByReference($m[1]);
    if (!$order || ($_SESSION['demo_order'] ?? '') !== $order['reference']) render('404', [], 404);
    if ($method === 'POST') {
        verify_csrf();
        complete_payment($order['reference'], (int) $order['total'], 'demo');
        unset($_SESSION['demo_order']);
        redirect(ticket_url($order) . '?new=1');
    }
    if ($order['status'] !== 'pending') redirect(ticket_url($order));
    render('demo_pay', ['order' => $order, 'event' => events()->findWithHostById((int) $order['event_id']),
                        'items' => orders()->items((int) $order['id'])]);
}

/* ===========================================================================
 | The buyer's tickets (secret link from the email)
 * ========================================================================= */

if ($method === 'GET' && preg_match('#^/tickets/(GP-[A-F0-9]{12})/([a-f0-9]{32})(/event\.ics|/qr/([A-Z0-9-]{14})\.svg)?$#', $path, $m)) {
    $order = orders()->findForBuyer($m[1], $m[2]);
    if (!$order) render('404', [], 404);
    $event = events()->findWithHostById((int) $order['event_id']);
    $tickets = $order['status'] === 'paid' ? orders()->tickets((int) $order['id']) : [];
    $sub = $m[3] ?? '';

    if ($sub === '/event.ics') {
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $event['slug'] . '.ics"');
        echo Ics::event($event, ticket_url($order), host(),
            'Your tickets (order ' . $order['reference'] . ').' . ($event['online_url'] !== '' ? "\nJoin online: " . $event['online_url'] : ''));
        exit;
    }
    if ($sub !== '') {
        if (!in_array($m[4], array_column($tickets, 'code'), true)) render('404', [], 404);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: private, max-age=86400');
        echo Qr::svg($m[4], 3);
        exit;
    }

    header('X-Robots-Tag: noindex');
    render('tickets', ['order' => $order, 'event' => $event, 'tickets' => $tickets, 'items' => orders()->items((int) $order['id']),
                       'isNew' => isset($_GET['new']), 'meta' => ['title' => 'Your tickets · ' . $event['title']]]);
}

/* ===========================================================================
 | Web cron (for hosts without a real cron job). Off unless cron_token is set.
 * ========================================================================= */

if (preg_match('#^/cron/([A-Za-z0-9]{24,})$#', $path, $m)) {
    $token = (string) config('cron_token');
    if ($token === '' || !hash_equals($token, $m[1])) render('404', [], 404);
    ignore_user_abort(true);
    json(Notify::runReminders());
}

render('404', [], 404);


/* ===========================================================================
 | Helpers used only by this file
 * ========================================================================= */

/** Send the buyer to pay: Paystack if configured, otherwise the demo checkout. */
function start_payment(array $order, array $event): string
{
    $paystack = Paystack::fromConfig();
    if (!$paystack) {
        $_SESSION['demo_order'] = $order['reference'];
        return '/pay/demo/' . $order['reference'];
    }
    return $paystack->initialize($order['buyer_email'], (int) $order['total'], $order['reference'],
        base_url() . '/pay/callback', ['event' => $event['title'], 'buyer' => $order['buyer_name']]);
}

/** Mark paid and email the tickets (once, even if the callback and webhook both arrive). */
function complete_payment(string $reference, int $amount, string $provider): void
{
    $order = orders()->markPaid($reference, $amount, $provider);
    if ($order && $order['just_paid']) {
        Notify::tickets($order);
    }
}
