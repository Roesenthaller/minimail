<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\UnsubscribeResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * What the reading pane's Unsubscribe button is allowed to offer.
 *
 * The header it reads is the SENDER's, so most of what is asserted here is
 * about what is refused: a scheme that would become a script, a plain-http
 * address offered as one-click, an address that is not one. The accepted cases
 * are the ones bulk mailers actually send — the two-entry form, the folded
 * header, the Gmail/Yahoo one-click pair.
 */
final class UnsubscribeResolverTest extends TestCase
{
    private UnsubscribeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UnsubscribeResolver();
    }

    public function testAMessageWithoutTheHeaderOffersNothing(): void
    {
        self::assertNull($this->resolver->forMessage($this->message(['subject' => 'hello'])));
    }

    public function testOneClickNeedsBothTheHttpsAddressAndTheDeclaration(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe'      => '<https://example.com/u/abc>, <mailto:leave@example.com>',
            'list-unsubscribe-post' => 'List-Unsubscribe=One-Click',
        ]));

        self::assertNotNull($options);
        self::assertSame('https://example.com/u/abc', $options->oneClickUrl);
        self::assertSame('https://example.com/u/abc', $options->webUrl);
        self::assertSame('mailto:leave@example.com', $options->mailto);
        self::assertSame('example.com', $options->oneClickHost());
    }

    public function testWithoutTheDeclarationTheAddressIsOnlyAPage(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe' => '<https://example.com/u/abc>',
        ]));

        self::assertNotNull($options);
        self::assertNull($options->oneClickUrl);
        self::assertSame('https://example.com/u/abc', $options->webUrl);
    }

    public function testThePostHeaderIsReadCaseAndSpaceInsensitively(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe'      => '<https://example.com/u/abc>',
            'list-unsubscribe-post' => 'list-unsubscribe = one-click',
        ]));

        self::assertSame('https://example.com/u/abc', $options?->oneClickUrl);
    }

    public function testAPlainHttpAddressIsNeverOneClick(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe'      => '<http://example.com/u/abc>',
            'list-unsubscribe-post' => 'List-Unsubscribe=One-Click',
        ]));

        self::assertNotNull($options);
        self::assertNull($options->oneClickUrl, 'a cleartext POST at an address a stranger chose');
        self::assertSame('http://example.com/u/abc', $options->webUrl);
    }

    public function testAFoldedHeaderIsPutBackTogether(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe' => "<https://example.com/unsub\r\n scribe/abc>",
        ]));

        self::assertSame('https://example.com/unsubscribe/abc', $options?->webUrl);
    }

    public function testAHeaderSeenTwiceIsTreatedAsOne(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe' => ['<mailto:leave@example.com>', '<https://example.com/u/abc>'],
        ]));

        self::assertSame('mailto:leave@example.com', $options?->mailto);
        self::assertSame('https://example.com/u/abc', $options->webUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEntries(): iterable
    {
        yield 'script'        => ['<javascript:alert(1)>'];
        yield 'data URI'      => ['<data:text/html;base64,PHNjcmlwdD4=>'];
        yield 'ftp'           => ['<ftp://example.com/leave>'];
        yield 'not an address' => ['<mailto:not an address>'];
        yield 'empty mailto'  => ['<mailto:>'];
        yield 'no brackets'   => ['https://example.com/u/abc'];
        yield 'empty'         => ['<>'];
    }

    #[DataProvider('refusedEntries')]
    public function testAnEntryThatIsNotSafeToOfferIsDropped(string $header): void
    {
        self::assertNull($this->resolver->forMessage($this->message(['list-unsubscribe' => $header])));
    }

    public function testControlCharactersMakeAnEntryUnusable(): void
    {
        self::assertNull($this->resolver->forMessage($this->message([
            'list-unsubscribe' => "<https://example.com/u\x07/abc>",
        ])));
    }

    public function testAnOverlongAddressIsDropped(): void
    {
        self::assertNull($this->resolver->forMessage($this->message([
            'list-unsubscribe' => '<https://example.com/' . str_repeat('a', 2100) . '>',
        ])));
    }

    public function testTheMailtoSubjectAndBodyAreReadFromItsQuery(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe' => '<mailto:leave@example.com?subject=Remove%20me&body=Please%20stop>',
        ]));

        self::assertNotNull($options);
        self::assertSame('leave@example.com', $options->mailtoAddress());
        self::assertSame('Remove me', $options->mailtoSubject());
        self::assertSame('Please stop', $options->mailtoBody());
    }

    public function testAMailtoWithoutASubjectFallsBackToTheWordTheListsExpect(): void
    {
        $options = $this->resolver->forMessage($this->message([
            'list-unsubscribe' => '<mailto:leave@example.com>',
        ]));

        self::assertSame('unsubscribe', $options?->mailtoSubject());
        self::assertSame('', $options->mailtoBody());
    }

    public function testAMessageWithoutAnIdOffersNothing(): void
    {
        // Not yet persisted: there is no message for the one-click POST to be
        // made against.
        self::assertNull($this->resolver->forMessage($this->message(
            ['list-unsubscribe' => '<https://example.com/u/abc>'],
            id: null,
        )));
    }

    private function message(array $headers, ?int $id = 7, ?DateTimeImmutable $at = null): Message
    {
        $message          = new Message();
        $message->headers = $headers;
        $message->receivedAt = $at;

        (new ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }
}
