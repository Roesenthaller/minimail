<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `unread_messages(account)`: how many received, unread emails an account has
 * in its Inbox, counted one per email, for the badge on the account's Messages
 * row in the sidebar. Worked out when the sidebar is drawn, so it is as fresh
 * as the page and does not tick live like the folder badges.
 */
final class MessageCountsExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('unread_messages', $this->unread(...))];
    }

    public function unread(Account $account): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->messages->countReceivedForUser($user, true, $account) : 0;
    }
}
