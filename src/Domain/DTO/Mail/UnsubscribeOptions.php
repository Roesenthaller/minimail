<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * The ways a sender has said a recipient may leave its list, read from one
 * message's `List-Unsubscribe` header (RFC 2369) and, for the first of them,
 * its `List-Unsubscribe-Post` companion (RFC 8058).
 *
 * Three fields rather than a list of URIs because they are three different
 * things to DO, and the button picks between them in a fixed order:
 *
 *  - {@see $oneClickUrl}: an https address the sender has promised will end the
 *    subscription on a bare POST. plMail makes that request itself, so leaving
 *    is one click and no page of the sender's is ever opened.
 *  - {@see $webUrl}: an address that needs a person at it — an unsubscribe page,
 *    often with a form. Opened in a new tab; plMail cannot finish it for them.
 *  - {@see $mailto}: an address to write to. Opens the composer, pre-filled.
 *
 * Any of them may be absent, and a sender that offers none has no options at
 * all — {@see UnsubscribeResolver} answers null for it rather than an empty
 * one, so "is there anything to show" is a null check.
 */
final readonly class UnsubscribeOptions
{
    public function __construct(
        public ?string $oneClickUrl,
        public ?string $webUrl,
        public ?string $mailto,
        /** The message these were read from; the one-click request is made against it. */
        public int $messageId,
    ) {
    }

    /** The host the one-click request goes to, for the confirmation to name. */
    public function oneClickHost(): ?string
    {
        if (null === $this->oneClickUrl) {
            return null;
        }

        $host = parse_url($this->oneClickUrl, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? $host : null;
    }

    /** The address a `mailto:` option writes to, or null when there is none. */
    public function mailtoAddress(): ?string
    {
        return $this->mailtoPart(null);
    }

    /** Its prefilled subject; "unsubscribe" when the sender set none. */
    public function mailtoSubject(): string
    {
        return $this->mailtoPart('subject') ?? 'unsubscribe';
    }

    /** Its prefilled body, or an empty string. */
    public function mailtoBody(): string
    {
        return $this->mailtoPart('body') ?? '';
    }

    /**
     * @param string|null $field a query field of the mailto URI, or null for the address itself
     */
    private function mailtoPart(?string $field): ?string
    {
        if (null === $this->mailto) {
            return null;
        }

        $rest = substr($this->mailto, strlen('mailto:'));
        [$address, $query] = array_pad(explode('?', $rest, 2), 2, '');

        if (null === $field) {
            $address = rawurldecode($address);

            return '' === $address ? null : $address;
        }

        parse_str($query, $fields);

        $value = $fields[$field] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
