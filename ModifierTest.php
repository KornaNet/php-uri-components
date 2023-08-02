<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\Uri;

use GuzzleHttp\Psr7\Utils;
use League\Uri\Components\DataPath;
use League\Uri\Exceptions\SyntaxError;
use PHPUnit\Framework\TestCase;

/**
 * @group host
 * @group resolution
 * @coversDefaultClass \League\Uri\UriModifier
 */
final class ModifierTest extends TestCase
{
    private readonly string $uri;
    private readonly Modifier $modifier;

    protected function setUp(): void
    {
        $this->uri = 'http://www.example.com/path/to/the/sky.php?kingkong=toto&foo=bar%20baz#doc3';
        $this->modifier = Modifier::from($this->uri);
    }

    /*****************************
     * QUERY MODIFIER METHOD TESTS
     ****************************/

    /**
     * @dataProvider validMergeQueryProvider
     */
    public function testMergeQuery(string $query, string $expected): void
    {
        self::assertSame($expected, $this->modifier->mergeQuery($query)->getUri()->getQuery());
    }

    public static function validMergeQueryProvider(): array
    {
        return [
            ['toto', 'kingkong=toto&foo=bar%20baz&toto'],
            ['kingkong=ape', 'kingkong=ape&foo=bar%20baz'],
        ];
    }

    /**
     * @dataProvider validAppendQueryProvider
     */
    public function testAppendQuery(string $query, string $expected): void
    {
        self::assertSame($expected, $this->modifier->appendQuery($query)->getUri()->getQuery());
    }

    public static function validAppendQueryProvider(): array
    {
        return [
            ['toto', 'kingkong=toto&foo=bar%20baz&toto'],
            ['kingkong=ape', 'kingkong=toto&foo=bar%20baz&kingkong=ape'],
        ];
    }

    public function testKsortQuery(): void
    {
        $uri = Http::new('http://example.com/?kingkong=toto&foo=bar%20baz&kingkong=ape');
        self::assertSame('kingkong=toto&kingkong=ape&foo=bar%20baz', Modifier::from($uri)->sortQuery()->getUri()->getQuery());
    }

    /**
     * @dataProvider validWithoutQueryValuesProvider
     */
    public function testWithoutQueryValuesProcess(array $input, string $expected): void
    {
        self::assertSame($expected, $this->modifier->removePairs(...$input)->getUri()->getQuery());
    }

    public static function validWithoutQueryValuesProvider(): array
    {
        return [
            [['1'], 'kingkong=toto&foo=bar%20baz'],
            [['kingkong'], 'foo=bar%20baz'],
        ];
    }

    /**
     * @dataProvider removeParamsProvider
     */
    public function testWithoutQueryParams(string $uri, array $input, ?string $expected): void
    {
        self::assertSame($expected, Modifier::from($uri)->removeParams(...$input)->getUri()->getQuery());
    }

    public static function removeParamsProvider(): array
    {
        return [
            [
                'uri' => 'http://example.com',
                'input' => ['foo'],
                'expected' => null,
            ],
            [
                'uri' => 'http://example.com?foo=bar&bar=baz',
                'input' => ['foo'],
                'expected' => 'bar=baz',
            ],
            [
                'uri' => 'http://example.com?fo.o=bar&fo_o=baz',
                'input' => ['fo_o'],
                'expected' => 'fo.o=bar',
            ],
        ];
    }

    /**
     * @dataProvider removeEmptyPairsProvider
     */
    public function testRemoveEmptyPairs(string $uri, ?string $expected): void
    {
        self::assertSame($expected, Modifier::from(Uri::fromBaseUri($uri))->removeEmptyPairs()->getUri()->__toString());
        self::assertSame($expected, Modifier::from(Http::fromBaseUri($uri))->removeEmptyPairs()->getUri()->__toString());
    }

