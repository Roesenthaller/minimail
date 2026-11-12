<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\LinkifyExtension;
use PHPUnit\Framework\TestCase;

final class LinkifyExtensionTest extends TestCase
{
    private function run(string $text): string
    {
        return (string) (new LinkifyExtension())->linkify($text);
    }

    public function testPlainTextIsEscapedAndUnchanged(): void
    {
        self::assertSame('a &lt;b&gt; &amp; c', $this->run('a <b> & c'));
    }

    public function testAnAddressBecomesALink(): void
    {
        $html = $this->run('Join: https://teams.microsoft.com/meet/123?p=abc now');

        self::assertStringContainsString('<a href="https://teams.microsoft.com/meet/123?p=abc"', $html);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        self::assertStringEndsWith('</a> now', $html);
    }

    public function testAnAddressInAngleBracketsDropsTheBrackets(): void
    {
        $html = $this->run('Need help?<https://aka.ms/JoinTeamsMeeting?omkt=en-US> | System');

        self::assertStringContainsString('<a href="https://aka.ms/JoinTeamsMeeting?omkt=en-US"', $html);
        self::assertStringContainsString('&lt;<a', $html);
        self::assertStringContainsString('</a>&gt; | System', str_replace('&gt;', '&gt;', $html));
    }

    public function testTrailingPunctuationStaysOutsideTheLink(): void
    {
        $html = $this->run('See https://example.com/page.');

        self::assertStringContainsString('href="https://example.com/page"', $html);
        self::assertStringEndsWith('</a>.', $html);
    }

    public function testOtherSchemesAreNotLinked(): void
    {
        self::assertStringNotContainsString('<a ', $this->run('javascript:alert(1) and ftp://example.com'));
    }

    public function testMarkupInsideAnAddressIsEscaped(): void
    {
        $html = $this->run('https://example.com/?q="><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }
}
