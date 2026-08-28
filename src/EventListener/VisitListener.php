<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\EventListener;

use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * La fenetre de continuite de visite, ouverte ou prolongee a chaque hit
 * mesure.
 *
 * `qm_visit=1` vaut la meme chose chez tout le monde : il n'identifie
 * personne, il dit seulement qu'une visite est deja en cours sur ce
 * navigateur. Sans lui, une empreinte visiteur qui change EN COURS DE VISITE
 * (passage de la 4G au wifi) fait compter la meme personne comme deux
 * visiteurs uniques le meme jour. TrackRequestListener le reporte ensuite
 * dans la cle `c` du hit.
 *
 * Sur kernel.response et pas sur kernel.terminate : a terminate la reponse est
 * deja partie chez le visiteur, il y serait trop tard pour un Set-Cookie.
 * C'est la meme contrainte de phase que pour le marqueur d'exclusion, d'ou le
 * meme decoupage en deux listeners.
 *
 * A LA DIFFERENCE d'OptOutListener, celui-ci n'est enregistre que lorsque la
 * page vue automatique est active, et c'est voulu : ce cookie accompagne un
 * hit mesure. Sans hit, il n'y a rien a accompagner, et l'ecrire quand meme
 * reviendrait a poser un cookie chez quelqu'un sans rien mesurer. Un
 * mecanisme de refus ne depend pas d'une option de mesure ; une continuite de
 * mesure, si.
 */
final class VisitListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // La fenetre suit le hit, pas la requete : meme decision qu'a
        // kernel.terminate, y compris le refus de la personne, qui interdit
        // d'ecrire quoi que ce soit chez elle.
        if (! TrackRequestListener::measures($request, $response)) {
            return;
        }

        // Cookie proprietaire, `path=/`, `samesite=lax`, `secure` en https,
        // dix minutes glissantes, et jamais httpOnly : le traceur JS du meme
        // site doit lire la meme fenetre, sinon le mode « les deux » en
        // ouvrirait une seconde et recompterait la personne.
        $response->headers->setCookie(Cookie::create(
            Client::VISIT_MARKER,
            '1',
            time() + Client::VISIT_LIFETIME,
            '/',
            null,
            $request->isSecure(),
            false, // httpOnly : le traceur JS doit lire la meme fenetre
            false,
            Cookie::SAMESITE_LAX,
        ));
    }
}
