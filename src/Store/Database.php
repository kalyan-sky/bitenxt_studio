<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Store;

/**
 * PDO connection plus the schema. Works on SQLite (local / single instance)
 * and MySQL (production; Magento's server or Cloud SQL). Tables are created
 * on first use, so there is no separate migration step.
 */
final class Database
{
    public readonly \PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $password = '')
    {
        if (str_starts_with($dsn, 'sqlite:') && ($file = substr($dsn, 7)) !== ':memory:' && !is_dir(dirname($file))) {
            mkdir(dirname($file), 0770, true);
        }
        $this->pdo = new \PDO($dsn, $user ?: null, $password ?: null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
        $this->migrate();
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /** @param array<string|int, mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string|int, mixed> $params @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T @param callable(): T $fn @return T */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $id = $this->driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $suffix = $this->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $tables = [
            "CREATE TABLE IF NOT EXISTS studio_users (
                id $id,
                email $text NOT NULL UNIQUE,
                name $text NOT NULL,
                role $text NOT NULL,
                password_hash $text NOT NULL,
                active INT NOT NULL DEFAULT 1,
                created_at INT NOT NULL
            )$suffix",
            "CREATE TABLE IF NOT EXISTS studio_sessions (
                token_hash CHAR(64) PRIMARY KEY,
                user_id INT NOT NULL,
                expires_at INT NOT NULL
            )$suffix",
            "CREATE TABLE IF NOT EXISTS login_failures (
                id $id,
                key_hash CHAR(64) NOT NULL,
                at INT NOT NULL
            )$suffix",
            "CREATE TABLE IF NOT EXISTS threads (
                id $id,
                order_number $text NOT NULL UNIQUE,
                customer_id $text NOT NULL,
                customer_email $text NOT NULL,
                customer_name $text NOT NULL,
                status $text NOT NULL DEFAULT 'open',
                assigned_to INT NULL,
                studio_unread INT NOT NULL DEFAULT 0,
                customer_unread INT NOT NULL DEFAULT 0,
                last_sender $text NOT NULL DEFAULT 'customer',
                last_message_at INT NOT NULL,
                created_at INT NOT NULL
            )$suffix",
            "CREATE TABLE IF NOT EXISTS messages (
                id $id,
                thread_id INT NOT NULL,
                sender_type $text NOT NULL,
                sender_id $text NOT NULL,
                sender_name $text NOT NULL,
                body TEXT NOT NULL,
                created_at INT NOT NULL
            )$suffix",
            "CREATE TABLE IF NOT EXISTS attachments (
                id $id,
                message_id INT NOT NULL,
                thread_id INT NOT NULL,
                original_name $text NOT NULL,
                stored_name $text NOT NULL,
                size_bytes INT NOT NULL,
                sha256 CHAR(64) NOT NULL,
                created_at INT NOT NULL
            )$suffix",
        ];
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        foreach ([
            'CREATE INDEX idx_threads_customer ON threads (customer_id)',
            'CREATE INDEX idx_threads_last ON threads (last_message_at)',
            'CREATE INDEX idx_messages_thread ON messages (thread_id)',
            'CREATE INDEX idx_attachments_message ON attachments (message_id)',
            'CREATE INDEX idx_login_failures_key ON login_failures (key_hash)',
        ] as $sql) {
            try {
                $this->pdo->exec(str_replace('CREATE INDEX', $this->driver() === 'mysql' ? 'CREATE INDEX' : 'CREATE INDEX IF NOT EXISTS', $sql));
            } catch (\PDOException) {
                // MySQL has no IF NOT EXISTS for indexes: it already exists.
            }
        }
    }
}
