<?php

declare(strict_types=1);

/**
 * Manage studio users (admins and designers).
 *
 *   php bin/studio-user.php add <email> <name> <admin|designer>     (asks for the password)
 *   php bin/studio-user.php password <email>                        (asks for the new password)
 *   php bin/studio-user.php disable <email>  |  enable <email>
 *   php bin/studio-user.php list
 *
 * Uses the same DATABASE_DSN as the service. On Cloud Run, run it as a Cloud Run job
 * or from a machine that can reach the database (see docs/DEPLOY.md).
 * STUDIO_USER_PASSWORD can be set instead of typing the password (for scripts).
 */

use Bitenxt\Studio\App;
use Bitenxt\Studio\Config;
use Bitenxt\Studio\Store\StudioUserRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$users = new StudioUserRepository(App::database(Config::fromEnvironment(dirname(__DIR__) . '/.env')));
$command = $argv[1] ?? '';

$askPassword = static function (): string {
    if (($p = getenv('STUDIO_USER_PASSWORD')) !== false && $p !== '') {
        return $p;
    }
    fwrite(STDOUT, 'Password (min 10 characters): ');
    @shell_exec('stty -echo');
    $password = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, "\n");

    return $password;
};

try {
    switch ($command) {
        case 'add':
            [, , $email, $name, $role] = $argv + [null, null, '', '', ''];
            if ($email === '' || $name === '' || $role === '') {
                throw new InvalidArgumentException('usage: add <email> <name> <admin|designer>');
            }
            $id = $users->create($email, $name, $role, $askPassword());
            echo "Created $role #$id $email\n";
            break;
        case 'password':
            echo $users->setPassword($argv[2] ?? '', $askPassword()) ? "Password changed\n" : "No such user\n";
            break;
        case 'disable':
        case 'enable':
            echo $users->setActive($argv[2] ?? '', $command === 'enable') ? "Done\n" : "No such user\n";
            break;
        case 'list':
            foreach ($users->activeUsers() as $u) {
                printf("#%d  %-9s %-30s %s\n", $u['id'], $u['role'], $u['email'], $u['name']);
            }
            break;
        default:
            fwrite(STDERR, "usage: php bin/studio-user.php add|password|disable|enable|list ...\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