    public static function removeEmptyPairsProvider(): iterable
    {
        return [
            'null query component' => [
                'uri' => 'http://example.com',
                'expected' => 'http://example.com',
            ],
            'empty query component' => [
                'uri' => 'http://example.com?',
                'expected' => 'http://example.com',
            ],
            'no empty pair query component' => [
                'uri' => 'http://example.com?foo=bar',
                'expected' => 'http://example.com?foo=bar',
            ],
            'with empty pair as last pair' => [
                'uri' => 'http://example.com?foo=bar&',
                'expected' => 'http://example.com?foo=bar',
            ],
            'with empty pair as first pair' => [
                'uri' => 'http://example.com?&foo=bar',
                'expected' => 'http://example.com?foo=bar',
            ],
            'with empty pair inside the component' => [
                'uri' => 'http://example.com?foo=bar&&&&bar=baz',
                'expected' => 'http://example.com?foo=bar&bar=baz',
            ],
        ];
    }

    /*****************************
     * HOST MODIFIER METHOD TESTS
     ****************************/

    /**
     * @dataProvider validHostProvider
     */
    public function testPrependLabelProcess(string $label, int $key, string $prepend, string $append, string $replace): void
    {
        self::assertSame($prepend, $this->modifier->prependLabel($label)->getUri()->getHost());
    }

    /**
     * @dataProvider validHostProvider
     */
    public function testAppendLabelProcess(string $label, int $key, string $prepend, string $append, string $replace): void
    {
        self::assertSame($append, $this->modifier->appendLabel($label)->getUri()->getHost());
    }

    /**
     * @dataProvider validHostProvider
     */
    public function testReplaceLabelProcess(string $label, int $key, string $prepend, string $append, string $replace): void
    {
        self::assertSame($replace, $this->modifier->replaceLabel($key, $label)->getUri()->getHost());
    }

    public static function validHostProvider(): array
    {
        return [
            ['toto', 2, 'toto.www.example.com', 'www.example.com.toto', 'toto.example.com'],
            ['123', 1, '123.www.example.com', 'www.example.com.123', 'www.123.com'],
        ];
    }

    public function testAppendLabelWithIpv4Host(): void
    {
        $uri = Http::new('http://127.0.0.1/foo/bar');

        self::assertSame(
            '127.0.0.1.localhost',
            Modifier::from($uri)->appendLabel('.localhost')->getUri()->getHost()
        );
    }

    public function testAppendLabelThrowsWithOtherIpHost(): void
    {
        $this->expectException(SyntaxError::class);

        Modifier::from(Http::new('http://[::1]/foo/bar'))->appendLabel('.localhost');
    }

    public function testPrependLabelWithIpv4Host(): void
    {
        $uri = Http::new('http://127.0.0.1/foo/bar');

        self::assertSame(
            'localhost.127.0.0.1',
            Modifier::from($uri)->prependLabel('localhost.')->getUri()->getHost()
        );
    }

    public function testAppendNulLabel(): void
    {
        $uri = Uri::new('http://127.0.0.1');

        self::assertSame($uri, Modifier::from($uri)->appendLabel(null)->getUri());
    }

    public function testPrependLabelThrowsWithOtherIpHost(): void
    {
        $this->expectException(SyntaxError::class);

        Modifier::from(Http::new('http://[::1]/foo/bar'))->prependLabel('.localhost');
    }

    public function testPrependNullLabel(): void
    {
        $uri = Uri::new('http://127.0.0.1');

        self::assertSame($uri, Modifier::from($uri)->prependLabel(null)->getUri());
    }

    public function testHostToAsciiProcess(): void
    {
        $uri = Uri::new('http://مثال.إختبار/where/to/go');

        self::assertSame(
            'http://xn--mgbh0fb.xn--kgbechtv/where/to/go',
            (string)  Modifier::from($uri)->hostToAscii()
        );
    }

