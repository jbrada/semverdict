<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Support\Http;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    public function testStatusCodeParsesStatusLine(): void
    {
        self::assertSame(200, Http::statusCode(['HTTP/1.1 200 OK']));
        self::assertSame(302, Http::statusCode(['HTTP/2 302']));
        self::assertSame(404, Http::statusCode(['HTTP/1.0 404 Not Found', 'Content-Type: text/html']));
        self::assertSame(0, Http::statusCode([]));
        self::assertSame(0, Http::statusCode(['garbage']));
    }

    public function testHeaderValueIsCaseInsensitiveAndTakesLastOccurrence(): void
    {
        $headers = ['HTTP/1.1 200 OK', 'content-length: 10', 'Content-Length: 20'];
        self::assertSame('20', Http::headerValue($headers, 'Content-Length'));
        self::assertNull(Http::headerValue($headers, 'Location'));
    }

    public function testResolveUrl(): void
    {
        self::assertSame(
            'https://cdn.example.com/file.zip',
            Http::resolveUrl('https://repo.example.com/p2/a/b.json', 'https://cdn.example.com/file.zip'),
        );
        self::assertSame(
            'https://repo.example.com/other/file.zip',
            Http::resolveUrl('https://repo.example.com/p2/a/b.json', '/other/file.zip'),
        );
        self::assertSame(
            'https://cdn.example.com/file.zip',
            Http::resolveUrl('https://repo.example.com/p2/a.json', '//cdn.example.com/file.zip'),
        );
        self::assertSame(
            'https://repo.example.com:8443/p2/a/file.zip',
            Http::resolveUrl('https://repo.example.com:8443/p2/a/b.json', 'file.zip'),
        );
    }

    public function testRefusesNonHttpUrls(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-HTTP(S)');
        Http::get('file:///etc/passwd');
    }

    public function testSameOriginComparesSchemeHostAndPort(): void
    {
        self::assertTrue(Http::sameOrigin('https://a.test/x', 'https://a.test/y'));
        self::assertTrue(Http::sameOrigin('https://a.test/x', 'https://a.test:443/y'));
        self::assertFalse(Http::sameOrigin('https://a.test/x', 'https://b.test/x'));
        self::assertFalse(Http::sameOrigin('https://a.test/x', 'http://a.test/x'));
        self::assertFalse(Http::sameOrigin('http://a.test:8080/x', 'http://a.test:8081/x'));
    }
}
