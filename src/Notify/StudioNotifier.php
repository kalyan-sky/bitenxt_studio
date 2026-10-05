<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Notify;

/**
 * Tells the studio team that a customer wrote or uploaded files on an order.
 * Always written to the event log; also POSTed to a webhook (Slack, Teams,
 * Google Chat) when STUDIO_WEBHOOK_URL is set.
 */
class StudioNotifier
{
    public function __construct(
        private readonly string $logFile,
        private readonly string $webhookUrl = '',
        private readonly string $studioUrl = '',
    ) {
    }

    /** @param array<string, mixed> $message as stored, with its attachments */
    public function customerMessage(array $thread, array $message): void
    {
        $files = array_column($message['attachments'] ?? [], 'original_name');
        $this->log([
            'event' => 'customer_message',
            'order' => $thread['order_number'],
            'message_id' => $message['id'],
            'files' => count($files),
            'assigned_to' => $thread['assigned_to'],
        ]);
        if ($this->webhookUrl === '') {
            return;
        }

        $lines = [sprintf('New message on order %s from %s (%s)', $thread['order_number'], $thread['customer_name'] ?: 'customer', $thread['customer_email'])];
        if (trim((string) $message['body']) !== '') {
            $lines[] = '> ' . mb_substr(str_replace("\n", ' ', (string) $message['body']), 0, 300);
        }
        if ($files !== []) {
            $lines[] = 'Attachments: ' . implode(', ', $files);
        }
        if (!empty($thread['assigned_name'])) {
            $lines[] = 'Assigned to: ' . $thread['assigned_name'];
        }
        if ($this->studioUrl !== '') {
            $lines[] = 'Open: ' . $this->studioUrl . '/studio.html#order=' . rawurlencode((string) $thread['order_number']);
        }
        $this->post(implode("\n", $lines));
    }

    /** @param array<string, mixed> $fields */
    protected function log(array $fields): void
    {
        $line = json_encode(['severity' => 'INFO', 'ts' => date(DATE_ATOM)] + $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($this->logFile === 'php://stderr') {
            file_put_contents('php://stderr', $line . "\n");

            return;
        }
        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0770, true);
        }
        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    protected function post(string $text): void
    {
        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text' => $text]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        if (!$ok) {
            $this->log(['event' => 'webhook_failed', 'severity' => 'WARNING']);
        }
    }
}