    public function testWithoutZoneIdentifierProcess(): void
    {
        $uri = Http::new('http://[fe80::1234%25eth0-1]/path/to/the/sky.php');

        self::assertSame(
            'http://[fe80::1234]/path/to/the/sky.php',
            (string) Modifier::from($uri)->removeZoneId()
        );
    }

    /**
     * @dataProvider validwithoutLabelProvider
     */
    public function testwithoutLabelProcess(array $keys, string $expected): void
    {
        self::assertSame($expected, $this->modifier->removeLabels(...$keys)->getUri()->getHost());
    }

    public static function validwithoutLabelProvider(): array
    {
        return [
            [[1], 'www.com'],
        ];
    }

    public function testRemoveLabels(): void
    {
        self::assertSame('example.com', $this->modifier->removeLabels(2)->getUri()->getHost());
    }

    public function testModifyingTheHostKeepHostUnicode(): void
    {
        $modifier = Modifier::from(Utils::uriFor('http://shop.bébé.be'));

        self::assertSame('http://shop.bébé', $modifier->removeLabels(0)->getUriString());
        self::assertSame('http://www.bébé.be', $modifier->replaceLabel(-1, 'www')->getUriString());
        self::assertSame('http://new.shop.bébé.be', $modifier->prependLabel('new')->getUriString());
        self::assertSame('http://shop.bébé.be.new', $modifier->appendLabel('new')->getUriString());
        self::assertSame('http://shop.bébé.be', $modifier->hostToUnicode()->getUriString());
        self::assertSame('http://shop.xn--bb-bjab.be', $modifier->hostToAscii()->getUriString());

        $modifier = Modifier::from(Utils::uriFor('http://shop.bebe.be'));

        self::assertSame('http://bébé.bebe.be', $modifier->replaceLabel(-1, 'bébé')->getUriString());
        self::assertSame('http://bébé.shop.bebe.be', $modifier->prependLabel('bébé')->getUriString());
        self::assertSame('http://shop.bebe.be.bébé', $modifier->appendLabel('bébé')->getUriString());
        self::assertSame('http://shop.bebe.be', $modifier->hostToAscii()->getUriString());
        self::assertSame('http://shop.bebe.be', $modifier->hostToUnicode()->getUriString());
    }

    public function testAddRootLabel(): void
    {
        self::assertSame('www.example.com.', $this->modifier->addRootLabel()->addRootLabel()->getUri()->getHost());
    }

    public function testRemoveRootLabel(): void
    {
        self::assertSame('www.example.com', $this->modifier->addRootLabel()->removeRootLabel()->getUri()->getHost());
        self::assertSame('www.example.com', $this->modifier->removeRootLabel()->getUri()->getHost());
    }

    public function testItCanBeJsonSerialize(): void
    {
        $uri = Http::new($this->uri);

        self::assertSame(json_encode($uri), json_encode($this->modifier));
    }

    public function testItCanConvertHostToUnicode(): void
    {
        $uriString = 'http://bébé.be';
        $uri = (string) Http::new($uriString);
        $modifier = Modifier::from(Utils::uriFor($uri));

        self::assertSame('http://xn--bb-bjab.be', $uri);
        self::assertSame('http://xn--bb-bjab.be', (string) $modifier);
        self::assertSame($uriString, (string) $modifier->hostToUnicode());
    }

    public function testICanNormalizeIPv4Host(): void
    {
        $uri = 'http://0300.0250.0000.0001/path/to/the/sky.php';
        $expected = 'http://192.168.0.1/path/to/the/sky.php';

        self::assertSame($expected, Modifier::from($uri)->normalizeIPv4()->getUriString());
    }

    public function testIpv4NormalizeHostWithPsr7Uri(): void
    {
        $uri = Http::new('http://0/test');
        $newUri = Modifier::from($uri)->normalizeIPv4()->getUri();
        self::assertSame('0.0.0.0', $newUri->getHost());

        $uri = Http::new('http://11.be/test');
        $unchangedUri = Modifier::from($uri)->normalizeIPv4()->getUri();
        self::assertSame($uri, $unchangedUri);
    }

