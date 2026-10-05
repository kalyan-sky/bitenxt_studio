<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Http;

final class Request
{
    /**
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $query
     * @param array<string, mixed> $body JSON body, or form fields for multipart
     * @param list<array{name: string, tmp: string, size: int, error: int}> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly string $clientIp = 'unknown',
    ) {
    }

    public function bearer(string $pattern = '[A-Za-z0-9._\-]{10,2048}'): ?string
    {
        return preg_match('/^Bearer\s+(' . $pattern . ')$/', $this->headers['authorization'] ?? '', $m) ? $m[1] : null;
    }

    public function string(string $key): string
    {
        $value = $this->body[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
