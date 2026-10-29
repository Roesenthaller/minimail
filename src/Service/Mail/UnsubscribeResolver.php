<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\UnsubscribeOptions;
use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;

/**
 * Reads what a sender has said about leaving its list out of a message's
 * headers.
 *
 * Nothing here is fetched or sent. This only reads a header that was stored at
 * ingest, in the canonical lowercase form HeaderNormalizer gives every
 * provider's bag — so it is as true of Gmail and Outlook mail as of IMAP, and
 * costs no query beyond the message it is handed.
 *
 * What is NOT trusted, and how that shows in the code. The header is written by
 * whoever sent the mail, so every address in it is an unvalidated claim. Three
 * consequences:
 *
 *  - Only https, http and mailto are ever returned. A `javascript:` or `data:`
 *    entry in this header would otherwise become an `href` on our own page.
 *  - A `mailto:` is accepted only if its address is a valid one, because it
 *    pre-fills the composer and is passed through the composer's own
 *    validation again before anything is sent.
 *  - The one-click URL must be https. RFC 8058 says so, and it matters here:
 *    plMail makes that request from the server, and a plain-http POST would be
 *    a cleartext request fired at an address a stranger chose. The address is
 *    checked again, against the private ranges, when the request is made —
 *    see UnsubscribeClient.
 */
final class UnsubscribeResolver
{
    /** A URI in the header is cut off here: nothing legitimate is longer. */
    private const int MAX_URI_LENGTH = 2048;

    /**
     * What the conversation's newest message that offers anything says.
     *
     * The newest, not the first: a sender that has changed its list provider
     * since the thread began has only the later address working, and a mailbox
     * conversation is mostly one sender anyway. A thread with no message that
     * carries the header is simply not a list.
     */
    public function forThread(MessageThread $thread): ?UnsubscribeOptions
    {
        $candidates = [];

        foreach ($thread->messages as $message) {
            if (true === $message->isDraft()) {
                continue;
            }

            $candidates[] = $message;
        }

        usort(
            $candidates,
            static fn (Message $a, Message $b): int => self::stamp($b) <=> self::stamp($a),
        );

        foreach ($candidates as $message) {
            $options = $this->forMessage($message);

            if (null !== $options) {
                return $options;
            }
        }

        return null;
    }

    public function forMessage(Message $message): ?UnsubscribeOptions
    {
        $headers = $message->headers ?? [];

        $raw = self::headerText($headers['list-unsubscribe'] ?? null);

        if ('' === $raw || null === $message->id) {
            return null;
        }

        $https  = null;
        $http   = null;
        $mailto = null;

        foreach (self::uris($raw) as $uri) {
            $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));

            if ('https' === $scheme && null === $https) {
                $https = $uri;
            } elseif ('http' === $scheme && null === $http) {
                $http = $uri;
            } elseif ('mailto' === $scheme && null === $mailto && self::validMailto($uri)) {
                $mailto = $uri;
            }
        }

        $oneClick = null !== $https && self::declaresOneClick($headers['list-unsubscribe-post'] ?? null)
            ? $https
            : null;

        $web = $https ?? $http;

        if (null === $oneClick && null === $web && null === $mailto) {
            return null;
        }

        return new UnsubscribeOptions($oneClick, $web, $mailto, $message->id);
    }

    /**
     * RFC 8058 §3.1: the POST header must carry exactly the pair
     * `List-Unsubscribe=One-Click`. Matched case-insensitively and with spaces
     * tolerated, because bulk mailers are not tidy; a header that does not say
     * it simply means "open the page".
     */
    private static function declaresOneClick(mixed $value): bool
    {
        $text = strtolower((string) preg_replace('/\s+/', '', self::headerText($value)));

        return str_contains($text, 'list-unsubscribe=one-click');
    }

    /**
     * Every `<…>` entry, in the order written. Whitespace inside the brackets
     * is dropped rather than rejected: a long header is folded across lines,
     * and a fold can land in the middle of a URL.
     *
     * @return list<string>
     */
    private static function uris(string $raw): array
    {
        preg_match_all('/<([^<>]+)>/', $raw, $matches);

        $uris = [];

        foreach ($matches[1] as $candidate) {
            $uri = (string) preg_replace('/\s+/', '', $candidate);

            if ('' === $uri || strlen($uri) > self::MAX_URI_LENGTH) {
                continue;
            }

            // Control characters in a header value are an injection attempt or
            // a broken mailer, and neither is worth rendering as a link.
            if (1 === preg_match('/[\x00-\x1f\x7f]/', $uri)) {
                continue;
            }

            $uris[] = $uri;
        }

        return $uris;
    }

    private static function validMailto(string $uri): bool
    {
        $rest    = substr($uri, strlen('mailto:'));
        $address = rawurldecode(explode('?', $rest, 2)[0]);

        return AddressHelper::isValidEmail(AddressHelper::email($address));
    }

    /**
     * A header is stored as a string, or as a list of them when it appeared
     * more than once — see HeaderNormalizer.
     */
    private static function headerText(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(',', array_map(static fn (mixed $part): string => (string) $part, $value)));
        }

        return is_string($value) ? trim($value) : '';
    }

    /** When the message arrived or was sent, as a sortable number. */
    private static function stamp(Message $message): int
    {
        return ($message->receivedAt ?? $message->sentAt ?? $message->createdAt)?->getTimestamp() ?? 0;
    }
}
