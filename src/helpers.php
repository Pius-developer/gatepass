<?php
declare(strict_types=1);

function config(string $key): mixed
{
    return $GLOBALS['config'][$key] ?? null;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- Services -------------------------------------------------------------

function users(): Users { static $u; return $u ??= new Users(Database::connection()); }
function events(): Events { static $e; return $e ??= new Events(Database::connection()); }
function orders(): Orders { static $o; return $o ??= new Orders(Database::connection(), (int) config('hold_minutes')); }
function covers(): Covers { return new Covers(ROOT . '/public_html/uploads'); }
function mailer(): Mailer { return Mailer::fromConfig(); }

// ---- CSRF -----------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Forms send _csrf in the body; fetch() calls send it in the X-CSRF-Token header. */
function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh and try again.');
    }
}

// ---- Auth -----------------------------------------------------------------

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = $_SESSION['user_id'] ?? null;
        $user = $id ? users()->find((int) $id) : null;
    }
    return $user;
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/dashboard';
        flash('Log in to continue.');
        redirect('/login');
    }
    return $user;
}

/** Simple rate limit: at most $max hits per $seconds for this key. */
function rate_ok(string $key, int $max, int $seconds): bool
{
    $db = Database::connection();
    $key = hash('sha256', $key);
    $s = $db->prepare('SELECT COUNT(*) FROM hits WHERE key = ? AND created_at > ?');
    $s->execute([$key, time() - $seconds]);
    if ((int) $s->fetchColumn() >= $max) {
        return false;
    }
    $db->prepare('INSERT INTO hits (key, created_at) VALUES (?, ?)')->execute([$key, time()]);
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM hits WHERE created_at < ?')->execute([time() - 86400]);
    }
    return true;
}

function client_ip(): string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

// ---- Responses ------------------------------------------------------------

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function render(string $view, array $data = [], int $status = 200): never
{
    http_response_code($status);
    $data['me']    = current_user();
    $data['flash'] = flash();
    extract($data);
    ob_start();
    require ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require ROOT . '/views/layout.php';
    exit;
}

/** Render a template to a string (used for emails). */
function partial(string $view, array $data = []): string
{
    extract($data);
    ob_start();
    require ROOT . '/views/' . $view . '.php';
    return (string) ob_get_clean();
}

function json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function base_url(): string
{
    if (config('app_url')) {
        return rtrim(config('app_url'), '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function host(): string
{
    return preg_replace('#^https?://#', '', base_url());
}

function cover_url(?string $file): ?string
{
    return $file ? '/uploads/' . rawurlencode($file) : null;
}

/** The buyer's private ticket page. */
function ticket_url(array $order): string
{
    return base_url() . '/tickets/' . $order['reference'] . '/' . $order['access_key'];
}

// ---- Formatting -----------------------------------------------------------

/** 500000 → "₦5,000", 0 → "Free". Kobo are shown only when there are some. */
function money(int|string $kobo, bool $freeWord = true): string
{
    $kobo = (int) $kobo;
    if ($kobo === 0 && $freeWord) return 'Free';
    $naira = $kobo / 100;
    return config('currency_symbol') . number_format($naira, $kobo % 100 ? 2 : 0);
}

/** UTC time shown in the event's own timezone: "Sat 5 Dec 2026, 6:30 PM". */
function local_time(string $utc, string $tz, string $format = 'D j M Y, g:i A'): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format($format);
}

/** "Sat 5 Dec, 6:30 – 10:00 PM WAT" or across days "5 Dec, 6 PM – 7 Dec, 2 AM WAT". */
function event_when(array $event): string
{
    $tz = $event['timezone'];
    $s = new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC'));
    $f = new DateTimeImmutable($event['ends_at'], new DateTimeZone('UTC'));
    $s = $s->setTimezone(new DateTimeZone($tz));
    $f = $f->setTimezone(new DateTimeZone($tz));
    $abbr = $s->format('T');
    if (preg_match('/^[+-]\d/', $abbr)) $abbr = 'GMT' . $abbr;   // zones without a short name
    $time = fn (DateTimeImmutable $d) => $d->format('i') === '00' ? $d->format('g A') : $d->format('g:i A');
    if ($s->format('Y-m-d') === $f->format('Y-m-d')) {
        return $s->format('D j M Y') . ', ' . $time($s) . ' – ' . $time($f) . ' ' . $abbr;
    }
    return $s->format('D j M') . ', ' . $time($s) . ' – ' . $f->format('D j M Y') . ', ' . $time($f) . ' ' . $abbr;
}

function ago(?string $utc): string
{
    if (!$utc) return 'never';
    $diff = time() - strtotime($utc . ' UTC');
    return match (true) {
        $diff < 45     => 'just now',
        $diff < 3600   => max(1, intdiv($diff, 60)) . ' min ago',
        $diff < 86400  => intdiv($diff, 3600) . ' h ago',
        default        => intdiv($diff, 86400) . ' d ago',
    };
}

function plural(int $n, string $one, ?string $many = null): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/** Paragraphs and line breaks for user text, safely escaped. */
function paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split("/\n{2,}/", trim($text)) as $p) {
        if ($p !== '') $out .= '<p>' . nl2br(e($p)) . '</p>';
    }
    return $out;
}

/** Is the event over? Is it on now? */
function event_phase(array $event): string
{
    $now = time();
    if ($event['status'] === 'cancelled') return 'cancelled';
    if (strtotime($event['ends_at'] . ' UTC') < $now) return 'past';
    if (strtotime($event['starts_at'] . ' UTC') <= $now) return 'live';
    return 'upcoming';
}
