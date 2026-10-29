<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Mail\ThreadRowAttachment;
use App\Service\Mail\AttachmentThumbnailer;
use App\Service\Mail\ThreadRows;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which files a conversation row names, and how they are described.
 *
 * The row shows two chips and a hover card, so what matters is that the list
 * it is handed is honest: a file that returns on every reply is shown once, two
 * different files that happen to share a name are shown twice, and the same
 * decision about "can this be viewed" is made here as in the conversation.
 *
 * attachmentsOf() is private and takes the rows the list query answers with, so
 * it is reached directly; building ThreadRows through its constructor would
 * need four repositories to answer a question that none of them is asked.
 */
final class ThreadRowsAttachmentsTest extends TestCase
{
    private ThreadRows $rows;

    private AttachmentThumbnailer $thumbnailer;

    protected function setUp(): void
    {
        // canPreview() reads none of the thumbnailer's collaborators.
        $this->thumbnailer = (new ReflectionClass(AttachmentThumbnailer::class))->newInstanceWithoutConstructor();

        $this->rows = (new ReflectionClass(ThreadRows::class))->newInstanceWithoutConstructor();

        // Initialised from inside the class's own scope, which is the only way a
        // readonly property can be given a value without running the constructor.
        (function (AttachmentThumbnailer $thumbnailer): void {
            $this->thumbnailer = $thumbnailer;
        })->call($this->rows, $this->thumbnailer);
    }

    public function testAFileThatComesBackOnEveryReplyIsShownOnce(): void
    {
        $files = $this->attachmentsOf([
            $this->part(1, 'Quote.pdf', 'application/pdf', 5000),
            $this->part(2, 'quote.PDF', 'application/pdf', 5000),
            $this->part(3, 'Quote.pdf', 'application/pdf', 5000),
        ]);

        self::assertCount(1, $files);
        self::assertSame(1, $files[0]->id, 'the first one is kept, which is the oldest');
    }

    public function testTwoFilesWithOneNameButDifferentSizesAreTwoDocuments(): void
    {
        $files = $this->attachmentsOf([
            $this->part(1, 'Invoice.pdf', 'application/pdf', 5000),
            $this->part(2, 'Invoice.pdf', 'application/pdf', 5200),
        ]);

        self::assertCount(2, $files);
    }

    public function testFilesWithoutANameAreNeverTakenForEachOther(): void
    {
        $files = $this->attachmentsOf([
            $this->part(1, null, 'application/octet-stream', 100),
            $this->part(2, null, 'application/octet-stream', 100),
        ]);

        self::assertCount(2, $files);
        self::assertSame('', $files[0]->filename);
    }

    public function testARowNamesNoMoreFilesThanItCanUse(): void
    {
        $parts = [];

        for ($id = 1; $id <= 40; ++$id) {
            $parts[] = $this->part($id, "file-{$id}.zip", 'application/zip', $id * 10);
        }

        self::assertCount(12, $this->attachmentsOf($parts));
    }

    public function testTheViewerIsChosenTheWayTheConversationChoosesIt(): void
    {
        $files = $this->attachmentsOf([
            $this->part(1, 'plan.pdf', 'application/pdf', 100),
            $this->part(2, 'scan', 'application/octet-stream', 100),
            $this->part(3, 'photo.png', 'image/png', 100),
            $this->part(4, 'clip.mp4', 'application/octet-stream', 100),
            $this->part(5, 'bundle.zip', 'application/zip', 100),
            $this->part(6, 'sealed.pdf', 'application/pdf', 100, sealed: true),
        ]);

        self::assertSame(['pdf', null, 'image', 'video', null, null], array_map(
            static fn (ThreadRowAttachment $file): ?string => $file->previewKind,
            $files,
        ));
    }

    public function testASealedFileHasNothingToPreviewAndNoPicture(): void
    {
        [$file] = $this->attachmentsOf([$this->part(1, 'secret.png', 'image/png', 100, sealed: true)]);

        self::assertNull($file->previewKind);
        self::assertFalse($file->hasThumbnail);
    }

    public function testAThumbnailIsOfferedExactlyWhenTheThumbnailerWouldMakeOne(): void
    {
        [$small, $huge] = $this->attachmentsOf([
            $this->part(1, 'a.png', 'image/png', 1000),
            $this->part(2, 'b.png', 'image/png', 50 * 1024 * 1024),
        ]);

        // Whether GD is installed decides the first answer on a given machine,
        // so it is compared with the rule rather than pinned.
        self::assertSame($this->thumbnailer->canPreview('image/png', 1000), $small->hasThumbnail);
        self::assertFalse($huge->hasThumbnail, 'over the thumbnailer\'s byte ceiling');
    }

    public function testAPartOfUnknownShapeDoesNotBreakTheRow(): void
    {
        $files = $this->attachmentsOf([
            ['id' => 9, 'messageId' => 1, 'filename' => null, 'contentType' => null, 'size' => null, 'sealed' => false],
        ]);

        self::assertCount(1, $files);
        self::assertNull($files[0]->contentType);
        self::assertNull($files[0]->size);
        self::assertSame('', $files[0]->humanSize());
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return list<ThreadRowAttachment>
     */
    private function attachmentsOf(array $parts): array
    {
        $method = new ReflectionMethod(ThreadRows::class, 'attachmentsOf');

        /** @var list<ThreadRowAttachment> */
        return $method->invoke($this->rows, $parts);
    }

    /** @return array<string, mixed> */
    private function part(int $id, ?string $filename, string $contentType, ?int $size, bool $sealed = false): array
    {
        return [
            'id'          => $id,
            'messageId'   => 1,
            'filename'    => $filename,
            'contentType' => $contentType,
            'size'        => $size,
            'sealed'      => $sealed,
        ];
    }
}
