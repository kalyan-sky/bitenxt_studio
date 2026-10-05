<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Store;

/**
 * Studio staff. Admins see every order thread and assign orders to
 * designers; designers see only the threads assigned to them.
 */
final class StudioUserRepository
{
    public const ADMIN = 'admin';
    public const DESIGNER = 'designer';

    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $email, string $name, string $role, string $password): int
    {
        if (!in_array($role, [self::ADMIN, self::DESIGNER], true)) {
            throw new \InvalidArgumentException('role must be admin or designer');
        }
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('password must be at least 10 characters');
        }
        $this->db->run(
            'INSERT INTO studio_users (email, name, role, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
            [mb_strtolower(trim($email)), trim($name), $role, password_hash($password, PASSWORD_DEFAULT), time()],
        );

        return $this->db->lastId();
    }

    public function setPassword(string $email, string $password): bool
    {
        return $this->db->run(
            'UPDATE studio_users SET password_hash = ? WHERE email = ?',
            [password_hash($password, PASSWORD_DEFAULT), mb_strtolower(trim($email))],
        )->rowCount() > 0;
    }

    public function setActive(string $email, bool $active): bool
    {
        $changed = $this->db->run('UPDATE studio_users SET active = ? WHERE email = ?', [$active ? 1 : 0, mb_strtolower(trim($email))])->rowCount() > 0;
        if (!$active) {
            $this->db->run('DELETE FROM studio_sessions WHERE user_id IN (SELECT id FROM studio_users WHERE email = ?)', [mb_strtolower(trim($email))]);
        }

        return $changed;
    }

    public function count(): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM studio_users')['n'] ?? 0);
    }

    public function byEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM studio_users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public function byId(int $id): ?array
    {
        return $this->db->one('SELECT * FROM studio_users WHERE id = ?', [$id]);
    }

    /** @return list<array{id: int, name: string, email: string, role: string}> */
    public function activeUsers(): array
    {
        return array_map(
            static fn (array $u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']],
            $this->db->all('SELECT * FROM studio_users WHERE active = 1 ORDER BY role, name'),
        );
    }

    /** Starts a session and returns the raw bearer token (only its hash is stored). */
    public function startSession(int $userId, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run('DELETE FROM studio_sessions WHERE expires_at < ?', [time()]);
        $this->db->run('INSERT INTO studio_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)', [hash('sha256', $token), $userId, time() + $ttlSeconds]);

        return $token;
    }

    public function userForToken(string $token): ?array
    {
        return $this->db->one(
            'SELECT u.* FROM studio_sessions s JOIN studio_users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1',
            [hash('sha256', $token), time()],
        );
    }

    public function endSession(string $token): void
    {
        $this->db->run('DELETE FROM studio_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    public function recentLoginFailures(string $key, int $windowSeconds): int
    {
        return (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM login_failures WHERE key_hash = ? AND at > ?',
            [hash('sha256', $key), time() - $windowSeconds],
        )['n'] ?? 0);
    }

    public function recordLoginFailure(string $key): void
    {
        $this->db->run('DELETE FROM login_failures WHERE at < ?', [time() - 86400]);
        $this->db->run('INSERT INTO login_failures (key_hash, at) VALUES (?, ?)', [hash('sha256', $key), time()]);
    }
}