    public function testIpv4NormalizeHostWithLeagueUri(): void
    {
        $uri = Uri::new('http://0/test');
        $newUri = Modifier::from($uri)->normalizeIPv4()->getUri();
        self::assertSame('0.0.0.0', $newUri->getHost());

        $uri = Http::new('http://11.be/test');
        $unchangedUri = Modifier::from($uri)->normalizeIPv4()->getUri();
        self::assertSame($uri, $unchangedUri);
    }

    /*********************
     * PATH MODIFIER TESTS
     *********************/

    /**
     * @dataProvider fileProvider
     */
    public function testToBinary(Uri $binary, Uri $ascii): void
    {
        self::assertSame($binary->toString(), Modifier::from($ascii)->dataPathToBinary()->getUriString());
    }

    /**
     * @dataProvider fileProvider
     */
    public function testToAscii(Uri $binary, Uri $ascii): void
    {
        self::assertSame($ascii->toString(), Modifier::from($binary)->dataPathToAscii()->getUriString());
    }

    public static function fileProvider(): array
    {
        $rootPath = dirname(__DIR__).'/test_files';

        $textPath = DataPath::new('text/plain;charset=us-ascii,Bonjour%20le%20monde%21');
        $binPath = DataPath::fromFileContents($rootPath.'/red-nose.gif');

        $ascii = Uri::new('data:text/plain;charset=us-ascii,Bonjour%20le%20monde%21');
        $binary = Uri::new('data:'.$textPath->toBinary());

        $pathBin = Uri::fromFileContents($rootPath.'/red-nose.gif');
        $pathAscii = Uri::new('data:'.$binPath->toAscii());

        return [
            [$pathBin, $pathAscii],
            [$binary, $ascii],
        ];
    }

    public function testDataUriWithParameters(): void
    {
        $uri = Uri::new('data:text/plain;charset=us-ascii,Bonjour%20le%20monde!');
        self::assertSame(
            'text/plain;coco=chanel,Bonjour%20le%20monde!',
            Modifier::from($uri)->replaceDataUriParameters('coco=chanel')->getUri()->getPath()
        );
    }

    /**
     * @dataProvider appendSegmentProvider
     */
    public function testAppendProcess(string $segment, string $append): void
    {
        self::assertSame($append, $this->modifier->appendSegment($segment)->getUri()->getPath());
    }

    public static function appendSegmentProvider(): array
    {
        return [
            ['toto', '/path/to/the/sky.php/toto'],
            ['le blanc', '/path/to/the/sky.php/le%20blanc'],
        ];
    }

    /**
     * @dataProvider validAppendSegmentProvider
     */
    public function testAppendProcessWithRelativePath(string $uri, string $segment, string $expected): void
    {
        self::assertSame($expected, (string) Modifier::from($uri)->appendSegment($segment)->getUri());
    }

    public static function validAppendSegmentProvider(): array
    {
        return [
            'uri with trailing slash' => [
                'uri' => 'http://www.example.com/report/',
                'segment' => 'new-segment',
                'expected' => 'http://www.example.com/report/new-segment',
            ],
            'uri with path without trailing slash' => [
                'uri' => 'http://www.example.com/report',
                'segment' => 'new-segment',
                'expected' => 'http://www.example.com/report/new-segment',
            ],
            'uri with absolute path' => [
                'uri' => 'http://www.example.com/',
                'segment' => 'new-segment',
                'expected' => 'http://www.example.com/new-segment',
            ],
            'uri with empty path' => [
                'uri' => 'http://www.example.com',
                'segment' => 'new-segment',
                'expected' => 'http://www.example.com/new-segment',
            ],
        ];
    }

