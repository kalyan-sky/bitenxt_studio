<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Tests;

use Bitenxt\Studio\Files\AttachmentStorage;
use Bitenxt\Studio\Http\Api;
use Bitenxt\Studio\Http\Request;
use Bitenxt\Studio\Http\Response;
use Bitenxt\Studio\Notify\StudioNotifier;
use Bitenxt\Studio\Service\CustomerMessaging;
use Bitenxt\Studio\Service\StudioMessaging;
use Bitenxt\Studio\Store\Database;
use Bitenxt\Studio\Store\StudioUserRepository;
use Bitenxt\Studio\Store\ThreadRepository;
use PHPUnit\Framework\TestCase;

final class StudioMessagingTest extends TestCase
{
    private string $dir;
    private Api $api;
    private StudioUserRepository $users;
    /** @var list<array{0: array, 1: array}> */
    private array $notified = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bnx-studio-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $db = new Database('sqlite::memory:');
        $threads = new ThreadRepository($db);
        $this->users = new StudioUserRepository($db);
        $storage = new AttachmentStorage($this->dir . '/attachments', 1024 * 1024, 3, ['stl', 'pdf', 'jpg']);
        $notified = &$this->notified;
        $notifier = new class ($notified) extends StudioNotifier {
            public function __construct(private array &$sink)
            {
                parent::__construct('');
            }

            public function customerMessage(array $thread, array $message): void
            {
                $this->sink[] = [$thread, $message];
            }
        };
        $this->api = new Api(
            new CustomerMessaging(new FakeMagento(), $threads, $storage, $notifier, 4000, 5),
            new StudioMessaging($this->users, $threads, $storage, 4000, 3600),
        );
        $this->users->create('admin@studio.test', 'Priya Admin', 'admin', 'admin-password-1');
        $this->users->create('dana@studio.test', 'Dana Designer', 'designer', 'designer-password-1');
        $this->users->create('omar@studio.test', 'Omar Designer', 'designer', 'designer-password-2');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCustomerUploadReachesStudioAndReplyComesBack(): void
    {
        $res = $this->customer('POST', '/api/orders/101/messages', FakeMagento::CLINIC_A, ['text' => 'Updated scan attached'], [$this->file('scan.stl')]);
        self::assertSame(201, $res->status);
        self::assertSame('000000101', $res->body['order_number'], 'short order numbers resolve to the real one');
        self::assertCount(1, $this->notified, 'studio is notified');
        self::assertSame('scan.stl', $this->notified[0][1]['attachments'][0]['original_name']);

        $admin = $this->login('admin@studio.test', 'admin-password-1');
        $inbox = $this->studio('GET', '/studio/api/threads', $admin);
        self::assertSame(1, $inbox->body['unread_total']);
        self::assertSame('000000101', $inbox->body['threads'][0]['order_number']);

        $thread = $this->studio('GET', '/studio/api/threads/000000101', $admin);
        self::assertSame('Updated scan attached', $thread->body['messages'][0]['text']);
        $attachmentId = $thread->body['messages'][0]['attachments'][0]['id'];
        $file = $this->studio('GET', '/studio/api/attachments/' . $attachmentId, $admin);
        self::assertTrue($file->isFile());
        self::assertSame('solid scan', file_get_contents((string) $file->filePath));
        self::assertSame(0, $this->studio('GET', '/studio/api/threads', $admin)->body['unread_total'], 'opening marks read');

        $reply = $this->studio('POST', '/studio/api/threads/000000101/messages', $admin, ['text' => 'Thanks, received.'], [$this->file('notes.pdf')]);
        self::assertSame(201, $reply->status);

        self::assertSame(1, $this->customer('GET', '/api/unread', FakeMagento::CLINIC_A)->body['total']);
        $view = $this->customer('GET', '/api/orders/000000101/messages', FakeMagento::CLINIC_A);
        self::assertSame(['you', 'studio'], array_column($view->body['messages'], 'from'));
        self::assertSame('BiteNXT Studio · Priya', $view->body['messages'][1]['name'], 'staff shown by first name');
        self::assertSame(0, $this->customer('GET', '/api/unread', FakeMagento::CLINIC_A)->body['total']);
        $studioFile = $view->body['messages'][1]['attachments'][0]['id'];
        self::assertTrue($this->customer('GET', '/api/attachments/' . $studioFile, FakeMagento::CLINIC_A)->isFile());
    }

