<?php

declare(strict_types=1);

namespace Bitenxt\Studio;

/** Settings from environment variables (and an optional .env file for local runs). */
final class Config
{
    /**
     * @param list<string> $allowedOrigins Pro frontend(s) that embed the customer messages panel
     * @param list<string> $studioAllowedOrigins studio frontend(s) that call /studio/api from another domain
     * @param list<string> $allowedExtensions
     */
    public function __construct(
        public readonly string $magentoGraphqlUrl,
        public readonly int $magentoTimeoutSeconds,
        public readonly string $databaseDsn,
        public readonly string $databaseUser,
        public readonly string $databasePassword,
        public readonly string $storageDir,
        public readonly array $allowedOrigins,
        public readonly array $studioAllowedOrigins,
        public readonly int $maxFileBytes,
        public readonly int $maxFilesPerMessage,
        public readonly array $allowedExtensions,
        public readonly int $maxMessageChars,
        public readonly int $customerMessagesPerMinute,
        public readonly int $studioSessionHours,
        public readonly string $studioWebhookUrl,
        public readonly string $studioUrl,
        public readonly int $trustedProxyHops,
        public readonly string $logTarget,
        public readonly string $bootstrapAdminEmail = '',
        public readonly string $bootstrapAdminPassword = '',
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        if ($envFile !== null && is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if (getenv($key) === false) {
                    putenv($key . '=' . trim($value, "\"'"));
                }
            }
        }
        $env = static fn (string $key, string $default = ''): string => ($v = getenv($key)) === false || $v === '' ? $default : $v;
        $list = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));
        $root = dirname(__DIR__);
        $storage = $env('STORAGE_DIR', $root . '/var');

        return new self(
            magentoGraphqlUrl: $env('MAGENTO_GRAPHQL_URL'),
            magentoTimeoutSeconds: (int) $env('MAGENTO_TIMEOUT_SECONDS', '10'),
            databaseDsn: $env('DATABASE_DSN', 'sqlite:' . $storage . '/studio.sqlite'),
            databaseUser: $env('DATABASE_USER'),
            databasePassword: $env('DATABASE_PASSWORD'),
            storageDir: $storage,
            allowedOrigins: $list($env('ALLOWED_ORIGINS')),
            studioAllowedOrigins: $list($env('STUDIO_ALLOWED_ORIGINS')),
            maxFileBytes: (int) $env('MAX_FILE_MB', '25') * 1024 * 1024,
            maxFilesPerMessage: (int) $env('MAX_FILES_PER_MESSAGE', '5'),
            allowedExtensions: array_map('strtolower', $list($env(
                'ALLOWED_FILE_EXTENSIONS',
                'stl,ply,obj,dcm,zip,7z,pdf,jpg,jpeg,png,heic,webp,txt',
            ))),
            maxMessageChars: (int) $env('MAX_MESSAGE_CHARS', '4000'),
            customerMessagesPerMinute: (int) $env('CUSTOMER_MESSAGES_PER_MINUTE', '10'),
            studioSessionHours: (int) $env('STUDIO_SESSION_HOURS', '12'),
            studioWebhookUrl: $env('STUDIO_WEBHOOK_URL'),
            studioUrl: rtrim($env('STUDIO_URL'), '/'),
            trustedProxyHops: (int) $env('TRUSTED_PROXY_HOPS', '0'),
            logTarget: $env('LOG_TARGET', 'file'),
            bootstrapAdminEmail: $env('STUDIO_ADMIN_EMAIL'),
            bootstrapAdminPassword: $env('STUDIO_ADMIN_PASSWORD'),
        );
    }
}
