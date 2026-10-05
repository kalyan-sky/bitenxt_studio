<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Store;

/**
 * One conversation per order. Customer and studio messages (with their
 * attachments) live in the order's thread; unread counters are kept for each
 * side so the chatbot and the studio inbox can show badges.
 */
final class ThreadRepository
{
    public const CUSTOMER = 'customer';
    public const STUDIO = 'studio';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByOrder(string $orderNumber): ?array
    {
        return $this->db->one('SELECT * FROM threads WHERE order_number = ?', [$orderNumber]);
    }

    /** @param array{customer_id: string, email: string, firstname: string, lastname: string} $customer */
    public function findOrCreate(string $orderNumber, array $customer): array
    {
        $thread = $this->findByOrder($orderNumber);
        if ($thread !== null) {
            return $thread;
        }
        $now = time();
        try {
            $this->db->run(
                'INSERT INTO threads (order_number, customer_id, customer_email, customer_name, last_message_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$orderNumber, $customer['customer_id'], $customer['email'], trim($customer['firstname'] . ' ' . $customer['lastname']), $now, $now],
            );
        } catch (\PDOException) {
            // Created by a parallel request: fall through and read it.
        }

        return (array) $this->findByOrder($orderNumber);
    }

    /**
     * Adds a message (and its already-stored attachment files) and bumps the
     * other side's unread counter.
     *
     * @param list<array{original_name: string, stored_name: string, size_bytes: int, sha256: string}> $files
     */
    public function addMessage(array $thread, string $senderType, string $senderId, string $senderName, string $body, array $files): array
    {
        return $this->db->transaction(function () use ($thread, $senderType, $senderId, $senderName, $body, $files) {
            $now = time();
            $this->db->run(
                'INSERT INTO messages (thread_id, sender_type, sender_id, sender_name, body, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$thread['id'], $senderType, $senderId, $senderName, $body, $now],
            );
            $messageId = $this->db->lastId();
            foreach ($files as $file) {
                $this->db->run(
                    'INSERT INTO attachments (message_id, thread_id, original_name, stored_name, size_bytes, sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$messageId, $thread['id'], $file['original_name'], $file['stored_name'], $file['size_bytes'], $file['sha256'], $now],
                );
            }
            $counter = $senderType === self::CUSTOMER ? 'studio_unread' : 'customer_unread';
            // A new customer message re-opens a closed thread so it shows up in the inbox again.
            $reopen = $senderType === self::CUSTOMER ? ", status = 'open'" : '';
            $this->db->run(
                "UPDATE threads SET $counter = $counter + 1, last_sender = ?, last_message_at = ?$reopen WHERE id = ?",
                [$senderType, $now, $thread['id']],
            );

            return $this->message($messageId);
        });
    }

    public function message(int $id): array
    {
        $message = (array) $this->db->one('SELECT * FROM messages WHERE id = ?', [$id]);
        $message['attachments'] = $this->db->all('SELECT * FROM attachments WHERE message_id = ? ORDER BY id', [$id]);

        return $message;
    }

    /** @return list<array<string, mixed>> oldest first, each with its attachments */
    public function messages(int $threadId): array
    {
        $messages = $this->db->all('SELECT * FROM messages WHERE thread_id = ? ORDER BY id', [$threadId]);
        $byMessage = [];
        foreach ($this->db->all('SELECT * FROM attachments WHERE thread_id = ? ORDER BY id', [$threadId]) as $attachment) {
            $byMessage[$attachment['message_id']][] = $attachment;
        }
        foreach ($messages as &$message) {
            $message['attachments'] = $byMessage[$message['id']] ?? [];
        }

        return $messages;
    }

    public function attachment(int $id): ?array
    {
        return $this->db->one(
            'SELECT a.*, t.order_number, t.customer_id, t.assigned_to FROM attachments a JOIN threads t ON t.id = a.thread_id WHERE a.id = ?',
            [$id],
        );
    }

    public function markRead(int $threadId, string $reader): void
    {
        $counter = $reader === self::CUSTOMER ? 'customer_unread' : 'studio_unread';
        $this->db->run("UPDATE threads SET $counter = 0 WHERE id = ?", [$threadId]);
    }

    /** @return list<array<string, mixed>> the customer's threads that have unread studio replies */
    public function customerUnread(string $customerId): array
    {
        return $this->db->all(
            'SELECT order_number, customer_unread, last_message_at FROM threads WHERE customer_id = ? AND customer_unread > 0 ORDER BY last_message_at DESC',
            [$customerId],
        );
    }

    /**
     * Studio inbox, newest activity first. A designer only sees the orders
     * assigned to them; pass null to see everything (admins).
     *
     * @return list<array<string, mixed>>
     */
    public function inbox(?int $assignedTo, string $status, int $limit = 100): array
    {
        $where = [];
        $params = [];
        if ($assignedTo !== null) {
            $where[] = 't.assigned_to = ?';
            $params[] = $assignedTo;
        }
        if (in_array($status, ['open', 'closed'], true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT t.*, u.name AS assigned_name FROM threads t LEFT JOIN studio_users u ON u.id = t.assigned_to'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY t.studio_unread > 0 DESC, t.last_message_at DESC LIMIT ' . max(1, min(500, $limit));

        return $this->db->all($sql, $params);
    }

    public function setStatus(int $threadId, string $status): void
    {
        $this->db->run('UPDATE threads SET status = ? WHERE id = ?', [$status, $threadId]);
    }

    public function assign(int $threadId, ?int $userId): void
    {
        $this->db->run('UPDATE threads SET assigned_to = ? WHERE id = ?', [$userId, $threadId]);
    }

    public function customerMessagesSince(string $customerId, int $since): int
    {
        return (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM messages m JOIN threads t ON t.id = m.thread_id WHERE t.customer_id = ? AND m.sender_type = ? AND m.created_at >= ?',
            [$customerId, self::CUSTOMER, $since],
        )['n'] ?? 0);
    }
}
