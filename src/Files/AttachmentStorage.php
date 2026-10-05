<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Files;

/**
 * Stores uploaded attachments outside the web root under random names, so a
 * file is only ever reachable through the API's permission checks.
 */
final class AttachmentStorage
{
    /** @param list<string> $allowedExtensions */
    public function __construct(
        private readonly string $dir,
        private readonly int $maxBytes,
        private readonly int $maxFiles,
        private readonly array $allowedExtensions,
    ) {
    }

    /**
     * Checks every file before anything is stored, so a message is either
     * saved with all its files or rejected as a whole.
     *
     * @param list<array{name: string, tmp: string, size: int, error: int}> $files
     * @return string|null a message for the user, or null when all files are acceptable
     */
    public function validate(array $files): ?string
    {
        if (count($files) > $this->maxFiles) {
            return "You can attach up to {$this->maxFiles} files per message.";
        }
        foreach ($files as $file) {
            $name = self::cleanName($file['name']);
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                || $file['size'] > $this->maxBytes) {
                return sprintf('"%s" is too large. The limit is %d MB per file.', $name, intdiv($this->maxBytes, 1048576));
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp'])) {
                return sprintf('"%s" did not upload completely. Please try again.', $name);
            }
            if ($file['size'] === 0) {
                return sprintf('"%s" is empty.', $name);
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->allowedExtensions, true)) {
                return sprintf('"%s" is not an accepted file type. Accepted: %s.', $name, implode(', ', $this->allowedExtensions));
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, tmp: string, size: int, error: int}> $files already validated
     * @return list<array{original_name: string, stored_name: string, size_bytes: int, sha256: string}>
     */
    public function store(string $orderNumber, array $files): array
    {
        $folder = preg_replace('/[^A-Za-z0-9-]/', '_', $orderNumber);
        $target = $this->dir . '/' . $folder;
        if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
            throw new \RuntimeException('cannot create attachment folder');
        }
        $stored = [];
        foreach ($files as $file) {
            $storedName = $folder . '/' . bin2hex(random_bytes(16)) . '.bin';
            $path = $this->dir . '/' . $storedName;
            $ok = is_uploaded_file($file['tmp']) ? move_uploaded_file($file['tmp'], $path) : copy($file['tmp'], $path);
            if (!$ok) {
                foreach ($stored as $done) {
                    @unlink($this->dir . '/' . $done['stored_name']);
                }
                throw new \RuntimeException('cannot store attachment');
            }
            $stored[] = [
                'original_name' => self::cleanName($file['name']),
                'stored_name' => $storedName,
                'size_bytes' => (int) filesize($path),
                'sha256' => (string) hash_file('sha256', $path),
            ];
        }

        return $stored;
    }

    public function path(string $storedName): ?string
    {
        // Stored names are generated here; refuse anything that could leave the folder.
        if (!preg_match('#^[A-Za-z0-9_-]+/[a-f0-9]{32}\.bin$#', $storedName)) {
            return null;
        }
        $path = $this->dir . '/' . $storedName;

        return is_file($path) ? $path : null;
    }

    /** A display-safe file name: no path, no control characters, bounded length. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>]/u', '', $name);
        $name = trim($name, " .");
        if ($name === '') {
            return 'file';
        }
        if (mb_strlen($name) > 150) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 140) . ($extension !== '' ? '.' . mb_substr($extension, 0, 8) : '');
        }

        return $name;
    }
}
