<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Http;

use HomeCare\Http\InvalidRequestException;
use HomeCare\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testGetReturnsValueWhenPresent(): void
    {
        $req = new Request(get: ['patient_id' => '42']);
        $this->assertSame('42', $req->get('patient_id'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $req = new Request();
        $this->assertSame('fallback', $req->get('missing', 'fallback'));
        $this->assertNull($req->get('missing'));
    }

    public function testPostReturnsValueWhenPresent(): void
    {
        $req = new Request(post: ['name' => 'Daisy']);
        $this->assertSame('Daisy', $req->post('name'));
    }

    public function testPostReturnsDefaultWhenMissing(): void
    {
        $this->assertNull((new Request())->post('missing'));
    }

    public function testGetIntParsesSignedInteger(): void
    {
        $this->assertSame(42, (new Request(get: ['n' => '42']))->getInt('n'));
        $this->assertSame(-7, (new Request(post: ['n' => '-7']))->getInt('n'));
    }

    public function testGetIntPostTakesPrecedenceOverGet(): void
    {
        $req = new Request(get: ['n' => '1'], post: ['n' => '2']);
        $this->assertSame(2, $req->getInt('n'));
    }

    public function testGetIntReturnsNullForNonNumeric(): void
    {
        $this->assertNull((new Request(get: ['n' => 'abc']))->getInt('n'));
        $this->assertNull((new Request(get: ['n' => '1.5']))->getInt('n'));
        $this->assertNull((new Request(get: ['n' => '']))->getInt('n'));
    }

    public function testGetIntReturnsNullForMissingKey(): void
    {
        $this->assertNull((new Request())->getInt('missing'));
    }

    public function testMethodUppercasesAndDefaultsToGet(): void
    {
        $this->assertSame('POST', (new Request(server: ['REQUEST_METHOD' => 'post']))->method());
        $this->assertSame('GET', (new Request())->method());
    }

    public function testFromGlobalsReadsSuperglobals(): void
    {
        $origGet = $_GET;
        $origPost = $_POST;
        $origServer = $_SERVER['REQUEST_METHOD'] ?? null;

        try {
            $_GET = ['q' => 'hello'];
            $_POST = ['name' => 'Daisy'];
            $_SERVER['REQUEST_METHOD'] = 'POST';

            $req = Request::fromGlobals();

            $this->assertSame('hello', $req->get('q'));
            $this->assertSame('Daisy', $req->post('name'));
            $this->assertSame('POST', $req->method());
        } finally {
            $_GET = $origGet;
            $_POST = $origPost;
            if ($origServer === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $origServer;
            }
        }
    }

    // XSS guard (the banned-tag check mirrors preventHacking() in formvars.php)

    public function testGetRejectsBannedTag(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('banned HTML tag <SCRIPT>');

        (new Request(get: ['note' => '<script>alert(1)</script>']))->get('note');
    }

    public function testPostRejectsBannedTagRegardlessOfWhitespace(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new Request(post: ['note' => '<  IFRAME src=evil>']))->post('note');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function bannedTagProvider(): iterable
    {
        yield ['APPLET'];
        yield ['BODY'];
        yield ['EMBED'];
        yield ['FORM'];
        yield ['HEAD'];
        yield ['HTML'];
        yield ['IFRAME'];
        yield ['LINK'];
        yield ['META'];
        yield ['NOEMBED'];
        yield ['NOFRAMES'];
        yield ['NOSCRIPT'];
        yield ['OBJECT'];
        yield ['SCRIPT'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bannedTagProvider')]
    public function testEachBannedTagRejected(string $tag): void
    {
        $this->expectException(InvalidRequestException::class);
        (new Request(get: ['v' => "<{$tag}>"]))->get('v');
    }

    public function testHexEscapeDecodedBeforeScanning(): void
    {
        // \x3c is the hex escape for `<`. A naive scan would miss this.
        $this->expectException(InvalidRequestException::class);
        (new Request(get: ['v' => '\\x3cSCRIPT>alert(1)']))->get('v');
    }

    public function testSafeValuePassesThrough(): void
    {
        $req = new Request(get: ['notes' => 'with food, no issues']);
        $this->assertSame('with food, no issues', $req->get('notes'));
    }

    public function testArrayValuesAreScannedRecursively(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new Request(post: ['notes' => ['safe', '<IFRAME>', 'also safe']]))->post('notes');
    }

    public function testArrayValuesReturnedWhenAllSafe(): void
    {
        $req = new Request(post: ['notes' => ['one', 'two']]);
        $this->assertSame(['one', 'two'], $req->post('notes'));
    }
}
