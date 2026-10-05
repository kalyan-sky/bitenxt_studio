<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Service;

/** Shapes stored rows for the API: no storage paths, hashes or internal ids beyond what clients need. */
final class MessagePresenter
{
    /** @param array<string, mixed> $message */
    public static function forCustomer(array $message): array
    {
        $fromStudio = $message['sender_type'] === 'studio';

        return [
            'id' => (int) $message['id'],
            'from' => $fromStudio ? 'studio' : 'you',
            // Staff are shown by first name only.
            'name' => $fromStudio ? trim('BiteNXT Studio · ' . strtok((string) $message['sender_name'], ' ')) : 'You',
            'text' => (string) $message['body'],
            'at' => (int) $message['created_at'],
            'attachments' => array_map(self::attachment(...), $message['attachments'] ?? []),
        ];
    }

    /** @param array<string, mixed> $message */
    public static function forStudio(array $message): array
    {
        return [
            'id' => (int) $message['id'],
            'from' => $message['sender_type'],
            'name' => (string) $message['sender_name'],
            'text' => (string) $message['body'],
            'at' => (int) $message['created_at'],
            'attachments' => array_map(self::attachment(...), $message['attachments'] ?? []),
        ];
    }

    /** @param array<string, mixed> $thread */
    public static function thread(array $thread): array
    {
        return [
            'order_number' => (string) $thread['order_number'],
            'customer_name' => (string) $thread['customer_name'],
            'customer_email' => (string) $thread['customer_email'],
            'status' => (string) $thread['status'],
            'assigned_to' => $thread['assigned_to'] === null ? null : (int) $thread['assigned_to'],
            'assigned_name' => $thread['assigned_name'] ?? null,
            'unread' => (int) $thread['studio_unread'],
            'last_sender' => (string) $thread['last_sender'],
            'last_message_at' => (int) $thread['last_message_at'],
        ];
    }

    /** @param array<string, mixed> $attachment */
    private static function attachment(array $attachment): array
    {
        return ['id' => (int) $attachment['id'], 'name' => (string) $attachment['original_name'], 'size' => (int) $attachment['size_bytes']];
    }
}
