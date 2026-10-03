<?php

namespace Tests\Support;

/**
 * Test-only replacement for the built-in "php" stream wrapper.
 *
 * "php://input" serves a configurable body; every other php:// URI is opened
 * through the original wrapper and proxied.
 */
final class PhpInputStreamWrapper
{
    private static ?string $body = null;

    /** @var resource|null Set by PHP when a stream context is passed. */
    public $context;

    /** @var resource|null */
    private $handle = null;
    private bool $isInput = false;
    private int $position = 0;

    public static function install(string $body): void
    {
        if (self::$body === null) {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }

        self::$body = $body;
    }

    public static function restore(): void
    {
        if (self::$body === null) {
            return;
        }

        stream_wrapper_restore('php');
        self::$body = null;
    }

    public static function isInstalled(): bool
    {
        return self::$body !== null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (strcasecmp($path, 'php://input') === 0) {
            $this->isInput = true;
            $this->position = 0;

            return true;
        }

        $handle = self::withOriginalWrapper(static fn () => @fopen($path, $mode));
        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->isInput) {
            $chunk = substr((string) self::$body, $this->position, $count);
            $this->position += strlen($chunk);

            return $chunk;
        }

        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        if ($this->isInput) {
            return 0;
        }

        $written = fwrite($this->handle, $data);

        return $written === false ? 0 : $written;
    }

    public function stream_eof(): bool
    {
        if ($this->isInput) {
            return $this->position >= strlen((string) self::$body);
        }

        return feof($this->handle);
    }

    public function stream_tell(): int
    {
        if ($this->isInput) {
            return $this->position;
        }

        $position = ftell($this->handle);

        return $position === false ? 0 : $position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->isInput) {
            $length = strlen((string) self::$body);
            $target = match ($whence) {
                SEEK_SET => $offset,
                SEEK_CUR => $this->position + $offset,
                SEEK_END => $length + $offset,
                default => -1,
            };
            if ($target < 0) {
                return false;
            }
            $this->position = $target;

            return true;
        }

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return $this->isInput ? true : fflush($this->handle);
    }

    public function stream_stat(): array|false
    {
        if ($this->isInput) {
            return ['size' => strlen((string) self::$body)];
        }

        return fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if (!$this->isInput && is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    private static function withOriginalWrapper(callable $operation): mixed
    {
        stream_wrapper_restore('php');
        try {
            return $operation();
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
    }
}