    /**
     * @dataProvider validBasenameProvider
     */
    public function testBasename(string $path, string $uri, string $expected): void
    {
        self::assertSame($expected, (string) Modifier::from(Uri::new($uri))->replaceBasename($path));
    }

    public static function validBasenameProvider(): array
    {
        return [
            ['baz', 'http://example.com', 'http://example.com/baz'],
            ['baz', 'http://example.com/foo/bar', 'http://example.com/foo/baz'],
            ['baz', 'http://example.com/foo/', 'http://example.com/foo/baz'],
            ['baz', 'http://example.com/foo', 'http://example.com/baz'],
        ];
    }

    public function testBasenameThrowException(): void
    {
        $this->expectException(SyntaxError::class);

        Modifier::from(Uri::new('http://example.com'))->replaceBasename('foo/baz');
    }

    /**
     * @dataProvider validDirnameProvider
     */
    public function testDirname(string $path, string $uri, string $expected): void
    {
        self::assertSame($expected, (string) Modifier::from(Uri::new($uri))->replaceDirname($path));
    }

    public static function validDirnameProvider(): array
    {
        return [
            ['baz', 'http://example.com', 'http://example.com/baz/'],
            ['baz/', 'http://example.com', 'http://example.com/baz/'],
            ['baz', 'http://example.com/foo', 'http://example.com/baz/foo'],
            ['baz', 'http://example.com/foo/yes', 'http://example.com/baz/yes'],
        ];
    }

    /**
     * @dataProvider prependSegmentProvider
     */
    public function testPrependProcess(string $uri, string $segment, string $prepend): void
    {
        $uri = Uri::new($uri);
        self::assertSame($prepend, Modifier::from($uri)->prependSegment($segment)->getUri()->getPath());
    }

    public static function prependSegmentProvider(): array
    {
        return [
            [
                'uri' => 'http://www.example.com/path/to/the/sky.php?kingkong=toto&foo=bar+baz#doc3',
                'segment' => 'toto',
                'expectedPath' => '/toto/path/to/the/sky.php',
            ],
            [
                'uri' => 'http://www.example.com/path/to/the/sky.php?kingkong=toto&foo=bar+baz#doc3',
                'segment' => 'le blanc',
                'expectedPath' => '/le%20blanc/path/to/the/sky.php',
            ],
            [
                'uri' => 'http://example.com/',
                'segment' => 'toto',
                'expectedPath' => '/toto/',
            ],
            [
                'uri' => 'http://example.com',
                'segment' => '/toto',
                'expectedPath' => '/toto/',
            ],
        ];
    }

    /**
     * @dataProvider replaceSegmentProvider
     */
    public function testReplaceSegmentProcess(string $segment, int $key, string $append, string $prepend, string $replace): void
    {
        self::assertSame($replace, $this->modifier->replaceSegment($key, $segment)->getUri()->getPath());
    }

    public static function replaceSegmentProvider(): array
    {
        return [
            ['toto', 2, '/path/to/the/sky.php/toto', '/toto/path/to/the/sky.php', '/path/to/toto/sky.php'],
            ['le blanc', 2, '/path/to/the/sky.php/le%20blanc', '/le%20blanc/path/to/the/sky.php', '/path/to/le%20blanc/sky.php'],
        ];
    }

    /**
     * @dataProvider addBasepathProvider
     */
    public function testaddBasepath(string $basepath, string $expected): void
    {
        self::assertSame($expected, $this->modifier->addBasePath($basepath)->getUri()->getPath());
    }

    public static function addBasepathProvider(): array
    {
        return [
            ['/', '/path/to/the/sky.php'],
            ['', '/path/to/the/sky.php'],
            ['/path/to', '/path/to/the/sky.php'],
            ['/route/to', '/route/to/path/to/the/sky.php'],
        ];
    }

    public function testaddBasepathWithRelativePath(): void
    {
        $uri = Http::new('base/path');
        self::assertSame('/base/path', Modifier::from($uri)->addBasePath('/base/path')->getUri()->getPath());
    }

