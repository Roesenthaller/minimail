<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The choices for how a list row looks that a reader can make for themselves:
 * the sender disc's size, the colours it is drawn in, and the width of the
 * sender column. The first entry of each list is the default and what an
 * unknown or missing value becomes.
 */
final class ListDisplay
{
    /** @var list<string> small, the size it has been since the rows were tightened, and the original */
    public const array AVATAR_SIZES = ['medium', 'small', 'large'];

    /** @var list<string> the dark muted tones, the same hues lightly tinted, one quiet grey */
    public const array AVATAR_SCHEMES = ['muted', 'soft', 'neutral'];

    /** @var list<string> 10rem, 14rem, 18rem */
    public const array SENDER_WIDTHS = ['normal', 'wide', 'wider'];

    /**
     * @param list<string> $allowed
     */
    public static function choose(array $allowed, mixed $value): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}
