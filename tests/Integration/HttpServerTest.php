<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Integration;

use Jbrada\Semverdict\Support\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Exercises Http against two local PHP built-in servers: redirects must be
 * followed, and the Authorization header must survive same-origin redirects
 * but never cross an origin boundary.
 */
class HttpServerTest extends TestCase
{
    /** @var list<Process> */
    private static array $processes = [];
    private static string $originA;
    private static string $originB;
    private static string $routerPath;

    public static function setUpBeforeClass(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'semverdict-router');
        @unlink($base);
        self::$routerPath = $base . '.php';
        file_put_contents(self::$routerPath, <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? 'none';
            switch ($path) {
                case '/ok':
                    header('Content-Type: text/plain');
                    echo "auth={$auth}";
                    break;
                case '/redirect':
                    header('Location: /ok', true, 302);
                    break;
                case '/redirect-cross':
                    header('Location: ' . getenv('CROSS_TARGET') . '/ok', true, 302);
                    break;
                case '/truncated':
                    header('Connection: close');
                    header('Content-Length: 1000');
                    echo 'short';
                    break;
                default:
                    http_response_code(404);
            }
            PHP);

        $portA = self::freePort();
        $portB = self::freePort();
        self::$originA = "http://127.0.0.1:{$portA}";
        self::$originB = "http://127.0.0.1:{$portB}";

        foreach ([$portA => self::$originB, $portB => self::$originA] as $port => $crossTarget) {
            $process = new Process(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", self::$routerPath],
                env: ['CROSS_TARGET' => $crossTarget],
            );
            $process->start();
            self::$processes[] = $process;
        }

        foreach ([$portA, $portB] as $port) {
            self::waitForPort($port);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            $process->stop(0);
        }
        @unlink(self::$routerPath);
    }

    public function testFollowsSameOriginRedirectKeepingAuth(): void
    {
        $body = Http::get(self::$originA . '/redirect', 'user:secret');

        self::assertSame('auth=Basic ' . base64_encode('user:secret'), $body);
    }

    public function testDropsAuthOnCrossOriginRedirect(): void
    {
        $body = Http::get(self::$originA . '/redirect-cross', 'user:secret');

        self::assertSame('auth=none', $body);
    }

    public function testThrowsOnHttpErrorStatus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('404');
        Http::get(self::$originA . '/missing');
    }

    public function testDownloadRejectsTruncatedBody(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'semverdict-dl');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('incomplete');
            Http::download(self::$originA . '/truncated', $dest);
        } finally {
            self::assertFileDoesNotExist($dest, 'a failed download must not leave a partial file behind');
            @unlink($dest);
        }
    }

    public function testDownloadWritesCompleteBody(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'semverdict-dl');

        try {
            Http::download(self::$originA . '/ok', $dest);
            self::assertSame('auth=none', file_get_contents($dest));
        } finally {
            @unlink($dest);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('Cannot open a socket to find a free port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($name === false) {
            self::fail('Cannot determine the test socket name.');
        }

        return (int) explode(':', $name)[1];
    }

    private static function waitForPort(int $port): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $port, timeout: 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        self::fail("Test HTTP server on port {$port} did not start.");
    }
}
