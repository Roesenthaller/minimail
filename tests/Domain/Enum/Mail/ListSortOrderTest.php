<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\ListSortOrder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The orders a folder list can be in, and what a click on a column heading asks
 * for next.
 *
 * What the tests guard is the pairing: every heading moves between exactly two
 * orders of its own column, every order belongs to one column, and every order
 * ends in the id, because a column sort ties constantly and paging an order that
 * is only partly determined shows a conversation twice and another not at all.
 */
final class ListSortOrderTest extends TestCase
{
    /** @return iterable<string, array{string, ListSortOrder, ListSortOrder}> */
    public static function clicks(): iterable
    {
        yield 'date, from newest'       => ['date', ListSortOrder::Newest, ListSortOrder::Oldest];
        yield 'date, from oldest'       => ['date', ListSortOrder::Oldest, ListSortOrder::Newest];
        yield 'date, from a column'     => ['date', ListSortOrder::SenderAsc, ListSortOrder::Newest];
        yield 'sender, first click'     => ['sender', ListSortOrder::Newest, ListSortOrder::SenderAsc];
        yield 'sender, second click'    => ['sender', ListSortOrder::SenderAsc, ListSortOrder::SenderDesc];
        yield 'sender, third click'     => ['sender', ListSortOrder::SenderDesc, ListSortOrder::SenderAsc];
        yield 'subject, first click'    => ['subject', ListSortOrder::Oldest, ListSortOrder::SubjectAsc];
        yield 'subject, second click'   => ['subject', ListSortOrder::SubjectAsc, ListSortOrder::SubjectDesc];
        yield 'files, first click'      => ['attachments', ListSortOrder::Newest, ListSortOrder::AttachmentsMost];
        yield 'files, second click'     => ['attachments', ListSortOrder::AttachmentsMost, ListSortOrder::AttachmentsLeast];
        yield 'files, third click'      => ['attachments', ListSortOrder::AttachmentsLeast, ListSortOrder::AttachmentsMost];
    }

    #[DataProvider('clicks')]
    public function testAHeadingMovesBetweenItsOwnTwoOrders(string $column, ListSortOrder $from, ListSortOrder $expected): void
    {
        self::assertSame($expected, $from->toggle($column));
    }

    public function testAColumnWithoutAHeadingAsksForNothing(): void
    {
        self::assertNull(ListSortOrder::Newest->toggle('size'));
        self::assertNull(ListSortOrder::Newest->toggle(''));
    }

    public function testEveryOrderBelongsToTheColumnThatCanReachIt(): void
    {
        foreach (ListSortOrder::cases() as $order) {
            $column = $order->column();

            self::assertTrue(
                $order === ListSortOrder::Newest->toggle($column) || $order === ListSortOrder::Newest->toggle($column)?->toggle($column),
                sprintf('"%s" is not reachable from the %s heading', $order->value, $column),
            );
        }
    }

    public function testOnlyTheTwoDateOrdersAreDateOrders(): void
    {
        $dates = array_values(array_filter(ListSortOrder::cases(), static fn (ListSortOrder $o): bool => $o->isDate()));

        self::assertSame([ListSortOrder::Newest, ListSortOrder::Oldest], $dates);
    }

    public function testTheArrowPointsDownForNewestZToAAndMostFiles(): void
    {
        $down = array_values(array_filter(ListSortOrder::cases(), static fn (ListSortOrder $o): bool => $o->isDescending()));

        self::assertSame(
            [ListSortOrder::Newest, ListSortOrder::SenderDesc, ListSortOrder::SubjectDesc, ListSortOrder::AttachmentsMost],
            $down,
        );
    }

    public function testEveryOrderHasALabelKeyOfItsOwn(): void
    {
        $keys = array_map(static fn (ListSortOrder $o): string => $o->transKey(), ListSortOrder::cases());

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertSame('list.sort.attachments_asc', ListSortOrder::AttachmentsLeast->transKey());
    }

    public function testAHandEditedAddressFallsBackToNewest(): void
    {
        self::assertSame(ListSortOrder::SenderDesc, ListSortOrder::fromSetting('sender_desc'));
        self::assertSame(ListSortOrder::Newest, ListSortOrder::fromSetting('sender; DROP TABLE'));
        self::assertSame(ListSortOrder::Newest, ListSortOrder::fromSetting(['sender']));
        self::assertSame(ListSortOrder::Newest, ListSortOrder::fromSetting(null));
    }

    /** @return iterable<string, array{ListSortOrder, list<string>}> */
    public static function orderings(): iterable
    {
        yield 'newest'    => [ListSortOrder::Newest, ['t.lastMessageAt DESC', 't.id DESC']];
        yield 'oldest'    => [ListSortOrder::Oldest, ['t.lastMessageAt ASC', 't.id ASC']];
        yield 'files'     => [ListSortOrder::AttachmentsMost, ['t.attachmentCount DESC', 't.lastMessageAt DESC', 't.id DESC']];
        yield 'no files'  => [ListSortOrder::AttachmentsLeast, ['t.attachmentCount ASC', 't.lastMessageAt DESC', 't.id DESC']];
        yield 'subject'   => [ListSortOrder::SubjectAsc, ['sortSubject ASC', 't.lastMessageAt DESC', 't.id DESC']];
        yield 'subject Z' => [ListSortOrder::SubjectDesc, ['sortSubject DESC', 't.lastMessageAt DESC', 't.id DESC']];
        yield 'sender'    => [ListSortOrder::SenderAsc, ['sortSender ASC', 't.lastMessageAt DESC', 't.id DESC']];
        yield 'sender Z'  => [ListSortOrder::SenderDesc, ['sortSender DESC', 't.lastMessageAt DESC', 't.id DESC']];
    }

    /** @param list<string> $expected */
    #[DataProvider('orderings')]
    public function testEveryOrderEndsInTheIdSoPagingIsStable(ListSortOrder $order, array $expected): void
    {
        $qb = $this->builder();

        $order->applyTo($qb);

        self::assertSame($expected, array_map('strval', $qb->getDQLPart('orderBy')));
    }

    public function testTheTwoTextOrdersSelectTheirKeyHiddenSoDistinctStillWorks(): void
    {
        $sender  = $this->builder();
        $subject = $this->builder();

        ListSortOrder::SenderAsc->applyTo($sender);
        ListSortOrder::SubjectAsc->applyTo($subject);
        ListSortOrder::Newest->applyTo($subject);

        $senderSelect  = array_map('strval', $sender->getDQLPart('select'));
        $subjectSelect = array_map('strval', $subject->getDQLPart('select'));

        self::assertTrue(str_ends_with($senderSelect[0], ' AS HIDDEN sortSender'));
        self::assertTrue(str_contains($senderSelect[0], 'sx.thread = t AND'));
        self::assertTrue(str_ends_with($subjectSelect[0], ' AS HIDDEN sortSubject'));
        self::assertCount(1, $subjectSelect, 'a date order selects nothing extra');
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->createStub(EntityManagerInterface::class));
    }
}
