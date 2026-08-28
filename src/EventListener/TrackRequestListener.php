<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Pageview serveur automatique sur kernel.terminate : la réponse est déjà
 * partie chez le visiteur, l'envoi n'ajoute aucune latence perçue.
 *
 * Le contexte vient de l'objet Request (jamais des superglobales) : correct
 * sous RoadRunner/FrankenPHP/workers persistants, dans les tests, et aligné
 * sur les trusted proxies configurés dans l'application hôte.
 *
 * Ce listener HONORE le marqueur d'exclusion mais ne le pose pas : le poser
 * appartient à OptOutListener, enregistré même quand la page vue automatique
 * est coupée. Un mécanisme de refus ne dépend pas d'une option de mesure.
 */
final class TrackRequestListener
{
    public function __construct(private readonly Client $client) {}

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (! self::measures($request, $response)) {
            return;
        }

        $lang = trim(explode(',', (string) $request->headers->get('Accept-Language'))[0]);

        $this->client->pageview([
            'url' => $request->getUri(),
            'referrer' => $request->headers->get('referer'),
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent'),
            'lang' => $lang !== '' ? substr($lang, 0, 5) : null,
            // Ce que le NAVIGATEUR a envoyé, et non le cookie que VisitListener
            // vient de poser sur la réponse : `c` doit dire l'état au moment du
            // hit, sinon tout hit se déclarerait en visite continue.
            'visit' => self::hasVisit($request),
        ]);
    }

    /**
     * Cette requête donne-t-elle lieu à une page vue mesurée ?
     *
     * Publique et statique parce que VisitListener pose la même question sur
     * la phase réponse, pour décider d'ouvrir la fenêtre de visite. Une seule
     * définition, sinon les deux gestes finiraient par diverger et le cookie
     * s'écrirait sans hit, ou l'inverse.
     */
    public static function measures(Request $request, Response $response): bool
    {
        if ($request->getMethod() !== 'GET'
            || ! $response->isSuccessful()
            || $request->isXmlHttpRequest()
            || ! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
        ) {
            return false;
        }

        // Le refus de la personne prime sur tout le reste : le marqueur
        // d'exclusion n'existe que pour arrêter la mesure, et on n'écrit rien
        // chez quelqu'un qui a refusé.
        if (OptOutListener::isOptedOut($request)) {
            return false;
        }

        // Un préchargement annoncé par le navigateur n'est pas une visite : la
        // requête est réelle, la réponse aussi, mais personne ne la voit tant
        // que la navigation n'est pas confirmée. Lu sur la Request comme le
        // reste du contexte, jamais dans `$_SERVER`.
        return ! Client::announcesPrefetch(
            $request->headers->get('Sec-Purpose'),
            $request->headers->get('Purpose'),
            $request->headers->get('X-Moz'),
        );
    }

    /**
     * Une visite était-elle déjà en cours sur ce navigateur ?
     *
     * Passe par `all()` plutôt que par `get()`, comme OptOutListener : un
     * cookie nommé `qm_visit[]` ferait lever une exception à
     * `InputBag::get()`, et rien ici ne doit casser la réponse d'un site hôte.
     *
     * Lu sur la Request et jamais dans `$_COOKIE` : sous RoadRunner ou
     * FrankenPHP la superglobale peut appartenir à la requête précédente, et
     * la visite d'un visiteur serait recollée à celle du suivant.
     */
    private static function hasVisit(Request $request): bool
    {
        $cookie = $request->cookies->all()[Client::VISIT_MARKER] ?? null;

        return Client::hasVisit(\is_string($cookie) ? $cookie : null);
    }
}
