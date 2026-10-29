<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Mail\SenderLogoDomain;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `sender_logo_url(address)`: where a row's avatar can ask for the sender's
 * logo, or null when it should not — the feature is switched off, or the
 * address belongs to no website worth asking (a mailbox provider, a malformed
 * address).
 */
final class SenderLogoExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(bool:SENDER_LOGOS)%')]
        private readonly bool $enabled,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('sender_logo_url', $this->url(...))];
    }

    public function url(?string $address): ?string
    {
        if (false === $this->enabled) {
            return null;
        }

        $domain = SenderLogoDomain::fromAddress($address);

        return null === $domain ? null : $this->urls->generate('app_mail_sender_logo', ['domain' => $domain]);
    }
}
