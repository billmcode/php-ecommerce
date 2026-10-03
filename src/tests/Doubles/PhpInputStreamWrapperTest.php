<?php

namespace Tests\Doubles;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhpInputStreamWrapper;

final class PhpInputStreamWrapperTest extends TestCase
{
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputBufferLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        PhpInputStreamWrapper::restore();
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    #[Test]
    public function it_serves_the_configured_body_for_php_input(): void
    {
        PhpInputStreamWrapper::install('{"name":"Widget"}');

        self::assertSame('{"name":"Widget"}', file_get_contents('php://input'));
    }

    #[Test]
    public function it_serves_the_body_again_on_every_read_and_for_large_bodies(): void
    {
        $body = str_repeat('0123456789', 3000);
        PhpInputStreamWrapper::install($body);

        self::assertSame($body, file_get_contents('php://input'));
        self::assertSame($body, file_get_contents('php://input'));
    }

    #[Test]
    public function it_serves_an_empty_body(): void
    {
        PhpInputStreamWrapper::install('');

        self::assertSame('', file_get_contents('php://input'));
    }

    #[Test]
    public function installing_again_replaces_the_body(): void
    {
        PhpInputStreamWrapper::install('first');
        PhpInputStreamWrapper::install('second');

        self::assertSame('second', file_get_contents('php://input'));
    }

    #[Test]
    public function other_php_uris_are_delegated_to_the_original_wrapper(): void
    {
        PhpInputStreamWrapper::install('body');

        foreach (['php://memory', 'php://temp'] as $uri) {
            $stream = fopen($uri, 'w+');
            fwrite($stream, 'payload');
            rewind($stream);
            self::assertSame('payload', stream_get_contents($stream), $uri);
            fclose($stream);
        }

        ob_start();
        $output = fopen('php://output', 'w');
        fwrite($output, 'echoed');
        fclose($output);
        self::assertSame('echoed', ob_get_clean());

        $stdin = fopen('php://stdin', 'r');
        self::assertIsResource($stdin);
        fclose($stdin);
    }

    #[Test]
    public function the_wrapper_stays_installed_after_delegating(): void
    {
        PhpInputStreamWrapper::install('body');
        fclose(fopen('php://memory', 'r'));

        self::assertSame('body', file_get_contents('php://input'));
    }

    #[Test]
    public function restore_puts_the_original_wrapper_back(): void
    {
        PhpInputStreamWrapper::install('body');
        PhpInputStreamWrapper::restore();

        self::assertFalse(PhpInputStreamWrapper::isInstalled());
        self::assertNotSame('body', file_get_contents('php://input'));

        $stream = fopen('php://memory', 'w+');
        fwrite($stream, 'x');
        rewind($stream);
        self::assertSame('x', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function restore_without_install_is_a_no_op(): void
    {
        PhpInputStreamWrapper::restore();
        PhpInputStreamWrapper::restore();

        self::assertFalse(PhpInputStreamWrapper::isInstalled());
    }
}
