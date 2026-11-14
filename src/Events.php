<?php
declare(strict_types=1);

/**
 * Events and their ticket types.
 *
 * "Taken" tickets = sold (paid orders) + held (unpaid orders that haven't expired).
 * Everything that sells tickets goes through Orders, inside a write lock.
 */
final class Events
{
    public function __construct(private PDO $db) {}

    // ---- Reading ---------------------------------------------------------------

    public function find(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM events WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM events WHERE id = ? AND user_id = ?');
        $s->execute([$id, $userId]);
        return $s->fetch() ?: null;
    }

    /** Event plus its organizer's public details. */
    public function findBySlug(string $slug): ?array
    {
        $s = $this->db->prepare(
            'SELECT e.*, u.username, u.name AS host_name, u.bio AS host_bio
               FROM events e JOIN users u ON u.id = e.user_id
              WHERE e.slug = ?'
        );
        $s->execute([$slug]);
        return $s->fetch() ?: null;
    }

    /** An organizer's events with sales numbers, soonest first, past events last. */
    public function forUser(int $userId): array
    {
        $s = $this->db->prepare(
            "SELECT e.*,
                    (SELECT COALESCE(SUM(quantity), 0) FROM ticket_types WHERE event_id = e.id AND archived = 0) AS capacity,
                    (SELECT COUNT(*) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.event_id = e.id) AS sold,
                    (SELECT COUNT(*) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.event_id = e.id AND t.checked_in_at IS NOT NULL) AS checked_in,
                    (SELECT COALESCE(SUM(total), 0) FROM orders WHERE event_id = e.id AND status = 'paid') AS revenue
               FROM events e
              WHERE e.user_id = ?
              ORDER BY (e.ends_at < datetime('now')), e.starts_at"
        );
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public function countForUser(int $userId): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM events WHERE user_id = ?');
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }

    /**
     * Published events that haven't ended, for Explore and the home page.
     * $when: '' | 'today' | 'week' | 'month'. $price: '' | 'free' | 'paid'.
     */
    public function upcoming(string $q = '', string $when = '', string $price = '', int $limit = 24, ?int $userId = null): array
    {
        $where = ["e.status = 'published'", "e.ends_at > datetime('now')"];
        $args = [];
        if ($userId !== null) {
            $where[] = 'e.user_id = ?';
            $args[] = $userId;
        }
        if ($q !== '') {
            $where[] = "(e.title LIKE ? ESCAPE '\\' OR e.venue LIKE ? ESCAPE '\\' OR u.name LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        $until = match ($when) {
            'today' => '+1 day',
            'week'  => '+7 days',
            'month' => '+31 days',
            default => null,
        };
        if ($until) {
            $where[] = 'e.starts_at <= datetime(\'now\', ?)';
            $args[] = $until;
        }
        $minPrice = '(SELECT MIN(price) FROM ticket_types WHERE event_id = e.id AND archived = 0)';
        if ($price === 'free') $where[] = "$minPrice = 0";
        if ($price === 'paid') $where[] = "$minPrice > 0";

        $s = $this->db->prepare(
            "SELECT e.id, e.slug, e.title, e.venue, e.starts_at, e.ends_at, e.timezone, e.cover,
                    u.username, u.name AS host_name,
                    $minPrice AS from_price,
                    (SELECT COALESCE(SUM(quantity), 0) FROM ticket_types WHERE event_id = e.id AND archived = 0) AS capacity,
                    (SELECT COUNT(*) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.event_id = e.id) AS sold
               FROM events e JOIN users u ON u.id = e.user_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY e.starts_at
              LIMIT ' . max(1, min(60, $limit))
        );
        $s->execute($args);
        return $s->fetchAll();
    }

