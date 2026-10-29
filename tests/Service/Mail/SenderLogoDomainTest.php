<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\SenderLogoDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the sender-logo route is willing to look up.
 *
 * The address comes out of a stranger's mail and becomes a host this server
 * connects to, so the shape of what passes is the first of two lines of defence
 * (the second, on the resolved addresses, is ImageProxyFetcher's). The table of
 * refusals is therefore the part worth reading: each row is a way somebody could
 * try to aim the lookup somewhere it should not go, or a case where a logo would
 * be a lie.
 */
final class SenderLogoDomainTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function addresses(): iterable
    {
        yield 'plain'                      => ['news@example.com', 'example.com'];
        yield 'upper case'                 => ['News@Example.COM', 'example.com'];
        yield 'subdomain is kept'          => ['bounce@mail.example.com', 'mail.example.com'];
        yield 'trailing dot'               => ['a@example.com.', 'example.com'];
        yield 'last at sign counts'        => ['"a@b"@example.com', 'example.com'];
        yield 'reserved example suffix'      => ['a@xn--bcher-kva.example', null];
        yield 'idn top level'              => ['a@example.xn--p1ai', 'example.xn--p1ai'];

        yield 'no address'                 => [null, null];
        yield 'empty'                      => ['', null];
        yield 'no at sign'                 => ['example.com', null];
        yield 'nothing after the at sign'  => ['a@', null];
        yield 'no dot'                     => ['a@localhost', null];
        yield 'bare IPv4'                  => ['a@127.0.0.1', null];
        yield 'bracketed IP'               => ['a@[10.0.0.1]', null];
        yield 'IPv6'                       => ['a@[::1]', null];
        yield 'internal suffix'            => ['a@printer.local', null];
        yield 'internal suffix 2'          => ['a@db.internal', null];
        yield 'port smuggled in'           => ['a@example.com:8080', null];
        yield 'path smuggled in'           => ['a@example.com/x', null];
        yield 'userinfo smuggled in'       => ['a@evil.com@example.com', 'example.com'];
        yield 'space'                      => ['a@exa mple.com', null];
        yield 'newline'                    => ["a@example.com\nHost: x", null];
        yield 'underscore'                 => ['a@ex_ample.com', null];
        yield 'leading hyphen'             => ['a@-example.com', null];
        yield 'numeric top level'          => ['a@example.123', null];

        yield 'gmail'                      => ['someone@gmail.com', null];
        yield 'outlook'                    => ['someone@outlook.com', null];
        yield 'yahoo uk'                   => ['someone@yahoo.co.uk', null];
        yield 'icloud'                     => ['someone@iCloud.com', null];
        yield 'a company on a similar name' => ['someone@gmail.example.com', 'gmail.example.com'];
    }

    #[DataProvider('addresses')]
    public function testOnlyAnAddressOnAWebsiteGivesADomain(?string $address, ?string $expected): void
    {
        self::assertSame($expected, SenderLogoDomain::fromAddress($address));
    }

    public function testAnOverlongNameIsRefused(): void
    {
        $label = str_repeat('a', 63);

        self::assertNull(SenderLogoDomain::normalise(implode('.', [$label, $label, $label, $label, 'com'])));
        self::assertNull(SenderLogoDomain::normalise(str_repeat('a', 64) . '.com'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function candidates(): iterable
    {
        yield 'a bare domain asks for itself'        => ['example.com', ['example.com']];
        yield 'a mail subdomain also asks its site'  => ['mail.example.com', ['mail.example.com', 'example.com']];
        yield 'one step up and no more'              => ['a.b.example.com', ['a.b.example.com', 'b.example.com']];
        yield 'a country registry is not a brand'    => ['bbc.co.uk', ['bbc.co.uk']];
        yield 'under one'                            => ['news.bbc.co.uk', ['news.bbc.co.uk', 'bbc.co.uk']];
        yield 'a parent that is a mailbox provider'  => ['mail.gmail.com', ['mail.gmail.com']];
    }

    /** @param list<string> $expected */
    #[DataProvider('candidates')]
    public function testTheHostsAskedAreTheNameAndItsSite(string $domain, array $expected): void
    {
        self::assertSame($expected, SenderLogoDomain::candidates($domain));
    }
}
