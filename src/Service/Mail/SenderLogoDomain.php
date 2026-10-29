<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Which website, if any, an email address stands for — the question a sender's
 * logo starts from.
 *
 * Pure string work with no network, so that what the server is willing to be
 * asked about is decided in one place that can be read and tested whole. The
 * address comes out of a stranger's mail and then, through the logo route,
 * becomes a host this server connects to: the shape is checked here, and the
 * addresses that host may resolve to are checked again, at connection time, by
 * ImageProxyFetcher.
 */
final class SenderLogoDomain
{
    /**
     * Mailbox providers: the domain says where someone keeps their mail, not
     * who they are. Shown a provider's own logo, every correspondent on it
     * would wear the same mark, and a list of fifty identical badges is worse
     * than the initials it replaced.
     *
     * Exact names. A suffix match would be cleverer and wrong in the cases that
     * matter: `mail.example.com` is a company, and `yahoo.co.uk` is a mailbox.
     *
     * @var list<string>
     */
    private const array MAILBOX_PROVIDERS = [
        'gmail.com', 'googlemail.com',
        'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk', 'msn.com',
        'yahoo.com', 'yahoo.co.uk', 'yahoo.de', 'yahoo.fr', 'ymail.com', 'rocketmail.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com', 'aim.com',
        'proton.me', 'protonmail.com', 'pm.me',
        'gmx.com', 'gmx.net', 'gmx.de', 'web.de', 'mail.com', 'zoho.com',
        'fastmail.com', 'fastmail.fm', 'hey.com', 'tutanota.com', 'tuta.com',
        'btinternet.com', 'sky.com', 'virginmedia.com', 'talktalk.net', 'ntlworld.com',
        'qq.com', '163.com', '126.com', 'yandex.com', 'yandex.ru', 'mail.ru',
    ];

    /**
     * Second-level names under which a country registers its own domains. For
     * `news.bbc.co.uk` the parent to try is `bbc.co.uk`, not `co.uk`, and the
     * second is a registry rather than a brand.
     *
     * Not the Public Suffix List and not trying to be: the cost of a miss is a
     * wrong parent that answers 404, and the cost of carrying the whole list is
     * a dependency for the sake of a favicon.
     *
     * @var list<string>
     */
    private const array REGISTRY_SUFFIXES = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'co.kr', 'co.in', 'co.za',
        'com.br', 'com.mx', 'com.cn', 'com.hk', 'com.sg', 'com.tr', 'com.ar',
    ];

    /** Letters, digits and hyphens in dot-separated labels; a final label that is not all digits. */
    private const string HOST_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * The domain whose logo stands for this address, or null for an address
     * that has none to offer: malformed, a bare IP, internal, or a mailbox
     * provider.
     */
    public static function fromAddress(?string $address): ?string
    {
        if (null === $address) {
            return null;
        }

        $at = strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        return self::normalise(substr($address, $at + 1));
    }

    /**
     * A domain as typed in a URL: lower case, no trailing dot, and only if it
     * is one the logo route is willing to look up. Null otherwise.
     */
    public static function normalise(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if (1 !== preg_match(self::HOST_PATTERN, $host)) {
            return null;
        }

        // A name with no dot has no logo of its own; a name ending in one of
        // these is the machine's own network rather than the open internet.
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.corp', '.intranet', '.invalid', '.test', '.example'] as $reserved) {
            if (str_ends_with($host, $reserved)) {
                return null;
            }
        }

        if (true === in_array($host, self::MAILBOX_PROVIDERS, true)) {
            return null;
        }

        return $host;
    }

    /**
     * The hosts to ask, in order: the one in the address, then the site it
     * belongs to. Mail comes from `mail.example.com` and `news.example.com`
     * while the logo lives at `example.com`.
     *
     * At most one step up, and only when something is left that is not a
     * registry: `a.b.example.com` asks for itself and `b.example.com`, not for
     * `example.com` — the nearer name is the likelier brand.
     *
     * @return list<string>
     */
    public static function candidates(string $domain): array
    {
        $labels = explode('.', $domain);
        $hosts  = [$domain];

        if (count($labels) >= 3) {
            $parent = implode('.', array_slice($labels, 1));

            if (false === in_array($parent, self::REGISTRY_SUFFIXES, true) && null !== self::normalise($parent)) {
                $hosts[] = $parent;
            }
        }

        return $hosts;
    }
}
