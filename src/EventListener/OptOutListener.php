<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Le marqueur d'exclusion, pose ou retire a la demande de la personne.
 *
 * VOLONTAIREMENT SEPARE de TrackRequestListener, et enregistre meme quand
 * `auto_pageview` vaut false. Les deux ont d'abord vecu ensemble, et c'etait
 * un defaut : couper la page vue automatique coupait aussi la possibilite de
 * se retirer. La LECTURE du refus continuait pourtant de fonctionner, le SDK
 * coeur lisant `$_COOKIE`, si bien qu'un visiteur restait exclu s'il s'etait
 * retire ailleurs mais ne pouvait plus le faire ici. Un mecanisme de refus ne
 * se desactive pas avec une option de confort.
 *
 * Sur kernel.response et pas sur kernel.terminate : a terminate la reponse est
 * deja partie chez le visiteur, il y serait trop tard pour un Set-Cookie.
 *
 * Le contexte vient de l'objet Request, jamais des superglobales : correct
 * sous RoadRunner, FrankenPHP et tout worker persistant, ou `$_GET` peut
 * appartenir a une requete precedente.
 */
final class OptOutListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $signal = self::signal($request);
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
            false, // httpOnly : le traceur JS doit pouvoir lire le meme marqueur
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * Le signal d'exclusion porte par l'URL, lu sur la Request.
     *
     * Passe par `all()` plutot que par `get()` : un `?qm_ignore[]=1` ferait
     * lever une exception a `InputBag::get()`, et rien ici ne doit casser la
     * reponse d'un site hote.
     */
    public static function signal(Request $request): ?bool
    {
        $value = $request->query->all()[Client::OPT_OUT_MARKER] ?? null;

        return Client::optOutSignal(\is_string($value) ? $value : null);
    }

    /**
     * La personne est-elle hors mesure pour CETTE requete ?
     *
     * Le signal de l'URL prime sur le cookie deja pose : la page qui pose le
     * refus n'est donc pas comptee, et celle qui le retire l'est de nouveau.
     * C'est exactement ce que fait le traceur JS, qui ecrit son marqueur puis
     * relit le sien avant de decider.
     */
    public static function isOptedOut(Request $request): bool
    {
        $signal = self::signal($request);
        if ($signal !== null) {
            return $signal;
        }

        $cookie = $request->cookies->all()[Client::OPT_OUT_MARKER] ?? null;

        return Client::isOptedOut(\is_string($cookie) ? $cookie : null);
    }
}
