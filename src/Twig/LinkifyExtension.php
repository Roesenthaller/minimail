<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

/**
 * `|linkify`: plain text with its web addresses made clickable.
 *
 * For text that arrived with an event or a message and is shown as text, such
 * as a calendar event's description, where a Teams or Zoom link was a dead
 * string. Everything is escaped, and only http and https addresses become
 * links: the text is somebody else's, so a `javascript:` address is left as
 * the plain words it is.
 */
final class LinkifyExtension extends AbstractExtension
{
    private const string URL = '~https?://[^\s<>"\']+~i';

    public function getFilters(): array
    {
        return [new TwigFilter('linkify', $this->linkify(...), ['is_safe' => ['html']])];
    }

    public function linkify(?string $text): Markup
    {
        preg_match_all(self::URL, (string) $text, $found, PREG_OFFSET_CAPTURE);

        $text   = (string) $text;
        $out    = '';
        $cursor = 0;

        foreach ($found[0] as [$match, $offset]) {
            $out .= $this->escape(substr($text, $cursor, $offset - $cursor));

            // Punctuation that ends a sentence or closes a bracket is not part of the address.
            $trailing = '';

            while ('' !== $match && 1 === preg_match('~[.,;:!?)\]]$~', $match)
                && (')' !== substr($match, -1) || substr_count($match, ')') > substr_count($match, '('))) {
                $trailing = $match[strlen($match) - 1] . $trailing;
                $match    = substr($match, 0, -1);
            }

            if (false !== filter_var($match, FILTER_VALIDATE_URL)) {
                $out .= '<a href="' . $this->escape($match) . '" target="_blank" rel="noopener noreferrer nofollow" class="text-accent hover:text-accent-strong underline">'
                    . $this->escape($match) . '</a>';
            } else {
                $out .= $this->escape($match);
            }

            $out   .= $this->escape($trailing);
            $cursor = $offset + strlen($match) + strlen($trailing);
        }

        return new Markup($out . $this->escape(substr($text, $cursor)), 'UTF-8');
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
