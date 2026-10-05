<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Service;

use Bitenxt\Studio\Files\AttachmentStorage;
use Bitenxt\Studio\Store\StudioUserRepository;
use Bitenxt\Studio\Store\ThreadRepository;

/**
 * The studio's side: sign in, see the inbox of order threads, read customer
 * messages and attachments, reply, close threads and (admins) assign orders
 * to designers. Admins see every thread; designers only those assigned to them.
 */
final class StudioMessaging
{
    private const MAX_FAILURES_PER_EMAIL = 8;
    private const MAX_FAILURES_PER_IP = 30;
    private const FAILURE_WINDOW = 900;

    public function __construct(
        private readonly StudioUserRepository $users,
        private readonly ThreadRepository $threads,
        private readonly AttachmentStorage $storage,
        private readonly int $maxMessageChars,
        private readonly int $sessionSeconds,
    ) {
    }

    /** @return array{token: string, expires_in: int, user: array<string, mixed>} */
    public function login(string $email, string $password, string $clientIp): array
    {
        $emailKey = 'email:' . mb_strtolower(trim($email));
        $ipKey = 'ip:' . $clientIp;
        if ($this->users->recentLoginFailures($emailKey, self::FAILURE_WINDOW) >= self::MAX_FAILURES_PER_EMAIL
            || $this->users->recentLoginFailures($ipKey, self::FAILURE_WINDOW) >= self::MAX_FAILURES_PER_IP) {
            throw new ServiceError(429, 'too_many_attempts', 'Too many failed sign-ins. Try again in 15 minutes.');
        }
        $user = $this->users->byEmail($email);
        // Verify against a dummy hash when the user is unknown so timing does not reveal valid emails.
        $hash = $user['password_hash'] ?? '$2y$10$wY40qY1EMHqcghGFka6yqOhP77EwjIPMtRbrAXE96sjPW5I8KcVne';
        if (!password_verify($password, $hash) || $user === null || (int) $user['active'] !== 1) {
            $this->users->recordLoginFailure($emailKey);
            $this->users->recordLoginFailure($ipKey);
            throw new ServiceError(401, 'invalid_login', 'Wrong email or password.');
        }

        return [
            'token' => $this->users->startSession((int) $user['id'], $this->sessionSeconds),
            'expires_in' => $this->sessionSeconds,
            'user' => self::publicUser($user),
        ];
    }

    public function logout(string $token): void
    {
        $this->users->endSession($token);
    }

    public function authenticate(?string $token): array
    {
        $user = $token === null ? null : $this->users->userForToken($token);
        if ($user === null) {
            throw new ServiceError(401, 'login_required', 'Please sign in.');
        }

        return $user;
    }

    public function me(array $user): array
    {
        return ['user' => self::publicUser($user)];
    }

    public function inbox(array $user, string $status): array
    {
        $threads = $this->threads->inbox(self::isAdmin($user) ? null : (int) $user['id'], $status);

        return [
            'threads' => array_map(MessagePresenter::thread(...), $threads),
            'unread_total' => array_sum(array_map(static fn ($t) => (int) $t['studio_unread'], $threads)),
        ];
    }

    public function thread(array $user, string $orderNumber): array
    {
        $thread = $this->accessibleThread($user, $orderNumber);
        $this->threads->markRead((int) $thread['id'], ThreadRepository::STUDIO);
        $thread = $this->threadWithAssignee($orderNumber);

        return [
            'thread' => MessagePresenter::thread($thread),
            'messages' => array_map(MessagePresenter::forStudio(...), $this->threads->messages((int) $thread['id'])),
        ];
    }

    /** @param list<array{name: string, tmp: string, size: int, error: int}> $files */
    public function reply(array $user, string $orderNumber, string $text, array $files): array
    {
        $text = CustomerMessaging::cleanText($text);
        if ($text === '' && $files === []) {
            throw new ServiceError(400, 'empty_message', 'Write a message or attach a file.');
        }
        if (mb_strlen($text) > $this->maxMessageChars) {
            throw new ServiceError(400, 'message_too_long', "Messages can be up to {$this->maxMessageChars} characters.");
        }
        if (($problem = $this->storage->validate($files)) !== null) {
            throw new ServiceError(400, 'invalid_attachment', $problem);
        }
        $thread = $this->accessibleThread($user, $orderNumber);
        $stored = $this->storage->store((string) $thread['order_number'], $files);
        $message = $this->threads->addMessage($thread, ThreadRepository::STUDIO, (string) $user['id'], (string) $user['name'], $text, $stored);
        // Replying means the studio has read everything up to now.
        $this->threads->markRead((int) $thread['id'], ThreadRepository::STUDIO);

        return ['message' => MessagePresenter::forStudio($message)];
    }

