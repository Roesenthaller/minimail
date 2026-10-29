<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\Mail\SenderLogoDomain;
use App\Service\Mail\SenderLogoFetcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A sender's logo for the avatar on a list row.
 *
 * Behind the login, unlike the remote-image proxy: this is requested by the
 * app's own page, which has the session, so there is nothing to sign. What
 * stops it being an open fetcher is the domain check and the fetcher's rules —
 * the route will look up a name that SenderLogoDomain accepts, and only on a
 * public address.
 *
 * Answers 404 for a domain with no usable icon, and says so for a day, so the
 * browser does not ask again for every row from the same sender. The page's
 * script treats the 404 as "keep the initial".
 */
final class SenderLogoController extends AbstractController
{
    public function __construct(
        private readonly SenderLogoFetcher $logos,
        #[Autowire('%env(bool:SENDER_LOGOS)%')]
        private readonly bool $enabled,
    ) {
    }

    #[Route('/mail/sender-logo/{domain}', name: 'app_mail_sender_logo', requirements: ['domain' => '[A-Za-z0-9.-]{4,253}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function logo(Request $request, string $domain): Response
    {
        if (false === $this->enabled) {
            return $this->missing();
        }

        // Let go of the session before the slow part. PHP's file sessions lock
        // for the whole request, and a list names dozens of domains at once:
        // held, each lookup that takes seconds would queue every other request
        // this person makes — opening a message included — behind it.
        $request->getSession()->save();

        $logo = SenderLogoDomain::normalise($domain) === $domain ? $this->logos->fetch($domain) : null;

        if (null === $logo) {
            return $this->missing();
        }

        $response = new BinaryFileResponse($logo['path']);

        // As for any picture a stranger's server gave us: the type is one we
        // checked, it is never sniffed into anything else, and it is sandboxed
        // if somebody opens it as a page.
        $response->headers->set('Content-Type', $logo['contentType']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'logo');
        $response->setPrivate();
        $response->setMaxAge(604800);

        return $response;
    }

    private function missing(): Response
    {
        $response = new Response('', Response::HTTP_NOT_FOUND);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
