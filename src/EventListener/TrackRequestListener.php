<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Symfony\EventListener;

use LaBoiteACode\WebAnalytics\Client;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Pageview serveur automatique sur kernel.terminate : la réponse est déjà
 * partie chez le visiteur, l'envoi n'ajoute aucune latence perçue.
 */
final class TrackRequestListener
{
    public function __construct(private readonly Client $client)
    {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getMethod() === 'GET'
            && $response->isSuccessful()
            && !$request->isXmlHttpRequest()
            && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
        ) {
            $this->client->pageview();
        }
    }
}
