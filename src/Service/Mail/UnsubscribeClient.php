<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Makes the one request RFC 8058 defines: a POST of `List-Unsubscribe=One-Click`
 * to the address a sender's mail named, which the sender has promised ends the
 * subscription without a page, a login or a question.
 *
 * Done from the server because the alternative is the browser, and the browser
 * cannot: a cross-origin POST with that body is refused by CORS, and an open
 * tab would be the sender's page rather than the one-click the header promised.
 *
 * ── What our own server is willing to connect to ────────────────────────────
 * The address is taken from a mail header, which is the sender's to write. It is
 * therefore held to the same rules as the remote-image proxy, and for the same
 * reason — see ImageProxyFetcher: https on port 443 only, every address the
 * name resolves to public, and the connection pinned to the one that was
 * checked so a second DNS answer cannot redirect it. The check is
 * ImageProxyFetcher::checkedTarget(), not a copy of it.
 *
 * Redirects are NOT followed. The proxy follows up to three, re-checking each
 * hop; this does not need to, because an unsubscribe endpoint that answers with
 * a redirect is not doing what the header says it does, and the reader is told
 * so and offered the page instead. Refusing is also the smaller thing to get
 * wrong: a redirect is the standard way to move a request to an internal
 * address after the first check.
 *
 * Nothing identifying is sent: no cookies, no referer, and a fixed User-Agent.
 * The recipient's address is already in the URL when the sender wants it there.
 */
final readonly class UnsubscribeClient
{
    /** Whole-request budget. A slow sender must not hold a PHP worker open. */
    private const float MAX_DURATION = 10.0;

    private const float CONNECT_TIMEOUT = 4.0;

    private const string USER_AGENT = 'plMail-unsubscribe/1.0';

    /** The body RFC 8058 §3.1 specifies, byte for byte. */
    private const string BODY = 'List-Unsubscribe=One-Click';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ImageProxyFetcher   $targets,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Whether the sender accepted the request.
     *
     * Any 2xx counts — the RFC asks for success and nothing more specific. A
     * refusal, a redirect, a timeout and a network failure are one answer to
     * the caller, which says "that did not work" and offers the sender's page;
     * the reason goes to the log, never to the response, for the reason the
     * image proxy gives: "refused" against "timed out" at an internal address
     * is itself the port scan the target check exists to deny.
     */
    public function oneClick(string $url): bool
    {
        $target = $this->targets->checkedTarget($url);

        if (null === $target) {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'resolve'       => [$target['host'] => $target['ip']],
                'max_redirects' => 0,
                'timeout'       => self::CONNECT_TIMEOUT,
                'max_duration'  => self::MAX_DURATION,
                'headers'       => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => self::USER_AGENT,
                ],
                'body'          => self::BODY,
            ]);

            $status = $response->getStatusCode();

            // The answer's body is nothing we use, and an unsubscribe page can
            // be large. Dropping it here is also what stops a hostile endpoint
            // streaming bytes at a worker.
            $response->cancel();
        } catch (HttpClientException $exception) {
            // ROUTINE: the sender's endpoint is down, slow or has stopped
            // existing. Not a fault in plMail, and info is the right volume.
            $this->logger->info('UnsubscribeClient: request failed', [
                'host'      => $target['host'],
                'exception' => $exception,
            ]);

            return false;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->info('UnsubscribeClient: refused by the sender', [
                'host'   => $target['host'],
                'status' => $status,
            ]);

            return false;
        }

        return true;
    }
}
