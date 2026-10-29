<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use App\Entity\Mail\Message;
use Doctrine\ORM\QueryBuilder;

/**
 * What order a folder list comes back in.
 *
 * Separate from {@see SearchSortOrder} rather than shared with it, because
 * relevance is not an order a folder can be in: there is no query to be
 * relevant to, and an enum offering it here would put a dead option in every
 * menu. What the two share is the tiebreaker rule, and the reason for it —
 * see SearchSortOrder::orderBy(): LIMIT/OFFSET over a non-deterministic sort
 * may show the same conversation on two pages and some other on none, and
 * `lastMessageAt` ties are ordinary in a mailbox, not an edge case (an import,
 * a bulk sync, anything that lands a batch with one timestamp).
 */
enum ListSortOrder: string
{
    /** Newest first — what every mail client does, and the default. */
    case Newest = 'newest';

    /** Oldest first, for reading a backlog forwards. */
    case Oldest = 'oldest';

    // The orders below are the column headers on the wide list. Each column has
    // two, and a click on its heading moves between them: see toggledBy().

    /** By the first sender the row names, A to Z. */
    case SenderAsc = 'sender';

    case SenderDesc = 'sender_desc';

    /** By subject, A to Z. */
    case SubjectAsc = 'subject';

    case SubjectDesc = 'subject_desc';

    /** Conversations with the most files first. */
    case AttachmentsMost = 'attachments';

    /** Conversations with no files first. */
    case AttachmentsLeast = 'attachments_asc';

    /**
     * Apply this order to a thread query built over the alias `t`.
     *
     * A method on the enum rather than an ORDER BY spelled at each call site,
     * for the reason the six role views became one controller method: the
     * tiebreaker has to accompany the sort everywhere or it protects nothing,
     * and six copies is six chances to write only the first half.
     */
    public function applyTo(QueryBuilder $qb, string $alias = 't'): QueryBuilder
    {
        $newestFirst = self::Oldest !== $this;

        match ($this) {
            self::SenderAsc, self::SenderDesc => $qb
                ->addSelect($this->senderKey($alias) . ' AS HIDDEN sortSender')
                ->orderBy('sortSender', self::SenderAsc === $this ? 'ASC' : 'DESC'),
            self::SubjectAsc, self::SubjectDesc => $qb
                ->addSelect('LOWER(COALESCE(' . $alias . ".subject, '')) AS HIDDEN sortSubject")
                ->orderBy('sortSubject', self::SubjectAsc === $this ? 'ASC' : 'DESC'),
            self::AttachmentsMost  => $qb->orderBy($alias . '.attachmentCount', 'DESC'),
            self::AttachmentsLeast => $qb->orderBy($alias . '.attachmentCount', 'ASC'),
            default                => $qb->orderBy($alias . '.lastMessageAt', $newestFirst ? 'DESC' : 'ASC'),
        };

        // The date is the tiebreaker for every column, newest first, and the id
        // under that: a column sort ties constantly (a hundred threads from one
        // sender), and an order that is only partly determined is the paging
        // bug described above. Oldest-first keeps its own direction throughout.
        if (false === $this->isDate()) {
            $qb->addOrderBy($alias . '.lastMessageAt', 'DESC');
        }

        return $qb->addOrderBy($alias . '.id', $newestFirst ? 'DESC' : 'ASC');
    }

    /**
     * The sender the row names first, as a sortable value. The row lists
     * everyone who has written, oldest first, so the leftmost name is the
     * author of the first message that arrived or went out (an unsent draft of
     * the reader's own is not taken for it), by display name when it has one
     * and by address when it does not.
     *
     * A subquery in the select list and not a join, so the row count and the
     * page size stay what the caller built them to be.
     */
    private function senderKey(string $alias): string
    {
        $message = Message::class;

        return '(SELECT LOWER(COALESCE(NULLIF(sm.fromName, \'\'), sm.fromAddress, \'\')) FROM ' . $message . ' sm'
            . ' WHERE sm.id = (SELECT MIN(sx.id) FROM ' . $message . ' sx'
            . ' WHERE sx.thread = ' . $alias . ' AND (sx.receivedAt IS NOT NULL OR sx.sentAt IS NOT NULL)))';
    }

    /** Whether this is one of the two orders by date. */
    public function isDate(): bool
    {
        return self::Newest === $this || self::Oldest === $this;
    }

    /** The column a heading controls: 'date', 'sender', 'subject' or 'attachments'. */
    public function column(): string
    {
        return match ($this) {
            self::Newest, self::Oldest                 => 'date',
            self::SenderAsc, self::SenderDesc          => 'sender',
            self::SubjectAsc, self::SubjectDesc        => 'subject',
            self::AttachmentsMost, self::AttachmentsLeast => 'attachments',
        };
    }

    /**
     * The order a click on a column's heading asks for, from this one: the
     * column's first order when it is not the one in force, the other of its
     * two when it is. Null for a column that has no heading, which the caller
     * is not expected to ask about.
     *
     * An instance method so a template can call it on the order it holds,
     * `list_sort.toggle('sender')`, without reaching for the enum by name.
     */
    public function toggle(string $column): ?self
    {
        return match ($column) {
            'date'        => self::Newest === $this ? self::Oldest : self::Newest,
            'sender'      => self::SenderAsc === $this ? self::SenderDesc : self::SenderAsc,
            'subject'     => self::SubjectAsc === $this ? self::SubjectDesc : self::SubjectAsc,
            'attachments' => self::AttachmentsMost === $this ? self::AttachmentsLeast : self::AttachmentsMost,
            default       => null,
        };
    }

    /** Whether the arrow on this order's heading points down: Z to A, newest or most files first. */
    public function isDescending(): bool
    {
        return match ($this) {
            self::Newest, self::SenderDesc, self::SubjectDesc, self::AttachmentsMost => true,
            default => false,
        };
    }

    /** Translation key for this option's label in the sort menu. */
    public function transKey(): string
    {
        return 'list.sort.' . $this->value;
    }

    /**
     * Whatever was stored or typed, read charitably — the query string is
     * whatever somebody put there, and a hand-edited URL should show a list in
     * some order rather than a 500.
     */
    public static function fromSetting(mixed $value, self $default = self::Newest): self
    {
        return is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
