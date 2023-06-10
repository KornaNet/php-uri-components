<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\Uri\Components;

use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Http;
use League\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface as Psr7UriInterface;

/**
 * @group path
 * @group defaultpath
 * @coversDefaultClass \League\Uri\Components\Path
 */
final class PathTest extends TestCase
{
    /**
     * @dataProvider validPathEncoding
     */
    public function testGetUriComponent(string $decoded, string $encoded): void
    {
        $path = Path::createFromString($decoded);

        self::assertSame($decoded, $path->decoded());
        self::assertSame($encoded, $path->value());
    }

    public static function validPathEncoding(): array
    {
        return [
            [
                'toto',
                'toto',
            ],
            [
                'bar---',
                'bar---',
            ],
            [
                '',
                '',
            ],
            [
                '"bad"',
                '%22bad%22',
            ],
            [
                '<not good>',
                '%3Cnot%20good%3E',
            ],
            [
                '{broken}',
                '%7Bbroken%7D',
            ],
            [
                '`oops`',
                '%60oops%60',
            ],
            [
                '\\slashy',
                '%5Cslashy',
            ],
            [
                'foo^bar',
                'foo%5Ebar',
            ],
            [
                'foo^bar/baz',
                'foo%5Ebar/baz',
            ],
            [
                'foo%2Fbar',
                'foo%2Fbar',
            ],
            [
                '/v1/people/%7E:(first-name,last-name,email-address,picture-url)',
                '/v1/people/%7E:(first-name,last-name,email-address,picture-url)',
            ],
            [
                '/v1/people/~:(first-name,last-name,email-address,picture-url)',
                '/v1/people/~:(first-name,last-name,email-address,picture-url)',
            ],
            [
                'foo%2520bar',
                'foo%2520bar',
            ],
        ];
    }

    public function testConstructorThrowsExceptionWithInvalidData(): void
    {
        $this->expectException(SyntaxError::class);

        Path::createFromString("\0");
    }

    /**
     * Test Removing Dot Segment.
     *
     * @dataProvider normalizeProvider
     */
    public function testWithoutDotSegments(string $path, string $expected): void
    {
        self::assertSame($expected, Path::createFromString($path)->withoutDotSegments()->toString());
    }

    /**
     * Provides different segment to be normalized.
     */
    public static function normalizeProvider(): array
    {
        return [
            ['/a/b/c/./../../g', '/a/g'],
            ['mid/content=5/../6', 'mid/6'],
            ['a/b/c', 'a/b/c'],
            ['a/b/c/.', 'a/b/c/'],
            ['/a/b/c', '/a/b/c'],
        ];
    }

    /**
     * @dataProvider trailingSlashProvider
     */
    public function testHasTrailingSlash(string $path, bool $expected): void
    {
        self::assertSame($expected, Path::createFromString($path)->hasTrailingSlash());
    }

    public static function trailingSlashProvider(): array
    {
        return [
            ['/path/to/my/', true],
            ['/path/to/my', false],
            ['path/to/my', false],
            ['path/to/my/', true],
            ['/', true],
            ['', false],
        ];
    }

    /**
     * @dataProvider withTrailingSlashProvider
     */
    public function testWithTrailingSlash(string $path, string $expected): void
    {
        self::assertSame($expected, (string) Path::createFromString($path)->withTrailingSlash());
    }

    public static function withTrailingSlashProvider(): array
    {
        return [
            'relative path without ending slash' => ['toto', 'toto/'],
            'absolute path without ending slash' => ['/toto', '/toto/'],
            'root path' => ['/', '/'],
            'empty path' => ['', '/'],
            'relative path with ending slash' => ['toto/', 'toto/'],
            'absolute path with ending slash' => ['/toto/', '/toto/'],
        ];
    }

    /**
     * @dataProvider withoutTrailingSlashProvider
     */
    public function testWithoutTrailingSlash(string $path, string $expected): void
    {
        self::assertSame($expected, (string) Path::createFromString($path)->withoutTrailingSlash());
    }

    public static function withoutTrailingSlashProvider(): array
    {
        return [
            'relative path without ending slash' => ['toto', 'toto'],
            'absolute path without ending slash' => ['/toto', '/toto'],
            'root path' => ['/', ''],
            'empty path' => ['', ''],
            'relative path with ending slash' => ['toto/', 'toto'],
            'absolute path with ending slash' => ['/toto/', '/toto'],
        ];
    }

    /**
     * @dataProvider withLeadingSlashProvider
     */
    public function testWithLeadingSlash(string $path, string $expected): void
    {
        self::assertSame($expected, (string) Path::createFromString($path)->withLeadingSlash());
    }

    public static function withLeadingSlashProvider(): array
    {
        return [
            'relative path without leading slash' => ['toto', '/toto'],
            'absolute path' => ['/toto', '/toto'],
            'root path' => ['/', '/'],
            'empty path' => ['', '/'],
            'relative path with ending slash' => ['toto/', '/toto/'],
            'absolute path with ending slash' => ['/toto/', '/toto/'],
        ];
    }

    /**
     * @dataProvider withoutLeadingSlashProvider
     */
    public function testWithoutLeadingSlash(string $path, string $expected): void
    {
        self::assertSame($expected, (string) Path::createFromString($path)->withoutLeadingSlash());
    }

    public static function withoutLeadingSlashProvider(): array
    {
        return [
            'relative path without ending slash' => ['toto', 'toto'],
            'absolute path without ending slash' => ['/toto', 'toto'],
            'root path' => ['/', ''],
            'empty path' => ['', ''],
            'absolute path with ending slash' => ['/toto/', 'toto/'],
        ];
    }

    /**
     * @dataProvider getURIProvider
     */
    public function testCreateFromUri(Psr7UriInterface|UriInterface $uri, ?string $expected): void
    {
        $path = Path::createFromUri($uri);

        self::assertSame($expected, $path->value());
    }

    public static function getURIProvider(): iterable
    {
        return [
            'PSR-7 URI object' => [
                'uri' => Http::createFromString('http://example.com/path'),
                'expected' => '/path',
            ],
            'PSR-7 URI object with no path' => [
                'uri' => Http::createFromString('toto://example.com'),
                'expected' => '',
            ],
            'League URI object' => [
                'uri' => Uri::createFromString('http://example.com/path'),
                'expected' => '/path',
            ],
            'League URI object with no path' => [
                'uri' => Uri::createFromString('toto://example.com'),
                'expected' => '',
            ],
        ];
    }
}
