<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Pageview serveur automatique sur kernel.terminate : la réponse est déjà
 * partie chez le visiteur, l'envoi n'ajoute aucune latence perçue.
 *
 * Le contexte vient de l'objet Request (jamais des superglobales) : correct
 * sous RoadRunner/FrankenPHP/workers persistants, dans les tests, et aligné
 * sur les trusted proxies configurés dans l'application hôte. Le marqueur
 * d'exclusion suit la même règle : lu sur la Request, écrit sur la Response
 * pendant kernel.response, seule phase où un Set-Cookie part encore.
 */
final class TrackRequestListener
{
    public function __construct(private readonly Client $client) {}

    /**
     * Pose ou retire le marqueur d'exclusion demandé par l'URL.
     *
     * Sur kernel.response et pas sur kernel.terminate : à terminate la réponse
     * est déjà partie chez le visiteur, il y serait trop tard pour ajouter un
     * en-tête Set-Cookie. Cookie propriétaire, `path=/`, `samesite=lax`,
     * `secure` en https, cinq ans, et lisible par le traceur JS du même site :
     * c'est le même marqueur pour les deux modes de suivi.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $signal = self::optOutSignal($request);
        if ($signal === null) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if (! $signal) {
            $headers->clearCookie(
                Client::OPT_OUT_MARKER,
                '/',
                null,
                $request->isSecure(),
                false,
                Cookie::SAMESITE_LAX,
            );

            return;
        }

        $headers->setCookie(Cookie::create(
            Client::OPT_OUT_MARKER,
            '1',
            time() + Client::OPT_OUT_LIFETIME,
            '/',
            null,
            $request->isSecure(),
            false, // httpOnly : le traceur JS doit pouvoir lire le même marqueur
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

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

        // Le refus de la personne prime sur tout le reste : le marqueur
        // d'exclusion n'existe que pour arrêter la mesure.
        if (self::isOptedOut($request)) {
            return;
        }

        // Un préchargement annoncé par le navigateur n'est pas une visite : la
        // requête est réelle, la réponse aussi, mais personne ne la voit tant
        // que la navigation n'est pas confirmée. Lu sur la Request comme le
        // reste du contexte, jamais dans `$_SERVER`.
        if (Client::announcesPrefetch(
            $request->headers->get('Sec-Purpose'),
            $request->headers->get('Purpose'),
            $request->headers->get('X-Moz'),
        )) {
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

    /**
     * Le signal d'exclusion porté par l'URL, lu sur la Request.
     *
     * Passe par `all()` plutôt que par `get()` : un `?qm_ignore[]=1` ferait
     * lever une exception à `InputBag::get()`, et ce listener ne doit jamais
     * casser la réponse d'un site hôte.
     */
    private static function optOutSignal(Request $request): ?bool
    {
        $value = $request->query->all()[Client::OPT_OUT_MARKER] ?? null;

        return Client::optOutSignal(\is_string($value) ? $value : null);
    }

    /**
     * La personne est-elle hors mesure pour CETTE requête ?
     *
     * Le signal de l'URL prime sur le cookie déjà posé : la page qui pose le
     * refus n'est donc pas comptée, et celle qui le retire l'est de nouveau.
     * C'est exactement ce que fait le traceur JS, qui écrit son marqueur puis
     * relit le sien avant de décider.
     */
    private static function isOptedOut(Request $request): bool
    {
        $signal = self::optOutSignal($request);
        if ($signal !== null) {
            return $signal;
        }

        $cookie = $request->cookies->all()[Client::OPT_OUT_MARKER] ?? null;

        return Client::isOptedOut(\is_string($cookie) ? $cookie : null);
    }
}
