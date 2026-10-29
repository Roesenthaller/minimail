<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\UnsubscribeOptions;
use App\Entity\Mail\MessageThread;
use App\Service\Mail\UnsubscribeResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `unsubscribe_options(thread)` — how this conversation's sender says to leave
 * its list, or null when it does not say.
 *
 * A function rather than a controller variable, for the reason
 * `read_receipt_decision` is one: the conversation partial is rendered by the
 * full page and as a bare fragment into the reading pane, and a value threaded
 * through both is a value the next caller forgets.
 */
final class UnsubscribeExtension extends AbstractExtension
{
    public function __construct(
        private readonly UnsubscribeResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unsubscribe_options', $this->options(...)),
        ];
    }

    public function options(MessageThread $thread): ?UnsubscribeOptions
    {
        return $this->resolver->forThread($thread);
    }
}
