<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Service;

use Bitenxt\Studio\Files\AttachmentStorage;
use Bitenxt\Studio\Magento\CustomerDirectory;
use Bitenxt\Studio\Magento\MagentoAuthException;
use Bitenxt\Studio\Magento\MagentoException;
use Bitenxt\Studio\Notify\StudioNotifier;
use Bitenxt\Studio\Store\ThreadRepository;

/**
 * The Pro customer's side: read the order's thread, write to the studio and
 * upload files. Identity always comes from the customer's Magento token and
 * every order is checked against Magento before its thread is touched, so a
 * customer can only reach threads of their own orders.
 */
final class CustomerMessaging
{
    private const ORDER_NUMBER = '/^[A-Za-z0-9-]{3,32}$/';

    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly ThreadRepository $threads,
        private readonly AttachmentStorage $storage,
        private readonly StudioNotifier $notifier,
        private readonly int $maxMessageChars,
        private readonly int $messagesPerMinute,
    ) {
    }

    /** @return array{order_number: string, messages: list<array<string, mixed>>} */
    public function thread(?string $token, string $orderNumber): array
    {
        [$customer, $order] = $this->ownOrder($token, $orderNumber);
        $thread = $this->ownThread($order['number'], $customer);
        if ($thread === null) {
            return ['order_number' => $order['number'], 'messages' => []];
        }
        $this->threads->markRead((int) $thread['id'], ThreadRepository::CUSTOMER);

        return [
            'order_number' => $order['number'],
            'messages' => array_map(MessagePresenter::forCustomer(...), $this->threads->messages((int) $thread['id'])),
        ];
    }

    /**
     * @param list<array{name: string, tmp: string, size: int, error: int}> $files
     * @return array{order_number: string, message: array<string, mixed>}
     */
    public function send(?string $token, string $orderNumber, string $text, array $files): array
    {
        $text = self::cleanText($text);
        if ($text === '' && $files === []) {
            throw new ServiceError(400, 'empty_message', 'Write a message or attach a file.');
        }
        if (mb_strlen($text) > $this->maxMessageChars) {
            throw new ServiceError(400, 'message_too_long', "Messages can be up to {$this->maxMessageChars} characters.");
        }
        if (($problem = $this->storage->validate($files)) !== null) {
            throw new ServiceError(400, 'invalid_attachment', $problem);
        }
        [$customer, $order] = $this->ownOrder($token, $orderNumber);
        if ($this->threads->customerMessagesSince($customer['customer_id'], time() - 60) >= $this->messagesPerMinute) {
            throw new ServiceError(429, 'rate_limited', 'You are sending messages too quickly. Please wait a minute.');
        }
        $thread = $this->ownThread($order['number'], $customer) ?? $this->threads->findOrCreate($order['number'], $customer);
        if ((string) $thread['customer_id'] !== $customer['customer_id']) {
            throw new ServiceError(404, 'order_not_found', 'No order with that number was found on your account.');
        }

        $stored = $this->storage->store($order['number'], $files);
        $name = trim($customer['firstname'] . ' ' . $customer['lastname']) ?: $customer['email'];
        $message = $this->threads->addMessage($thread, ThreadRepository::CUSTOMER, $customer['customer_id'], $name, $text, $stored);
        // The customer has obviously seen the thread they are writing in.
        $this->threads->markRead((int) $thread['id'], ThreadRepository::CUSTOMER);
        $this->notifier->customerMessage($this->threads->findByOrder($order['number']) ?? $thread, $message);

        return ['order_number' => $order['number'], 'message' => MessagePresenter::forCustomer($message)];
    }

    /** @return array{path: string, name: string} */
    public function download(?string $token, int $attachmentId): array
    {
        $customer = $this->customer($token);
        $attachment = $this->threads->attachment($attachmentId);
        if ($attachment === null || (string) $attachment['customer_id'] !== $customer['customer_id']
            || ($path = $this->storage->path((string) $attachment['stored_name'])) === null) {
            throw new ServiceError(404, 'not_found', 'That file is not available.');
        }

        return ['path' => $path, 'name' => (string) $attachment['original_name']];
    }

    /** Orders with studio replies the customer has not read yet (for a badge in the chatbot). */
    public function unread(?string $token): array
    {
        $customer = $this->customer($token);
        $orders = array_map(static fn (array $t) => [
            'order_number' => (string) $t['order_number'],
            'unread' => (int) $t['customer_unread'],
            'last_message_at' => (int) $t['last_message_at'],
        ], $this->threads->customerUnread($customer['customer_id']));

        return ['total' => array_sum(array_column($orders, 'unread')), 'orders' => $orders];
    }

    /** @return array{customer_id: string, email: string, firstname: string, lastname: string} */
    private function customer(?string $token): array
    {
        if ($token === null || $token === '') {
            throw new ServiceError(401, 'login_required', 'Please log in to your BiteNXT Pro account.');
        }
        try {
            return $this->directory->currentCustomer($token);
        } catch (MagentoAuthException) {
            throw new ServiceError(401, 'login_required', 'Please log in to your BiteNXT Pro account.');
        } catch (MagentoException) {
            throw new ServiceError(503, 'unavailable', "We can't verify your account right now. Please try again in a few minutes.");
        }
    }

    /** @return array{0: array<string, string>, 1: array{number: string, status: string}} */
    private function ownOrder(?string $token, string $orderNumber): array
    {
        $orderNumber = trim($orderNumber);
        if (!preg_match(self::ORDER_NUMBER, $orderNumber)) {
            throw new ServiceError(400, 'invalid_order_number', 'Please check the order number.');
        }
        $customer = $this->customer($token);
        try {
            $order = $this->directory->findOwnOrder((string) $token, $orderNumber);
        } catch (MagentoAuthException) {
            throw new ServiceError(401, 'login_required', 'Please log in to your BiteNXT Pro account.');
        } catch (MagentoException) {
            throw new ServiceError(503, 'unavailable', "We can't load your order right now. Please try again in a few minutes.");
        }
        if ($order === null) {
            // Same answer whether the order is missing or someone else's.
            throw new ServiceError(404, 'order_not_found', 'No order with that number was found on your account.');
        }

        return [$customer, $order];
    }

    /** The order's thread if it exists and belongs to this customer. */
    private function ownThread(string $orderNumber, array $customer): ?array
    {
        $thread = $this->threads->findByOrder($orderNumber);
        if ($thread !== null && (string) $thread['customer_id'] !== $customer['customer_id']) {
            throw new ServiceError(404, 'order_not_found', 'No order with that number was found on your account.');
        }

        return $thread;
    }

    public static function cleanText(string $text): string
    {
        // Drop control and invisible characters except newlines and tabs.
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $text));

        return trim($text);
    }
}
