<?php

declare(strict_types=1);

namespace Bitenxt\Studio;

use Bitenxt\Studio\Files\AttachmentStorage;
use Bitenxt\Studio\Http\Api;
use Bitenxt\Studio\Magento\GraphQLClient;
use Bitenxt\Studio\Magento\MagentoCustomerDirectory;
use Bitenxt\Studio\Notify\StudioNotifier;
use Bitenxt\Studio\Service\CustomerMessaging;
use Bitenxt\Studio\Service\StudioMessaging;
use Bitenxt\Studio\Store\Database;
use Bitenxt\Studio\Store\StudioUserRepository;
use Bitenxt\Studio\Store\ThreadRepository;

/** Wires the production object graph from Config. */
final class App
{
    public static function database(Config $config): Database
    {
        return new Database($config->databaseDsn, $config->databaseUser, $config->databasePassword);
    }

    public static function api(Config $config): Api
    {
        $db = self::database($config);
        $threads = new ThreadRepository($db);
        $users = new StudioUserRepository($db);
        // First deploy: create the first admin from STUDIO_ADMIN_EMAIL / STUDIO_ADMIN_PASSWORD.
        // Ignored once any studio user exists, so the variables can stay set safely.
        if ($config->bootstrapAdminEmail !== '' && $config->bootstrapAdminPassword !== '' && $users->count() === 0) {
            try {
                $users->create($config->bootstrapAdminEmail, 'Studio Admin', StudioUserRepository::ADMIN, $config->bootstrapAdminPassword);
            } catch (\PDOException) {
                // Another instance created it at the same moment.
            }
        }
        $storage = new AttachmentStorage(
            $config->storageDir . '/attachments',
            $config->maxFileBytes,
            $config->maxFilesPerMessage,
            $config->allowedExtensions,
        );
        $notifier = new StudioNotifier(
            $config->logTarget === 'stderr' ? 'php://stderr' : $config->storageDir . '/logs/events.jsonl',
            $config->studioWebhookUrl,
            $config->studioUrl,
        );

        return new Api(
            new CustomerMessaging(
                new MagentoCustomerDirectory(new GraphQLClient($config->magentoGraphqlUrl, $config->magentoTimeoutSeconds)),
                $threads,
                $storage,
                $notifier,
                $config->maxMessageChars,
                $config->customerMessagesPerMinute,
            ),
            new StudioMessaging(
                $users,
                $threads,
                $storage,
                $config->maxMessageChars,
                $config->studioSessionHours * 3600,
            ),
        );
    }
}