    public function setStatus(array $user, string $orderNumber, string $status): array
    {
        if (!in_array($status, ['open', 'closed'], true)) {
            throw new ServiceError(400, 'invalid_status', 'Status must be open or closed.');
        }
        $thread = $this->accessibleThread($user, $orderNumber);
        $this->threads->setStatus((int) $thread['id'], $status);

        return ['thread' => MessagePresenter::thread($this->threadWithAssignee($orderNumber))];
    }

    public function assign(array $user, string $orderNumber, ?int $userId): array
    {
        if (!self::isAdmin($user)) {
            throw new ServiceError(403, 'admin_only', 'Only studio admins can assign orders.');
        }
        $thread = $this->accessibleThread($user, $orderNumber);
        if ($userId !== null) {
            $target = $this->users->byId($userId);
            if ($target === null || (int) $target['active'] !== 1) {
                throw new ServiceError(400, 'invalid_user', 'That studio user does not exist or is inactive.');
            }
        }
        $this->threads->assign((int) $thread['id'], $userId);

        return ['thread' => MessagePresenter::thread($this->threadWithAssignee($orderNumber))];
    }

    public function users(array $user): array
    {
        if (!self::isAdmin($user)) {
            throw new ServiceError(403, 'admin_only', 'Only studio admins can list users.');
        }

        return ['users' => $this->users->activeUsers()];
    }

    public function createUser(array $user, string $email, string $name, string $role, string $password): array
    {
        if (!self::isAdmin($user)) {
            throw new ServiceError(403, 'admin_only', 'Only studio admins can add users.');
        }
        if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL) || trim($name) === '') {
            throw new ServiceError(400, 'invalid_user', 'Enter a name and a valid email.');
        }
        if ($this->users->byEmail($email) !== null) {
            throw new ServiceError(409, 'user_exists', 'A studio user with that email already exists.');
        }
        try {
            $this->users->create($email, mb_substr($name, 0, 100), $role, $password);
        } catch (\InvalidArgumentException $e) {
            throw new ServiceError(400, 'invalid_user', ucfirst($e->getMessage()) . '.');
        }

        return $this->users($user);
    }

    public function setUserActive(array $user, int $userId, bool $active): array
    {
        if (!self::isAdmin($user)) {
            throw new ServiceError(403, 'admin_only', 'Only studio admins can change users.');
        }
        $target = $this->users->byId($userId);
        if ($target === null) {
            throw new ServiceError(404, 'not_found', 'No such studio user.');
        }
        if ((int) $target['id'] === (int) $user['id'] && !$active) {
            throw new ServiceError(400, 'cannot_disable_self', 'You cannot deactivate your own account.');
        }
        $this->users->setActive((string) $target['email'], $active);

        return $this->users($user);
    }

    /** @return array{path: string, name: string} */
    public function download(array $user, int $attachmentId): array
    {
        $attachment = $this->threads->attachment($attachmentId);
        $allowed = $attachment !== null
            && (self::isAdmin($user) || (int) ($attachment['assigned_to'] ?? 0) === (int) $user['id']);
        if (!$allowed || ($path = $this->storage->path((string) $attachment['stored_name'])) === null) {
            throw new ServiceError(404, 'not_found', 'That file is not available.');
        }

        return ['path' => $path, 'name' => (string) $attachment['original_name']];
    }

    private function accessibleThread(array $user, string $orderNumber): array
    {
        $thread = $this->threads->findByOrder(trim($orderNumber));
        if ($thread === null || (!self::isAdmin($user) && (int) ($thread['assigned_to'] ?? 0) !== (int) $user['id'])) {
            throw new ServiceError(404, 'not_found', 'No conversation for that order.');
        }

        return $thread;
    }

    private function threadWithAssignee(string $orderNumber): array
    {
        $thread = (array) $this->threads->findByOrder(trim($orderNumber));
        $assignee = $thread['assigned_to'] !== null ? $this->users->byId((int) $thread['assigned_to']) : null;
        $thread['assigned_name'] = $assignee['name'] ?? null;

        return $thread;
    }

    private static function isAdmin(array $user): bool
    {
        return $user['role'] === StudioUserRepository::ADMIN;
    }

    private static function publicUser(array $user): array
    {
        return ['id' => (int) $user['id'], 'name' => (string) $user['name'], 'email' => (string) $user['email'], 'role' => (string) $user['role']];
    }
}
