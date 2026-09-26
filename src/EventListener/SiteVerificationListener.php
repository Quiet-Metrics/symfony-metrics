<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * La preuve de propriété du site, servie sur `/.well-known/quietmetrics.json`.
 *
 * L'onglet SEO de Quiet Metrics n'explore que les sites qui prouvent
 * appartenir au compte qui les a déclarés. Le document (voir
 * Client::siteVerificationDocument()) porte un HMAC de la clé secrète : seul
 * le détenteur de cette clé peut le produire.
 *
 * Sur kernel.request, AVANT le routeur : l'application n'a pas à déclarer de
 * route, et sans la réponse posée ici le routeur lèverait son 404. Toujours
 * enregistré, parce que `seo_crawl` peut venir d'un `%env(bool:...)%` qui
 * n'est résolu qu'à l'exécution : c'est le client, à la requête, qui décide.
 * Tant qu'il n'a pas de document (option éteinte ou clé secrète absente),
 * rien n'est intercepté et la requête suit son cours comme si le bundle
 * n'existait pas.
 *
 * Dépend de Client et non de Tracker : un Tracker remplacé en test n'a pas de
 * document à servir, et la preuve de propriété n'a rien d'une mesure.
 */
final class SiteVerificationListener
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest() || ! self::targets($event->getRequest())) {
            return;
        }

        $document = $this->client->siteVerificationDocument();
        if ($document === null) {
            return;
        }

        // Pour un HEAD, Symfony retire lui-même le corps en préparant la réponse.
        $event->setResponse(new Response($document, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            // Jamais mis en cache : couper l'option ou changer de clé doit
            // prendre effet au prochain passage de la plateforme.
            'Cache-Control' => 'no-store',
        ]));
    }

    /** Un GET ou un HEAD sur le chemin exact, la chaîne de requête mise à part. */
    public static function targets(Request $request): bool
    {
        return ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->getPathInfo() === Client::SITE_VERIFICATION_PATH;
    }
}
