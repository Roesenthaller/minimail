<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * One file as a conversation ROW shows it: enough to draw a chip and a hover
 * card, and nothing that would need the message it came from.
 *
 * Scalars rather than a MessagePart for the reason ThreadRowMessage is scalars
 * rather than a Message — a page of fifty rows would otherwise hydrate every
 * part of every message on it to read a name and a size. The viewer and the
 * thumbnail are reached by the part's id, which the existing routes already
 * take, so nothing here needs a second way to fetch a file.
 */
final readonly class ThreadRowAttachment
{
    public function __construct(
        public int $id,
        /** As the sender named it; empty when they did not. The template supplies the fallback. */
        public string $filename,
        public ?string $contentType,
        public ?int $size,
        /** 'pdf', 'image' or 'video' when a viewer exists for it, else null (a click downloads). */
        public ?string $previewKind,
        /** Whether a thumbnail can be drawn — see AttachmentThumbnailer::canPreview(). */
        public bool $hasThumbnail,
    ) {
    }

    /**
     * A Font Awesome class for the file's kind, chosen from the declared type
     * and falling back on the extension, since mailers label a good deal of
     * mail `application/octet-stream`.
     */
    public function icon(): string
    {
        $type = strtolower((string) $this->contentType);
        $ext  = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));

        return match (true) {
            'pdf' === $this->previewKind || 'application/pdf' === $type || 'pdf' === $ext => 'fa-file-pdf',
            str_starts_with($type, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'svg', 'bmp', 'tif', 'tiff'], true) => 'fa-file-image',
            str_starts_with($type, 'video/') || in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv'], true) => 'fa-file-video',
            str_starts_with($type, 'audio/') || in_array($ext, ['mp3', 'wav', 'm4a', 'flac', 'ogg'], true) => 'fa-file-audio',
            in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'], true) || str_contains($type, 'zip') => 'fa-file-zipper',
            in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'pages'], true) || str_contains($type, 'word') => 'fa-file-word',
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv', 'numbers'], true) || str_contains($type, 'excel') || str_contains($type, 'spreadsheet') => 'fa-file-excel',
            in_array($ext, ['ppt', 'pptx', 'odp', 'key'], true) || str_contains($type, 'powerpoint') || str_contains($type, 'presentation') => 'fa-file-powerpoint',
            str_starts_with($type, 'text/') || in_array($ext, ['txt', 'md', 'log'], true) => 'fa-file-lines',
            default => 'fa-file',
        };
    }

    /** "812 B", "14.3 KB", "2.0 MB" — or an empty string when the size is not known. */
    public function humanSize(): string
    {
        if (null === $this->size || $this->size < 0) {
            return '';
        }

        if ($this->size < 1024) {
            return $this->size . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $this->size / 1024;
        $unit  = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return number_format($value, 1, '.', '') . ' ' . $units[$unit];
    }
}