    /** Ticket types with how many are sold and held right now. */
    public function types(int $eventId, bool $withArchived = false): array
    {
        $s = $this->db->prepare(
            "SELECT tt.*,
                    COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                               WHERE oi.ticket_type_id = tt.id AND o.status = 'paid'), 0) AS sold,
                    COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                               WHERE oi.ticket_type_id = tt.id AND o.status = 'pending' AND o.expires_at > datetime('now')), 0) AS held
               FROM ticket_types tt
              WHERE tt.event_id = ?" . ($withArchived ? '' : ' AND tt.archived = 0') . '
              ORDER BY tt.position, tt.id'
        );
        $s->execute([$eventId]);
        return array_map(function ($t) {
            $t['left'] = max(0, (int) $t['quantity'] - (int) $t['sold'] - (int) $t['held']);
            return $t;
        }, $s->fetchAll());
    }

    // ---- Writing ---------------------------------------------------------------

    /** @param array $data cleaned by Events::validate() */
    public function create(int $userId, array $data): int
    {
        return Database::transaction(function (PDO $db) use ($userId, $data) {
            $s = $db->prepare(
                'INSERT INTO events (user_id, slug, title, description, venue, online_url, starts_at, ends_at, timezone, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $s->execute([$userId, $this->uniqueSlug($data['title']), $data['title'], $data['description'], $data['venue'],
                         $data['online_url'], $data['starts_at'], $data['ends_at'], $data['timezone'], $data['status']]);
            $id = (int) $db->lastInsertId();
            $this->saveTypes($id, $data['types']);
            return $id;
        });
    }

    public function update(int $id, array $data): void
    {
        Database::transaction(function (PDO $db) use ($id, $data) {
            $s = $db->prepare(
                'UPDATE events SET title = ?, description = ?, venue = ?, online_url = ?, starts_at = ?, ends_at = ?, timezone = ?
                  WHERE id = ?'
            );
            $s->execute([$data['title'], $data['description'], $data['venue'], $data['online_url'],
                         $data['starts_at'], $data['ends_at'], $data['timezone'], $id]);
            $this->saveTypes($id, $data['types']);
        });
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function setCover(int $id, ?string $file): void
    {
        $this->db->prepare('UPDATE events SET cover = ? WHERE id = ?')->execute([$file, $id]);
    }

    /** Only events that never sold a ticket can be deleted; the rest can be cancelled. */
    public function delete(int $id): bool
    {
        $s = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE event_id = ? AND status = 'paid'");
        $s->execute([$id]);
        if ((int) $s->fetchColumn() > 0) {
            return false;
        }
        $this->db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        return true;
    }

    /**
     * Insert, update or remove ticket types. A type that already sold tickets is
     * archived rather than deleted, so existing tickets keep their name.
     * Must run inside a transaction.
     */
    private function saveTypes(int $eventId, array $types): void
    {
        $existing = [];
        foreach ($this->types($eventId, true) as $t) {
            $existing[(int) $t['id']] = $t;
        }
        $kept = [];
        foreach (array_values($types) as $pos => $t) {
            $id = (int) ($t['id'] ?? 0);
            if ($id && isset($existing[$id])) {
                $this->db->prepare('UPDATE ticket_types SET name = ?, price = ?, quantity = ?, position = ?, archived = 0 WHERE id = ?')
                    ->execute([$t['name'], $t['price'], $t['quantity'], $pos, $id]);
                $kept[$id] = true;
            } else {
                $this->db->prepare('INSERT INTO ticket_types (event_id, name, price, quantity, position) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$eventId, $t['name'], $t['price'], $t['quantity'], $pos]);
            }
        }
        foreach ($existing as $id => $t) {
            if (isset($kept[$id])) continue;
            $used = $this->db->prepare('SELECT COUNT(*) FROM order_items WHERE ticket_type_id = ?');
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                $this->db->prepare('UPDATE ticket_types SET archived = 1 WHERE id = ?')->execute([$id]);
            } else {
                $this->db->prepare('DELETE FROM ticket_types WHERE id = ?')->execute([$id]);
            }
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: '')), '-');
        $base = substr($base, 0, 50) ?: 'event';
        do {
            $slug = rtrim($base, '-') . '-' . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 4);
            $s = $this->db->prepare('SELECT 1 FROM events WHERE slug = ?');
            $s->execute([$slug]);
        } while ($s->fetchColumn());
        return $slug;
    }

    // ---- Validation --------------------------------------------------------------

    /**
     * Clean the event form. Dates arrive as local "YYYY-MM-DDTHH:MM" in the chosen timezone
     * and are stored in UTC. Prices arrive in naira and are stored in kobo.
     *
     * @param array|null $current existing event (with 'types') when editing
     * @return array{0: array, 1: array<string, string>} cleaned data and errors
     */
    public static function validate(array $in, array $timezones, int $maxTypes, ?array $currentTypes = null): array
    {
        $errors = [];
        $d = [
            'title'       => trim((string) ($in['title'] ?? '')),
            'description' => trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? ''))),
            'venue'       => trim((string) ($in['venue'] ?? '')),
            'online_url'  => trim((string) ($in['online_url'] ?? '')),
            'timezone'    => (string) ($in['timezone'] ?? 'Africa/Lagos'),
            'start_local' => (string) ($in['start_local'] ?? ''),
            'end_local'   => (string) ($in['end_local'] ?? ''),
            'status'      => ($in['status'] ?? '') === 'published' ? 'published' : 'draft',
            'types'       => [],
        ];

        if (mb_strlen($d['title']) < 3 || mb_strlen($d['title']) > 90) {
            $errors['title'] = 'Give your event a name (3–90 characters).';
        }
        if (mb_strlen($d['description']) > 5000) {
            $errors['description'] = 'Keep the description under 5,000 characters.';
        }
        if ($d['venue'] === '' && $d['online_url'] === '') {
            $errors['venue'] = 'Add a venue address, an online link, or both.';
        }
        if (mb_strlen($d['venue']) > 200) {
            $errors['venue'] = 'Keep the venue under 200 characters.';
        }
        if ($d['online_url'] !== '' && (!filter_var($d['online_url'], FILTER_VALIDATE_URL)
                || !preg_match('#^https?://#i', $d['online_url']) || strlen($d['online_url']) > 300)) {
            $errors['online_url'] = 'Use a full link that starts with https://';
        }
        if (!isset($timezones[$d['timezone']])) {
            $d['timezone'] = 'Africa/Lagos';
        }

        $start = self::toUtc($d['start_local'], $d['timezone']);
        $end = self::toUtc($d['end_local'], $d['timezone']);
        if (!$start) {
            $errors['start_local'] = 'Pick when the event starts.';
        } elseif ($currentTypes === null && strtotime($start . ' UTC') < time()) {
            $errors['start_local'] = 'The start time is in the past.';
        }
        if (!$end) {
            $errors['end_local'] = 'Pick when the event ends.';
        } elseif ($start && $end <= $start) {
            $errors['end_local'] = 'The end must be after the start.';
        }
        $d['starts_at'] = $start ?: '';
        $d['ends_at'] = $end ?: '';

        // Ticket types arrive as parallel arrays: type_id[], type_name[], type_price[], type_qty[]
        $sold = [];
        foreach ($currentTypes ?? [] as $t) {
            $sold[(int) $t['id']] = (int) $t['sold'] + (int) $t['held'];
        }
        $names = (array) ($in['type_name'] ?? []);
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            $priceRaw = str_replace([',', ' ', '₦'], '', (string) ($in['type_price'][$i] ?? '0'));
            $qtyRaw = (string) ($in['type_qty'][$i] ?? '');
            $id = (int) ($in['type_id'][$i] ?? 0);
            if ($name === '' && $qtyRaw === '' && ($priceRaw === '' || $priceRaw === '0')) {
                continue;   // an empty row
            }
            $row = ['id' => isset($sold[$id]) ? $id : 0, 'name' => $name, 'price' => 0, 'quantity' => 0];
            if ($name === '' || mb_strlen($name) > 40) {
                $errors['types'] = 'Each ticket needs a name, like "Regular" or "VIP" (up to 40 characters).';
            }
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 10_000_000) {
                $errors['types'] = 'Prices must be a number between 0 (free) and 10,000,000.';
            } else {
                $row['price'] = (int) round((float) $priceRaw * 100);
                if ($row['price'] > 0 && $row['price'] < 10000) {
                    $errors['types'] = 'Paid tickets must cost at least 100 (card payments have a minimum).';
                }
            }
            if (!ctype_digit($qtyRaw) || (int) $qtyRaw < 1 || (int) $qtyRaw > 100000) {
                $errors['types'] = 'How many of each ticket? Use a whole number from 1 to 100,000.';
            } else {
                $row['quantity'] = (int) $qtyRaw;
                if ($row['id'] && $row['quantity'] < $sold[$row['id']]) {
                    $errors['types'] = '"' . $name . '" already has ' . $sold[$row['id']] . ' sold or held, so it can\'t go below that.';
                }
            }
            $d['types'][] = $row;
        }
        if (!$d['types'] && !isset($errors['types'])) {
            $errors['types'] = 'Add at least one ticket type.';
        }
        if (count($d['types']) > $maxTypes) {
            $errors['types'] = "Up to $maxTypes ticket types per event.";
        }

        return [$d, $errors];
    }

    /** "2026-12-05T18:30" in $tz → "2026-12-05 17:30:00" UTC, or null. */
    public static function toUtc(string $local, string $tz): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone($tz));
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $local) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC "Y-m-d H:i:s" → local "YYYY-MM-DDTHH:MM" for the form. */
    public static function toLocalInput(string $utc, string $tz): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i');
    }
}