    public function testCustomerCannotReachAnotherClinicsOrderOrFiles(): void
    {
        $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => 'hi'], [$this->file('scan.stl')]);

        $read = $this->customer('GET', '/api/orders/000000101/messages', FakeMagento::CLINIC_B);
        self::assertSame(404, $read->status);
        $write = $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_B, ['text' => 'sneaky']);
        self::assertSame(404, $write->status);
        self::assertSame(404, $this->customer('GET', '/api/attachments/1', FakeMagento::CLINIC_B)->status);
        self::assertSame(401, $this->customer('GET', '/api/orders/000000101/messages', 'forged-token-xyz')->status);
        self::assertSame(401, $this->api->handle(new Request('GET', '/api/unread'))->status);
    }

    public function testDesignerSeesOnlyAssignedOrders(): void
    {
        $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => 'one']);
        $this->customer('POST', '/api/orders/000000201/messages', FakeMagento::CLINIC_B, ['text' => 'two'], [$this->file('photo.jpg')]);
        $admin = $this->login('admin@studio.test', 'admin-password-1');
        $dana = $this->login('dana@studio.test', 'designer-password-1');
        $omar = $this->login('omar@studio.test', 'designer-password-2');

        self::assertSame([], $this->studio('GET', '/studio/api/threads', $dana)->body['threads']);
        self::assertSame(403, $this->studio('POST', '/studio/api/threads/000000201/assign', $dana, ['user_id' => 2])->status);

        $assigned = $this->studio('POST', '/studio/api/threads/000000201/assign', $admin, ['user_id' => 2]);
        self::assertSame('Dana Designer', $assigned->body['thread']['assigned_name']);

        self::assertSame(['000000201'], array_column($this->studio('GET', '/studio/api/threads', $dana)->body['threads'], 'order_number'));
        self::assertSame(200, $this->studio('GET', '/studio/api/threads/000000201', $dana)->status);
        self::assertSame(404, $this->studio('GET', '/studio/api/threads/000000101', $dana)->status);
        self::assertSame(404, $this->studio('GET', '/studio/api/threads/000000201', $omar)->status);
        self::assertSame(404, $this->studio('GET', '/studio/api/attachments/1', $omar)->status);
        self::assertTrue($this->studio('GET', '/studio/api/attachments/1', $dana)->isFile());
        self::assertSame(201, $this->studio('POST', '/studio/api/threads/000000201/messages', $dana, ['text' => 'On it'])->status);
    }

    public function testClosedThreadReopensWhenCustomerWrites(): void
    {
        $this->customer('POST', '/api/orders/000000102/messages', FakeMagento::CLINIC_A, ['text' => 'question']);
        $admin = $this->login('admin@studio.test', 'admin-password-1');
        $this->studio('POST', '/studio/api/threads/000000102/status', $admin, ['status' => 'closed']);
        self::assertSame([], $this->studio('GET', '/studio/api/threads', $admin)->body['threads']);

        $this->customer('POST', '/api/orders/000000102/messages', FakeMagento::CLINIC_A, ['text' => 'one more thing']);
        self::assertSame('open', $this->studio('GET', '/studio/api/threads', $admin)->body['threads'][0]['status']);
    }

    public function testUploadValidation(): void
    {
        $exe = $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => ''], [$this->file('virus.exe')]);
        self::assertSame(400, $exe->status);
        self::assertSame('invalid_attachment', $exe->body['error']);

        $big = $this->file('big.stl', str_repeat('x', 1024 * 1024 + 1));
        self::assertSame(400, $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, [], [$big])->status);

        $many = [$this->file('1.stl'), $this->file('2.stl'), $this->file('3.stl'), $this->file('4.stl')];
        self::assertSame(400, $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, [], $many)->status);

        self::assertSame(400, $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => "  \u{200B} "])->status);
        self::assertSame([], $this->notified, 'nothing stored or sent for rejected messages');

        $traversal = $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, [], [$this->file('../../etc/scan.stl')]);
        self::assertSame(201, $traversal->status);
        self::assertSame('scan.stl', $traversal->body['message']['attachments'][0]['name']);
    }

    public function testCustomerRateLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(201, $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => "m$i"])->status);
        }
        self::assertSame(429, $this->customer('POST', '/api/orders/000000101/messages', FakeMagento::CLINIC_A, ['text' => 'too many'])->status);
    }

    public function testStudioLoginLockoutAndLogout(): void
    {
        for ($i = 0; $i < 8; $i++) {
            self::assertSame(401, $this->api->handle(new Request('POST', '/studio/api/login', body: ['email' => 'admin@studio.test', 'password' => 'wrong'], clientIp: '1.2.3.4'))->status);
        }
        $locked = $this->api->handle(new Request('POST', '/studio/api/login', body: ['email' => 'admin@studio.test', 'password' => 'admin-password-1'], clientIp: '1.2.3.4'));
        self::assertSame(429, $locked->status);

        $token = $this->login('dana@studio.test', 'designer-password-1');
        self::assertSame(200, $this->studio('GET', '/studio/api/me', $token)->status);
        $this->studio('POST', '/studio/api/logout', $token);
        self::assertSame(401, $this->studio('GET', '/studio/api/me', $token)->status);

        self::assertSame(401, $this->studio('GET', '/studio/api/threads', str_repeat('a', 64))->status);
        $this->users->setActive('dana@studio.test', false);
        self::assertSame(401, $this->api->handle(new Request('POST', '/studio/api/login', body: ['email' => 'dana@studio.test', 'password' => 'designer-password-1']))->status);
    }

    public function testAdminManagesTeam(): void
    {
        $admin = $this->login('admin@studio.test', 'admin-password-1');
        $dana = $this->login('dana@studio.test', 'designer-password-1');

        self::assertSame(403, $this->studio('POST', '/studio/api/users', $dana, ['email' => 'x@studio.test', 'name' => 'X', 'role' => 'admin', 'password' => 'long-enough-1'])->status);
        self::assertSame(400, $this->studio('POST', '/studio/api/users', $admin, ['email' => 'new@studio.test', 'name' => 'New', 'role' => 'designer', 'password' => 'short'])->status);
        self::assertSame(400, $this->studio('POST', '/studio/api/users', $admin, ['email' => 'new@studio.test', 'name' => 'New', 'role' => 'owner', 'password' => 'long-enough-1'])->status);
        self::assertSame(409, $this->studio('POST', '/studio/api/users', $admin, ['email' => 'DANA@studio.test', 'name' => 'Dup', 'role' => 'designer', 'password' => 'long-enough-1'])->status);

        $created = $this->studio('POST', '/studio/api/users', $admin, ['email' => 'new@studio.test', 'name' => 'New Designer', 'role' => 'designer', 'password' => 'long-enough-1']);
        self::assertSame(201, $created->status);
        self::assertContains('new@studio.test', array_column($created->body['users'], 'email'));
        $this->login('new@studio.test', 'long-enough-1');

        self::assertSame(400, $this->studio('POST', '/studio/api/users/1/deactivate', $admin)->status, 'cannot lock yourself out');
        $this->studio('POST', '/studio/api/users/2/deactivate', $admin);
        self::assertSame(401, $this->studio('GET', '/studio/api/me', $dana)->status, 'deactivation ends sessions');
    }

    private function login(string $email, string $password): string
    {
        $res = $this->api->handle(new Request('POST', '/studio/api/login', body: ['email' => $email, 'password' => $password]));
        self::assertSame(200, $res->status, json_encode($res->body));

        return $res->body['token'];
    }

    private function customer(string $method, string $path, string $token, array $body = [], array $files = []): Response
    {
        return $this->api->handle(new Request($method, $path, ['authorization' => 'Bearer ' . $token], [], $body, $files));
    }

    private function studio(string $method, string $path, string $token, array $body = [], array $files = []): Response
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $q);

        return $this->api->handle(new Request($method, $path, ['authorization' => 'Bearer ' . $token], $q, $body, $files));
    }

    private function file(string $name, string $content = 'solid scan'): array
    {
        $tmp = tempnam($this->dir, 'up');
        file_put_contents($tmp, $content);

        return ['name' => $name, 'tmp' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }
}
