<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\ThreadRowAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a row's attachment chip says about a file: which icon, and how big.
 *
 * Both are read off the declared type with the extension as the fallback,
 * because a good deal of mail labels everything `application/octet-stream` and
 * the extension is then the only thing left that knows what the file is.
 */
final class ThreadRowAttachmentTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, string}> */
    public static function icons(): iterable
    {
        yield 'pdf by type'              => ['report.pdf', 'application/pdf', 'fa-file-pdf'];
        yield 'pdf by extension only'    => ['Invoice.PDF', 'application/octet-stream', 'fa-file-pdf'];
        yield 'png by type'              => ['photo.png', 'image/png', 'fa-file-image'];
        yield 'image by extension only'  => ['IMG_0001.HEIC', 'application/octet-stream', 'fa-file-image'];
        yield 'video'                    => ['clip.mp4', 'video/mp4', 'fa-file-video'];
        yield 'audio'                    => ['note.m4a', 'audio/mp4', 'fa-file-audio'];
        yield 'zip by type'              => ['bundle.bin', 'application/x-zip-compressed', 'fa-file-zipper'];
        yield 'zip by extension'         => ['bundle.7z', 'application/octet-stream', 'fa-file-zipper'];
        yield 'word by type'             => ['letter', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'fa-file-word'];
        yield 'word by extension'        => ['letter.docx', 'application/octet-stream', 'fa-file-word'];
        yield 'excel by type'            => ['budget', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'fa-file-excel'];
        yield 'csv by extension'         => ['export.csv', 'application/octet-stream', 'fa-file-excel'];
        yield 'powerpoint by type'       => ['deck', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'fa-file-powerpoint'];
        yield 'plain text'               => ['notes.txt', 'text/plain', 'fa-file-lines'];
        yield 'unknown'                  => ['mystery.xyz', 'application/octet-stream', 'fa-file'];
        yield 'no name, no type'         => ['', null, 'fa-file'];
    }

    #[DataProvider('icons')]
    public function testTheIconFollowsTheKindOfFile(string $filename, ?string $contentType, string $expected): void
    {
        self::assertSame($expected, $this->file($filename, $contentType)->icon());
    }

    public function testAViewablePdfKeepsThePdfIconWhateverItsTypeSaysItIs(): void
    {
        $file = new ThreadRowAttachment(1, 'scan', 'application/octet-stream', 10, 'pdf', false);

        self::assertSame('fa-file-pdf', $file->icon());
    }

    /** @return iterable<string, array{?int, string}> */
    public static function sizes(): iterable
    {
        yield 'unknown'     => [null, ''];
        yield 'negative'    => [-5, ''];
        yield 'empty'       => [0, '0 B'];
        yield 'bytes'       => [812, '812 B'];
        yield 'one KB'      => [1024, '1.0 KB'];
        yield 'fractional'  => [14643, '14.3 KB'];
        yield 'megabytes'   => [2 * 1024 * 1024, '2.0 MB'];
        yield 'gigabytes'   => [5 * 1024 * 1024 * 1024, '5.0 GB'];
    }

    #[DataProvider('sizes')]
    public function testTheSizeIsReadable(?int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->file('f', 'application/pdf', $bytes)->humanSize());
    }

    private function file(string $filename, ?string $contentType, ?int $size = 100): ThreadRowAttachment
    {
        return new ThreadRowAttachment(1, $filename, $contentType, $size, null, false);
    }
}
