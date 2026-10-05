<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Tests;

use Bitenxt\Studio\App;
use Bitenxt\Studio\Config;
use Bitenxt\Studio\Http\Request;
use PHPUnit\Framework\TestCase;

final class AppBootstrapTest extends TestCase
{
    public function testFirstAdminIsCreatedOnlyWhenNoUsersExist(): void
    {
        $dir = sys_get_temp_dir() . '/bnx-studio-boot-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $config = fn (string $password) => new Config(
            '', 5, 'sqlite:' . $dir . '/db.sqlite', '', '', $dir, [], [], 1024, 1, ['pdf'], 100, 5, 1, '', '', 0, 'file',
            'owner@studio.test', $password,
        );
        $login = fn (string $password) => App::api($config('first-password-1'))
            ->handle(new Request('POST', '/studio/api/login', body: ['email' => 'owner@studio.test', 'password' => $password]))->status;

        self::assertSame(200, $login('first-password-1'));
        // A changed variable later does not reset or add anything.
        App::api($config('other-password-2'));
        self::assertSame(401, $login('other-password-2'));
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
