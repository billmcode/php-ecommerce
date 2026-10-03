<?php

namespace Tests\Support;

/**
 * Overrides the "php" stream wrapper so php://input serves a configurable body
 * while every other php:// URL (output, stdin, memory, temp, ...) is delegated
 * to the native wrapper.
 */
final class PhpInputStream
{
    private static string $body = '';
    private static bool $registered = false;

    /** @var resource|null set by PHP when a stream context is supplied */
    public $context;

    /** @var resource|null */
    private $handle = null;
    private bool $input = false;
    private int $pos = 0;

    public static function setBody(string $body): void
    {
        self::$body = $body;
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        self::$registered = true;
    }

    public static function restore(): void
    {
        if (!self::$registered) {
            return;
        }
        self::$registered = false;
        self::$body = '';
        stream_wrapper_restore('php');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (strcasecmp($path, 'php://input') === 0) {
            $this->input = true;
            $this->pos = 0;
            return true;
        }

        // Delegate to the native wrapper: restore it, open, then re-install this one.
        stream_wrapper_restore('php');
        try {
            $handle = @fopen($path, $mode);
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }

        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->input) {
            $chunk = substr(self::$body, $this->pos, $count);
            $this->pos += strlen($chunk);
            return $chunk;
        }
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        if ($this->input) {
            return 0;
        }
        return (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        if ($this->input) {
            return $this->pos >= strlen(self::$body);
        }
        return feof($this->handle);
    }

    public function stream_tell(): int
    {
        return $this->input ? $this->pos : (int) ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($this->input) {
            $length = strlen(self::$body);
            $target = match ($whence) {
                SEEK_SET => $offset,
                SEEK_CUR => $this->pos + $offset,
                SEEK_END => $length + $offset,
                default => -1,
            };
            if ($target < 0) {
                return false;
            }
            $this->pos = $target;
            return true;
        }
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return $this->input ? true : fflush($this->handle);
    }

    public function stream_stat(): array|false
    {
        if ($this->input) {
            return ['size' => strlen(self::$body)];
        }
        return fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_cast(int $castAs)
    {
        return $this->handle ?? false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
