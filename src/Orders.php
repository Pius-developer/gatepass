<?php
declare(strict_types=1);

/**
 * Checkout, tickets and check-in.
 *
 * Flow: reserve() holds tickets in a "pending" order for a few minutes →
 * the buyer pays → markPaid() (after the server verifies the payment) issues
 * one ticket per seat with its own QR code.
 */
final class Orders
{
    // No 0/O, 1/I/L: codes are easy to read out at the gate if a phone won't scan.
    private const CODE_CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private PDO $db, private int $holdMinutes = 15) {}

    // ---- Checkout ----------------------------------------------------------------

    /**
     * Hold tickets for a buyer. $items is [ticket_type_id => quantity].
     * Throws DomainException with a message for the buyer if it can't.
     * Free orders are paid straight away.
     */
    public function reserve(array $event, string $name, string $email, array $items, int $maxPerOrder): array
    {
        $items = array_filter(array_map('intval', $items), fn ($q) => $q > 0);
        if (!$items) {
            throw new DomainException('Choose at least one ticket.');
        }
        if (array_sum($items) > $maxPerOrder) {
            throw new DomainException("You can get up to $maxPerOrder tickets in one order.");
        }
        if ($event['status'] !== 'published') {
            throw new DomainException('This event isn\'t selling tickets.');
        }
        if (strtotime($event['ends_at'] . ' UTC') < time()) {
            throw new DomainException('This event has already ended.');
        }

        $order = Database::transaction(function (PDO $db) use ($event, $name, $email, $items) {
            $this->expireStale();
            $types = [];
            foreach (events()->types((int) $event['id']) as $t) {
                $types[(int) $t['id']] = $t;
            }

            $total = 0;
            foreach ($items as $typeId => $qty) {
                $t = $types[$typeId] ?? null;
                if (!$t) {
                    throw new DomainException('That ticket type is no longer available. Refresh the page.');
                }
                if ($qty > $t['left']) {
                    throw new DomainException($t['left'] > 0
                        ? 'Only ' . $t['left'] . ' "' . $t['name'] . '" ' . ($t['left'] === 1 ? 'ticket is' : 'tickets are') . ' left.'
                        : '"' . $t['name'] . '" is sold out.');
                }
                $total += $qty * (int) $t['price'];
            }

            $reference = 'GP-' . strtoupper(bin2hex(random_bytes(6)));
            $db->prepare(
                "INSERT INTO orders (event_id, reference, access_key, buyer_name, buyer_email, total, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, datetime('now', ?))"
            )->execute([$event['id'], $reference, bin2hex(random_bytes(16)), $name, $email, $total,
                        '+' . $this->holdMinutes . ' minutes']);
            $orderId = (int) $db->lastInsertId();

            $ins = $db->prepare('INSERT INTO order_items (order_id, ticket_type_id, quantity, price) VALUES (?, ?, ?, ?)');
            foreach ($items as $typeId => $qty) {
                $ins->execute([$orderId, $typeId, $qty, $types[$typeId]['price']]);
            }
            return $this->findByReference($reference);
        });

        if ((int) $order['total'] === 0) {
            $order = $this->markPaid($order['reference'], 0, 'free');
        }
        return $order;
    }

    /**
     * Mark an order paid and issue its tickets. Safe to call twice (Paystack's
     * redirect and its webhook can both arrive): the second call changes nothing.
     * Returns the order (with just_paid = true only for the call that paid it, so the
     * ticket email goes out once), or null if the reference is unknown or the amount is wrong.
     */
    public function markPaid(string $reference, int $amountPaid, string $provider): ?array
    {
        return Database::transaction(function (PDO $db) use ($reference, $amountPaid, $provider) {
            $order = $this->findByReference($reference);
            if (!$order) {
                return null;
            }
            if ($order['status'] === 'paid') {
                return $order + ['just_paid' => false];
            }
            if ($amountPaid !== (int) $order['total']) {
                return null;   // never trust a payment for a different amount
            }
            // A buyer who paid just after their hold expired is still let in: they paid.
            $db->prepare("UPDATE orders SET status = 'paid', paid_at = datetime('now'), provider = ? WHERE id = ?")
               ->execute([$provider, $order['id']]);

            $ins = $db->prepare('INSERT INTO tickets (order_id, ticket_type_id, code) VALUES (?, ?, ?)');
            foreach ($this->items((int) $order['id']) as $item) {
                for ($i = 0; $i < (int) $item['quantity']; $i++) {
                    $ins->execute([$order['id'], $item['ticket_type_id'], $this->newCode()]);
                }
            }
            return $this->findByReference($reference) + ['just_paid' => true];
        });
    }

    /** Release tickets held by unpaid orders whose time ran out. */
    public function expireStale(): int
    {
        return $this->db->exec("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at <= datetime('now')");
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
            $code = implode('-', str_split($code, 4));    // e.g. K7Q2-M9XD-4TPA (~59 bits)
            $s = $this->db->prepare('SELECT 1 FROM tickets WHERE code = ?');
            $s->execute([$code]);
        } while ($s->fetchColumn());
        return $code;
    }

    // ---- Reading ---------------------------------------------------------------

    public function findByReference(string $reference): ?array
    {
        $s = $this->db->prepare('SELECT * FROM orders WHERE reference = ?');
        $s->execute([$reference]);
        return $s->fetch() ?: null;
    }

    /** The buyer's view: needs the secret key from their email link. */
    public function findForBuyer(string $reference, string $key): ?array
    {
        $order = $this->findByReference($reference);
        return ($order && hash_equals($order['access_key'], $key)) ? $order : null;
    }

    public function items(int $orderId): array
    {
        $s = $this->db->prepare(
            'SELECT oi.*, tt.name FROM order_items oi JOIN ticket_types tt ON tt.id = oi.ticket_type_id
              WHERE oi.order_id = ? ORDER BY tt.position, tt.id'
        );
        $s->execute([$orderId]);
        return $s->fetchAll();
    }

    public function tickets(int $orderId): array
    {
        $s = $this->db->prepare(
            'SELECT t.*, tt.name AS type_name FROM tickets t JOIN ticket_types tt ON tt.id = t.ticket_type_id
              WHERE t.order_id = ? ORDER BY t.id'
        );
        $s->execute([$orderId]);
        return $s->fetchAll();
    }

    /** Everyone with a ticket to an event, for the organizer. */
    public function attendees(int $eventId): array
    {
        $s = $this->db->prepare(
            "SELECT t.id, t.code, t.checked_in_at, tt.name AS type_name, o.reference, o.buyer_name, o.buyer_email,
                    o.paid_at, oi.price
               FROM tickets t
               JOIN orders o ON o.id = t.order_id
               JOIN ticket_types tt ON tt.id = t.ticket_type_id
               JOIN order_items oi ON oi.order_id = o.id AND oi.ticket_type_id = t.ticket_type_id
              WHERE o.event_id = ? AND o.status = 'paid'
              ORDER BY o.paid_at DESC, t.id"
        );
        $s->execute([$eventId]);
        return $s->fetchAll();
    }

    /** Totals for one event. */
    public function eventStats(int $eventId): array
    {
        $s = $this->db->prepare(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders WHERE event_id = ? AND status = 'paid'"
        );
        $s->execute([$eventId]);
        $o = $s->fetch();
        $s = $this->db->prepare(
            "SELECT COUNT(*) AS tickets, COUNT(t.checked_in_at) AS checked_in
               FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.event_id = ?"
        );
        $s->execute([$eventId]);
        return $o + $s->fetch();
    }

    /**
     * Tickets sold and revenue per day for the last $days days, across all of an
     * organizer's events (or one event). Days with no sales are included as zero.
     * @return array<int, array{day: string, tickets: int, revenue: int}>
     */
    public function dailySales(int $userId, int $days = 30, ?int $eventId = null): array
    {
        $sql = "SELECT date(o.paid_at) AS day, SUM(o.total) AS revenue,
                       SUM((SELECT COUNT(*) FROM tickets t WHERE t.order_id = o.id)) AS tickets
                  FROM orders o JOIN events e ON e.id = o.event_id
                 WHERE o.status = 'paid' AND e.user_id = ? AND o.paid_at >= date('now', ?)"
             . ($eventId ? ' AND o.event_id = ?' : '') . '
                 GROUP BY date(o.paid_at)';
        $args = [$userId, '-' . ($days - 1) . ' days'];
        if ($eventId) $args[] = $eventId;
        $s = $this->db->prepare($sql);
        $s->execute($args);
        $byDay = [];
        foreach ($s->fetchAll() as $r) {
            $byDay[$r['day']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-$i days"));
            $out[] = ['day' => $day, 'tickets' => (int) ($byDay[$day]['tickets'] ?? 0), 'revenue' => (int) ($byDay[$day]['revenue'] ?? 0)];
        }
        return $out;
    }

    /** Most recent paid orders across an organizer's events. */
    public function recentForUser(int $userId, int $limit = 8): array
    {
        $s = $this->db->prepare(
            "SELECT o.reference, o.buyer_name, o.total, o.paid_at, e.title, e.id AS event_id,
                    (SELECT COUNT(*) FROM tickets WHERE order_id = o.id) AS tickets
               FROM orders o JOIN events e ON e.id = o.event_id
              WHERE e.user_id = ? AND o.status = 'paid'
              ORDER BY o.paid_at DESC LIMIT " . max(1, $limit)
        );
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    // ---- Check-in ----------------------------------------------------------------

    /**
     * Scan a ticket at the door. Only works for the event it belongs to, and only once.
     * @return array{result: string, ticket?: array} result: ok | already | wrong_event | not_found
     */
    public function checkIn(int $eventId, string $code): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 12) {
            return ['result' => 'not_found'];
        }
        $code = implode('-', str_split($code, 4));

        return Database::transaction(function (PDO $db) use ($eventId, $code) {
            $s = $db->prepare(
                "SELECT t.*, tt.name AS type_name, o.buyer_name, o.event_id, o.status
                   FROM tickets t JOIN orders o ON o.id = t.order_id JOIN ticket_types tt ON tt.id = t.ticket_type_id
                  WHERE t.code = ?"
            );
            $s->execute([$code]);
            $t = $s->fetch();
            if (!$t || $t['status'] !== 'paid') {
                return ['result' => 'not_found'];
            }
            $ticket = ['code' => $t['code'], 'name' => $t['buyer_name'], 'type' => $t['type_name'], 'checked_in_at' => $t['checked_in_at']];
            if ((int) $t['event_id'] !== $eventId) {
                return ['result' => 'wrong_event', 'ticket' => $ticket];
            }
            if ($t['checked_in_at']) {
                return ['result' => 'already', 'ticket' => $ticket];
            }
            $db->prepare("UPDATE tickets SET checked_in_at = datetime('now') WHERE id = ?")->execute([$t['id']]);
            $ticket['checked_in_at'] = gmdate('Y-m-d H:i:s');
            return ['result' => 'ok', 'ticket' => $ticket];
        });
    }

    // ---- Reminders -------------------------------------------------------------

    /** Paid orders whose event starts within $hours and haven't been reminded. */
    public function dueReminders(int $hours): array
    {
        $s = $this->db->prepare(
            "SELECT o.* FROM orders o JOIN events e ON e.id = o.event_id
              WHERE o.status = 'paid' AND o.reminded_at IS NULL AND e.status = 'published'
                AND e.starts_at > datetime('now') AND e.starts_at <= datetime('now', ?)
                AND o.paid_at <= datetime('now', '-1 hour')"   // just bought? they already have the ticket email
        );
        $s->execute(['+' . $hours . ' hours']);
        return $s->fetchAll();
    }

    public function markReminded(int $orderId): void
    {
        $this->db->prepare("UPDATE orders SET reminded_at = datetime('now') WHERE id = ?")->execute([$orderId]);
    }

    /** Buyers of an event, for the cancellation email. */
    public function paidOrdersForEvent(int $eventId): array
    {
        $s = $this->db->prepare("SELECT * FROM orders WHERE event_id = ? AND status = 'paid'");
        $s->execute([$eventId]);
        return $s->fetchAll();
    }
}