    /**
     * @dataProvider removeBasePathProvider
     */
    public function testRemoveBasePath(string $basepath, string $expected): void
    {
        self::assertSame($expected, $this->modifier->removeBasePath($basepath)->getUri()->getPath());
    }

    public static function removeBasePathProvider(): array
    {
        return [
            'base path is the leading slash' => ['/', '/path/to/the/sky.php'],
            'base path is the empty string' => ['', '/path/to/the/sky.php'],
            'base path is included in the current path' => ['/path/to', '/the/sky.php'],
            'base path is not included in the current path' => ['/route/to', '/path/to/the/sky.php'],
        ];
    }

    public function testRemoveBasePathWithRelativePath(): void
    {
        $uri = Http::new('base/path');
        self::assertSame('base/path', Modifier::from($uri)->removeBasePath('/base/path')->getUri()->getPath());
    }

    /**
     * @dataProvider validwithoutSegmentProvider
     */
    public function testwithoutSegment(array $keys, string $expected): void
    {
        self::assertSame($expected, $this->modifier->removeSegments(...$keys)->getUri()->getPath());
    }

    public static function validwithoutSegmentProvider(): array
    {
        return [
            [[1], '/path/the/sky.php'],
        ];
    }

    public function testWithoutDotSegmentsProcess(): void
    {
        $uri = Http::new(
            'http://www.example.com/path/../to/the/./sky.php?kingkong=toto&foo=bar+baz#doc3'
        );
        self::assertSame('/to/the/sky.php', Modifier::from($uri)->removeDotSegments()->getUri()->getPath());
    }

    public function testWithoutEmptySegmentsProcess(): void
    {
        $uri = Http::new(
            'http://www.example.com/path///to/the//sky.php?kingkong=toto&foo=bar+baz#doc3'
        );
        self::assertSame('/path/to/the/sky.php', Modifier::from($uri)->removeEmptySegments()->getUri()->getPath());
    }

    public function testWithoutTrailingSlashProcess(): void
    {
        $uri = Http::new('http://www.example.com/');
        self::assertSame('', Modifier::from($uri)->removeTrailingSlash()->getUri()->getPath());
    }

    /**
     * @dataProvider validExtensionProvider
     */
    public function testExtensionProcess(string $extension, string $expected): void
    {
        self::assertSame($expected, $this->modifier->replaceExtension($extension)->getUri()->getPath());
    }

    public static function validExtensionProvider(): array
    {
        return [
            ['csv', '/path/to/the/sky.csv'],
            ['', '/path/to/the/sky'],
        ];
    }

    public function testWithTrailingSlashProcess(): void
    {
        self::assertSame('/path/to/the/sky.php/', $this->modifier->addTrailingSlash()->getUri()->getPath());
    }

    public function testWithoutLeadingSlashProcess(): void
    {
        $uri = Http::new('/foo/bar?q=b#h');

        self::assertSame('foo/bar?q=b#h', (string) Modifier::from($uri)->removeLeadingSlash());
    }

    public function testWithLeadingSlashProcess(): void
    {
        $uri = Http::new('foo/bar?q=b#h');

        self::assertSame('/foo/bar?q=b#h', (string) Modifier::from($uri)->addLeadingSlash());
    }

    public function testReplaceSegmentConstructorFailed2(): void
    {
        $this->expectException(SyntaxError::class);

        $this->modifier->replaceSegment(2, "whyno\0t");
    }

    public function testExtensionProcessFailed(): void
    {
        $this->expectException(SyntaxError::class);

        $this->modifier->replaceExtension('to/to');
    }

    public static function providesInvalidMethodNames(): iterable
    {
        yield 'unknown method' => ['method' => 'unknownMethod'];
        yield 'case sensitive method' => ['method' => 'rePLAceExtenSIOn'];
    }
}
