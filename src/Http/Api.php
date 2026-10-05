<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Http;

use Bitenxt\Studio\Service\CustomerMessaging;
use Bitenxt\Studio\Service\ServiceError;
use Bitenxt\Studio\Service\StudioMessaging;

/**
 * Routes:
 *
 *   Pro customers (Bearer = the customer's Magento token, as used by the chatbot)
 *     GET  /api/orders/{order}/messages           the order's thread (marks studio replies read)
 *     POST /api/orders/{order}/messages           text and/or files[] (multipart) or JSON {"text"}
 *     GET  /api/attachments/{id}                  download a file from one of their threads
 *     GET  /api/unread                            orders with unread studio replies
 *
 *   Studio users (Bearer = token from /studio/api/login)
 *     POST /studio/api/login                      {"email","password"}
 *     POST /studio/api/logout
 *     GET  /studio/api/me
 *     GET  /studio/api/threads?status=open|closed|all
 *     GET  /studio/api/threads/{order}            messages (marks customer messages read)
 *     POST /studio/api/threads/{order}/messages   reply: text and/or files[]
 *     POST /studio/api/threads/{order}/status     {"status":"open"|"closed"}
 *     POST /studio/api/threads/{order}/assign     {"user_id": 3 | null}   (admins)
 *     GET  /studio/api/users                                              (admins)
 *     POST /studio/api/users                      {"email","name","role":"admin"|"designer","password"} (admins)
 *     POST /studio/api/users/{id}/deactivate                              (admins)
 *     GET  /studio/api/attachments/{id}
 */
final class Api
{
    private const ORDER = '([A-Za-z0-9-]{1,32})';
    private const STUDIO_TOKEN = '[a-f0-9]{64}';

    public function __construct(
        private readonly CustomerMessaging $customers,
        private readonly StudioMessaging $studio,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (ServiceError $e) {
            return Response::error($e->status, $e->error, $e->getMessage());
        }
    }

    private function route(Request $r): Response
    {
        $m = [];
        $is = static function (string $method, string $pattern) use ($r, &$m): bool {
            return $r->method === $method && preg_match('#^' . $pattern . '$#', $r->path, $m) === 1;
        };

        // --- customers ---
        if ($is('GET', '/api/orders/' . self::ORDER . '/messages')) {
            return Response::json(200, $this->customers->thread($r->bearer(), $m[1]));
        }
        if ($is('POST', '/api/orders/' . self::ORDER . '/messages')) {
            return Response::json(201, $this->customers->send($r->bearer(), $m[1], $r->string('text'), $r->files));
        }
        if ($is('GET', '/api/attachments/(\d{1,12})')) {
            $file = $this->customers->download($r->bearer(), (int) $m[1]);

            return Response::file($file['path'], $file['name']);
        }
        if ($is('GET', '/api/unread')) {
            return Response::json(200, $this->customers->unread($r->bearer()));
        }

        // --- studio ---
        if ($is('POST', '/studio/api/login')) {
            return Response::json(200, $this->studio->login($r->string('email'), $r->string('password'), $r->clientIp));
        }
        if (!str_starts_with($r->path, '/studio/api/')) {
            return Response::error(404, 'not_found');
        }
        $token = $r->bearer(self::STUDIO_TOKEN);
        $user = $this->studio->authenticate($token);

        if ($is('POST', '/studio/api/logout')) {
            $this->studio->logout((string) $token);

            return Response::json(200, ['ok' => true]);
        }
        if ($is('GET', '/studio/api/me')) {
            return Response::json(200, $this->studio->me($user));
        }
        if ($is('GET', '/studio/api/threads')) {
            return Response::json(200, $this->studio->inbox($user, $r->query['status'] ?? 'open'));
        }
        if ($is('GET', '/studio/api/threads/' . self::ORDER)) {
            return Response::json(200, $this->studio->thread($user, $m[1]));
        }
        if ($is('POST', '/studio/api/threads/' . self::ORDER . '/messages')) {
            return Response::json(201, $this->studio->reply($user, $m[1], $r->string('text'), $r->files));
        }
        if ($is('POST', '/studio/api/threads/' . self::ORDER . '/status')) {
            return Response::json(200, $this->studio->setStatus($user, $m[1], $r->string('status')));
        }
        if ($is('POST', '/studio/api/threads/' . self::ORDER . '/assign')) {
            $userId = $r->body['user_id'] ?? null;

            return Response::json(200, $this->studio->assign($user, $m[1], is_numeric($userId) ? (int) $userId : null));
        }
        if ($is('GET', '/studio/api/users')) {
            return Response::json(200, $this->studio->users($user));
        }
        if ($is('POST', '/studio/api/users')) {
            return Response::json(201, $this->studio->createUser($user, $r->string('email'), $r->string('name'), $r->string('role'), $r->string('password')));
        }
        if ($is('POST', '/studio/api/users/(\\d{1,12})/deactivate')) {
            return Response::json(200, $this->studio->setUserActive($user, (int) $m[1], false));
        }
        if ($is('GET', '/studio/api/attachments/(\d{1,12})')) {
            $file = $this->studio->download($user, (int) $m[1]);

            return Response::file($file['path'], $file['name']);
        }

        return Response::error(404, 'not_found');
    }
}
