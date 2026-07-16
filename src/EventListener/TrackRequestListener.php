<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Pageview serveur automatique sur kernel.terminate : la réponse est déjà
 * partie chez le visiteur, l'envoi n'ajoute aucune latence perçue.
 *
 * Le contexte vient de l'objet Request (jamais des superglobales) : correct
 * sous RoadRunner/FrankenPHP/workers persistants, dans les tests, et aligné
 * sur les trusted proxies configurés dans l'application hôte.
 */
final class TrackRequestListener
{
    public function __construct(private readonly Client $client) {}

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getMethod() !== 'GET'
            || ! $response->isSuccessful()
            || $request->isXmlHttpRequest()
            || ! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
        ) {
            return;
        }

        $lang = trim(explode(',', (string) $request->headers->get('Accept-Language'))[0]);

        $this->client->pageview([
            'url' => $request->getUri(),
            'referrer' => $request->headers->get('referer'),
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent'),
            'lang' => $lang !== '' ? substr($lang, 0, 5) : null,
        ]);
    }
}
