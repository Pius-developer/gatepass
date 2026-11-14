<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    /** For tests: use a fresh database. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Run $fn inside a write transaction. BEGIN IMMEDIATE takes SQLite's write lock
     * straight away, so two buyers can't both grab the last ticket.
     */
    public static function transaction(callable $fn): mixed
    {
        $db = self::connection();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($db);
            $db->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    private static function migrate(PDO $db): void
    {
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
                email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                name          TEXT NOT NULL,              -- shown as "Hosted by ..."
                bio           TEXT NOT NULL DEFAULT '',
                created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS events (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                slug         TEXT NOT NULL UNIQUE,
                title        TEXT NOT NULL,
                description  TEXT NOT NULL DEFAULT '',
                venue        TEXT NOT NULL DEFAULT '',     -- address, or empty for online events
                online_url   TEXT NOT NULL DEFAULT '',     -- only sent to ticket holders
                starts_at    TEXT NOT NULL,                -- UTC, 'Y-m-d H:i:s'
                ends_at      TEXT NOT NULL,                -- UTC
                timezone     TEXT NOT NULL DEFAULT 'Africa/Lagos',
                cover        TEXT,                         -- file name in public_html/uploads
                status       TEXT NOT NULL DEFAULT 'draft', -- draft | published | cancelled
                created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_events_user ON events(user_id, starts_at);
            CREATE INDEX IF NOT EXISTS idx_events_public ON events(status, starts_at);

            CREATE TABLE IF NOT EXISTS ticket_types (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id    INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                price       INTEGER NOT NULL DEFAULT 0,    -- kobo; 0 = free
                quantity    INTEGER NOT NULL,              -- how many exist in total
                position    INTEGER NOT NULL DEFAULT 0,
                archived    INTEGER NOT NULL DEFAULT 0     -- removed by the organizer after some were sold
            );
            CREATE INDEX IF NOT EXISTS idx_types_event ON ticket_types(event_id, position);

            -- One checkout. "pending" orders hold their tickets until expires_at.
            CREATE TABLE IF NOT EXISTS orders (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id       INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
                reference      TEXT NOT NULL UNIQUE,       -- also the Paystack reference
                access_key     TEXT NOT NULL,              -- secret part of the buyer's ticket link
                buyer_name     TEXT NOT NULL,
                buyer_email    TEXT NOT NULL,
                total          INTEGER NOT NULL,           -- kobo
                status         TEXT NOT NULL DEFAULT 'pending', -- pending | paid | expired
                expires_at     TEXT NOT NULL,
                paid_at        TEXT,
                provider       TEXT,                       -- free | paystack | demo
                reminded_at    TEXT,
                created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_orders_event ON orders(event_id, status);
            CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status, expires_at);

            CREATE TABLE IF NOT EXISTS order_items (
                order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                ticket_type_id  INTEGER NOT NULL REFERENCES ticket_types(id),
                quantity        INTEGER NOT NULL,
                price           INTEGER NOT NULL,          -- kobo each, copied at checkout time
                PRIMARY KEY (order_id, ticket_type_id)
            );

            -- One row per person allowed in. Created only when an order is paid.
            CREATE TABLE IF NOT EXISTS tickets (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                ticket_type_id  INTEGER NOT NULL REFERENCES ticket_types(id),
                code            TEXT NOT NULL UNIQUE,      -- what the QR code holds
                checked_in_at   TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_tickets_order ON tickets(order_id);

            CREATE TABLE IF NOT EXISTS hits (
                key         TEXT NOT NULL,
                created_at  INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, created_at);
        SQL);
    }
}
