<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Http;

final class Response
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly int $status,
        public readonly array $body = [],
        public readonly ?string $filePath = null,
        public readonly string $fileName = '',
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body): self
    {
        return new self($status, $body);
    }

    public static function error(int $status, string $error, string $message = ''): self
    {
        return new self($status, array_filter(['error' => $error, 'message' => $message]));
    }

    public static function file(string $path, string $name): self
    {
        return new self(200, [], $path, $name);
    }

    public function isFile(): bool
    {
        return $this->filePath !== null;
    }
}
