<?php
declare(strict_types=1);

final class Users
{
    public function __construct(private PDO $db) {}

    public function find(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $s->execute([ltrim($username, '@')]);
        return $s->fetch() ?: null;
    }

    public function findByLogin(string $login): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
        $s->execute([$login, ltrim($login, '@')]);
        return $s->fetch() ?: null;
    }

    public function exists(string $column, string $value): bool
    {
        if (!in_array($column, ['username', 'email'], true)) {
            throw new InvalidArgumentException('Bad column');
        }
        $s = $this->db->prepare("SELECT 1 FROM users WHERE $column = ?");
        $s->execute([$value]);
        return (bool) $s->fetchColumn();
    }

    public function create(string $username, string $email, string $password, string $name): int
    {
        $s = $this->db->prepare('INSERT INTO users (username, email, password_hash, name) VALUES (?, ?, ?, ?)');
        $s->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $name]);
        return (int) $this->db->lastInsertId();
    }

    public function updateProfile(int $id, string $name, string $bio): void
    {
        $s = $this->db->prepare('UPDATE users SET name = ?, bio = ? WHERE id = ?');
        $s->execute([$name, $bio, $id]);
    }
}
