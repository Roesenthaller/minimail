<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * A sender's logo: the small icon their website offers at /favicon.ico.
 *
 * The request goes out from this server and never from the reader's browser,
 * through the same fetcher that loads remote images in mail — https only, port
 * 443, public addresses only, the connection pinned to the address that was
 * checked, no redirect followed unseen, nothing sent that identifies the reader
 * (see ImageProxyFetcher). What is added here is the part a logo needs and a
 * newsletter image does not:
 *
 *  - Misses are remembered. A list of fifty rows names perhaps thirty domains
 *    and most of them have no usable icon; without a note of that, every
 *    redraw would ask thirty servers the same question again, and each answer
 *    that does not come is a PHP worker held for up to ten seconds.
 *  - What is asked is limited to what SenderLogoDomain allows.
 *
 * Disclosure, because it is the cost of the feature: fetching an icon tells the
 * sender's host that this server exists and that mail from that domain has
 * reached it. It is the same thing a tracking pixel tells them, once per domain
 * per week rather than once per message and without the message's identity in
 * the URL — and it is why the feature has an off switch (SENDER_LOGOS).
 */
final readonly class SenderLogoFetcher
{
    /** How long a miss is believed before the site is asked again. */
    private const int MISS_TTL = 86400;

    public function __construct(
        private ImageProxyFetcher $fetcher,
        private string            $projectDir,
    ) {
    }

    /**
     * @return array{path: string, contentType: string}|null
     */
    public function fetch(string $domain): ?array
    {
        $domain = SenderLogoDomain::normalise($domain);

        if (null === $domain) {
            return null;
        }

        $missPath = sprintf('%s/var/sender-logos/%s.miss', $this->projectDir, hash('sha256', $domain));

        if (true === is_file($missPath) && (time() - (int) filemtime($missPath)) < self::MISS_TTL) {
            return null;
        }

        foreach (SenderLogoDomain::candidates($domain) as $host) {
            $logo = $this->fetcher->fetch('https://' . $host . '/favicon.ico');

            if (null !== $logo) {
                return $logo;
            }
        }

        $this->remember($missPath);

        return null;
    }

    private function remember(string $missPath): void
    {
        $dir = dirname($missPath);

        if (false === is_dir($dir) && false === @mkdir($dir, 0775, true) && false === is_dir($dir)) {
            return;
        }

        // Best effort. Failing to note a miss costs one more lookup.
        @touch($missPath);
    }
}
