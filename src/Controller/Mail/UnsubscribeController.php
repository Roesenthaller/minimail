<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\Message;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\UnsubscribeClient;
use App\Service\Mail\UnsubscribeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The reading pane's Unsubscribe button, when the sender offered the one-click
 * kind (RFC 8058).
 *
 * The page and the mailto kinds need no endpoint: one is a link, the other
 * opens the composer. This is the only one that has the SERVER do something, so
 * it is the only one that is a POST with a token and an ownership check.
 *
 * The URL is read from the stored message, never from the request. The button
 * sends nothing but "this message", so a forged request can at most ask for the
 * request the mail itself already names — it cannot point our server at an
 * address of its choosing. That is the whole point of taking an id and not a
 * URL.
 *
 * Answers 200 with `{ok: true|false}` rather than an error status for a sender
 * that refused: the request to US succeeded, and the front end has a specific
 * sentence for "the sender did not accept it" that a generic failure toast
 * would hide.
 */
#[Route('/mail/message/{id}/unsubscribe', name: 'app_mail_unsubscribe', requirements: ['id' => '\d+'], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class UnsubscribeController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly UnsubscribeResolver $resolver,
        private readonly UnsubscribeClient   $client,
    ) {
    }

    public function __invoke(Request $request, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'ajax');

        $url = $this->resolver->forMessage($message)?->oneClickUrl;

        // No one-click address on this message: the page was rendered from
        // another one, or the header changed. Nothing to send.
        if (null === $url) {
            return new JsonResponse(['ok' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => $this->client->oneClick($url)]);
    }
}
