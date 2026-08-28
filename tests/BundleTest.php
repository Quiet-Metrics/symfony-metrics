<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\Tests;

use QuietMetrics\Client;
use QuietMetrics\Symfony\EventListener\OptOutListener;
use QuietMetrics\Symfony\EventListener\TrackRequestListener;
use QuietMetrics\Symfony\EventListener\VisitListener;
use QuietMetrics\Symfony\QuietMetricsBundle;
use QuietMetrics\Tests\CaptureServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Le bundle testé sans kernel complet (robuste et rapide) :
 * - câblage : compilation d'un ContainerBuilder avec l'extension du bundle ;
 * - comportement : listener réel contre le serveur de capture HTTP du cœur.
 */
final class BundleTest extends TestCase
{
    private static CaptureServer $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../vendor/quiet-metrics/php-metrics/tests/CaptureServer.php';
        self::$server = new CaptureServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config): ContainerBuilder
    {
        // Paramètres kernel minimaux requis par l'extension d'un AbstractBundle.
        $container = new ContainerBuilder(new \Symfony\Component\DependencyInjection\ParameterBag\ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => sys_get_temp_dir(),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));
        $extension = (new QuietMetricsBundle())->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $container->getCompilerPassConfig()->setRemovingPasses([]); // garde les services privés inspectables
        $container->compile();

        return $container;
    }

    public function test_l_extension_s_appelle_quiet_metrics_et_cable_le_client(): void
    {
        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'secret_key' => 'qm_sec_test',
            'endpoint' => 'https://collecte.exemple.fr/api/v1/collect',
        ]);

        $this->assertTrue($container->has(Client::class));

        $definition = $container->getDefinition(Client::class);
        $this->assertSame('qm_pub_test', $definition->getArgument(0));
        $this->assertSame('qm_sec_test', $definition->getArgument(1));
        $this->assertSame('https://collecte.exemple.fr/api/v1/collect', $definition->getArgument(2)['endpoint']);

        // Pageview auto : listener présent et branché sur kernel.terminate.
        $listener = $container->getDefinition(TrackRequestListener::class);
        $tags = $listener->getTag('kernel.event_listener');
        $this->assertSame('kernel.terminate', $tags[0]['event']);
        $this->assertSame('onKernelTerminate', $tags[0]['method']);

        // Le marqueur d'exclusion se pose pendant la phase reponse : sur
        // kernel.terminate la reponse est deja partie chez le visiteur, il y
        // serait trop tard pour ajouter un en-tete Set-Cookie.
        $this->assertCount(1, $tags, 'le listener de mesure ne porte plus que kernel.terminate');

        // Le marqueur d'exclusion se pose pendant la phase reponse, dans un
        // listener a lui : a kernel.terminate la reponse est deja partie chez
        // le visiteur, il y serait trop tard pour un Set-Cookie. Et il reste
        // enregistre quand la page vue automatique est coupee.
        $marqueur = $container->getDefinition(OptOutListener::class)->getTag('kernel.event_listener');
        $this->assertSame('kernel.response', $marqueur[0]['event']);
        $this->assertSame('onKernelResponse', $marqueur[0]['method']);
    }

    /**
     * Le refus ne depend pas de l'option de mesure.
     *
     * Le marqueur d'exclusion voyageait dans le meme listener que la page vue
     * automatique, donc `auto_pageview: false` le desactivait avec elle : sur
     * ces applications, `?qm_ignore=1` ne faisait rien. La LECTURE du refus
     * fonctionnait pourtant, le SDK coeur lisant `$_COOKIE`, si bien qu'un
     * visiteur ne pouvait plus se retirer mais restait exclu s'il l'avait fait
     * ailleurs. Un mecanisme de refus ne se desactive pas avec une option de
     * confort.
     */
    public function test_le_marqueur_reste_posable_sans_pageview_automatique(): void
    {
        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'auto_pageview' => false,
        ]);

        $this->assertFalse(
            $container->has(TrackRequestListener::class),
            'la page vue automatique reste bien desactivee',
        );

        $this->assertTrue(
            $container->has(OptOutListener::class),
            'le visiteur doit pouvoir poser son refus meme sans mesure automatique',
        );

        $tags = $container->getDefinition(OptOutListener::class)->getTag('kernel.event_listener');
        $this->assertSame('kernel.response', $tags[0]['event']);
    }

    public function test_auto_pageview_desactivable(): void
    {
        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'auto_pageview' => false,
        ]);

        $this->assertTrue($container->has(Client::class), 'les événements manuels restent possibles');
        $this->assertFalse($container->has(TrackRequestListener::class));

        // Sans clé endpoint configurée, on n'en passe pas au client :
        // l'endpoint par défaut du SDK cœur (SaaS Quiet Metrics) fait foi.
        $this->assertArrayNotHasKey('endpoint', $container->getDefinition(Client::class)->getArgument(2));
    }

    public function test_le_listener_envoie_la_pageview_sur_kernel_terminate(): void
    {
        $listener = new TrackRequestListener(new Client('qm_pub_test', 'qm_sec_test', [
            'endpoint' => self::$server->endpoint(),
            'async' => false,
        ]));

        $listener->onKernelTerminate(new TerminateEvent(
            $this->stubKernel(),
            Request::create('https://monsite.fr/tarifs', 'GET', server: ['HTTP_USER_AGENT' => 'NavigateurTest/1.0']),
            new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ));

        $request = self::$server->requests()[0];
        $payload = json_decode($request['body'], true);

        $this->assertSame('pageview', $payload['t']);
        $this->assertSame('https://monsite.fr/tarifs', $payload['u']);
        $this->assertSame('NavigateurTest/1.0', $payload['ua']);

        $timestamp = $request['headers']['x-qm-timestamp'];
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$request['body'], 'qm_sec_test'),
            $request['headers']['x-qm-signature'],
        );
    }

    /**
     * Un préchargement annoncé par le navigateur n'est pas une visite.
     *
     * Lu sur la Request, jamais dans `$_SERVER` : sous RoadRunner ou
     * FrankenPHP la superglobale peut appartenir à une requête precedente.
     */
    public function test_le_listener_ignore_un_prechargement_du_navigateur(): void
    {
        $listener = new TrackRequestListener(new Client('qm_pub_test', null, [
            'endpoint' => self::$server->endpoint(),
            'async' => false,
        ]));

        foreach ([['HTTP_SEC_PURPOSE', 'prefetch;prerender'], ['HTTP_PURPOSE', 'prefetch'], ['HTTP_X_MOZ', 'prefetch']] as [$entete, $valeur]) {
            $listener->onKernelTerminate(new TerminateEvent(
                $this->stubKernel(),
                Request::create('https://monsite.fr/tarifs', 'GET', server: [
                    'HTTP_USER_AGENT' => 'NavigateurTest/1.0',
                    $entete => $valeur,
                ]),
                new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            ));
        }

        $this->assertSame([], self::$server->requests(1, 400));
    }

    public function test_le_listener_ignore_json_erreurs_et_non_get(): void
    {
        $listener = new TrackRequestListener(new Client('qm_pub_test', null, [
            'endpoint' => self::$server->endpoint(),
            'async' => false,
        ]));

        $cases = [
            [Request::create('https://monsite.fr/api', 'GET'), new Response('{}', 200, ['Content-Type' => 'application/json'])],
            [Request::create('https://monsite.fr/form', 'POST'), new Response('ok', 200, ['Content-Type' => 'text/html'])],
            [Request::create('https://monsite.fr/oups', 'GET'), new Response('non', 500, ['Content-Type' => 'text/html'])],
        ];

        foreach ($cases as [$request, $response]) {
            $listener->onKernelTerminate(new TerminateEvent($this->stubKernel(), $request, $response));
        }

        $this->assertSame([], self::$server->requests(1, 400));
    }

    private function stubKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface
        {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }


    /**
     * Le marqueur d'exclusion pose par la personne arrete la mesure.
     *
     * Lu sur la Request et jamais dans `$_COOKIE` : sous RoadRunner ou
     * FrankenPHP la superglobale peut appartenir a la requete precedente, et
     * le refus d'un visiteur exclurait alors le suivant.
     */
    public function test_le_listener_ignore_un_visiteur_qui_a_pose_le_marqueur(): void
    {
        $this->listener()->onKernelTerminate(new TerminateEvent(
            $this->stubKernel(),
            Request::create('https://monsite.fr/tarifs', 'GET', [], [Client::OPT_OUT_MARKER => '1'], [], [
                'HTTP_USER_AGENT' => 'NavigateurTest/1.0',
            ]),
            new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ));

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /**
     * `?qm_ignore=1` pose le marqueur sur la reponse, et la visite qui le pose
     * ne se compte pas elle-meme.
     */
    public function test_le_listener_pose_le_marqueur_demande_par_l_url_sans_compter_la_visite(): void
    {
        $listener = $this->listener();
        $request = Request::create('https://monsite.fr/tarifs?'.Client::OPT_OUT_MARKER.'=1');
        $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        // Les deux listeners, chacun sur sa phase : le refus se pose pendant
        // la reponse, et la mesure, qui le lit a terminate, s'abstient.
        (new OptOutListener)->onKernelResponse(new ResponseEvent(
            $this->stubKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
        $listener->onKernelTerminate(new TerminateEvent($this->stubKernel(), $request, $response));

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(Client::OPT_OUT_MARKER, $cookies[0]->getName());
        $this->assertSame('1', $cookies[0]->getValue());
        $this->assertSame('/', $cookies[0]->getPath());
        $this->assertSame('lax', strtolower((string) $cookies[0]->getSameSite()));
        $this->assertTrue($cookies[0]->isSecure(), 'requete en https : le marqueur est secure');
        $this->assertFalse($cookies[0]->isHttpOnly(), 'le traceur JS doit lire le meme marqueur');
        $this->assertEqualsWithDelta(
            time() + Client::OPT_OUT_LIFETIME,
            $cookies[0]->getExpiresTime(),
            60,
            'cinq ans, comme le traceur JS',
        );

        $this->assertSame(
            [],
            self::$server->requests(1, 400),
            'la visite qui pose le refus ne se compte pas elle-meme',
        );
    }

    /** `?qm_ignore=0` retire le marqueur, et la visite recompte des maintenant. */
    public function test_le_listener_retire_le_marqueur_demande_par_l_url_et_recompte_la_visite(): void
    {
        $listener = $this->listener();
        $request = Request::create(
            'https://monsite.fr/tarifs?'.Client::OPT_OUT_MARKER.'=0',
            'GET',
            [],
            [Client::OPT_OUT_MARKER => '1'],
            [],
            ['HTTP_USER_AGENT' => 'NavigateurTest/1.0'],
        );
        $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        // Les deux listeners, chacun sur sa phase : le refus se retire pendant
        // la reponse, la page vue part a terminate.
        (new OptOutListener)->onKernelResponse(new ResponseEvent(
            $this->stubKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
        $listener->onKernelTerminate(new TerminateEvent($this->stubKernel(), $request, $response));

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(Client::OPT_OUT_MARKER, $cookies[0]->getName());
        $this->assertLessThan(time(), $cookies[0]->getExpiresTime(), 'un marqueur retire expire dans le passe');

        $this->assertCount(
            1,
            self::$server->requests(),
            'retirer le refus remet la personne dans la mesure des cette visite',
        );
    }

    /** Une URL sans signal ne pose ni ne retire quoi que ce soit. */
    public function test_le_listener_ne_touche_au_marqueur_que_sur_signal(): void
    {
        $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        (new OptOutListener)->onKernelResponse(new ResponseEvent(
            $this->stubKernel(),
            Request::create('https://monsite.fr/tarifs'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        $this->assertSame([], $response->headers->getCookies());
    }

    private function listener(): TrackRequestListener
    {
        return new TrackRequestListener(new Client('qm_pub_test', null, [
            'endpoint' => self::$server->endpoint(),
            'async' => false,
        ]));
    }

    /**
     * La fenetre de visite se pose pendant la phase reponse.
     *
     * A kernel.terminate la reponse est deja partie chez le visiteur, il y
     * serait trop tard pour un Set-Cookie : meme raison que pour le marqueur
     * d'exclusion, et meme decoupage en deux listeners.
     *
     * A LA DIFFERENCE du marqueur d'exclusion, ce listener n'est PAS
     * enregistre quand `auto_pageview` vaut false, et c'est voulu : le cookie
     * accompagne un hit mesure. Sans page vue automatique il n'y a pas de hit
     * a accompagner, et l'ouvrir quand meme reviendrait a ecrire chez le
     * visiteur sans rien mesurer. Un refus ne depend pas d'une option de
     * mesure ; une continuite de mesure, si.
     */
    public function test_le_listener_de_visite_est_branche_sur_kernel_response(): void
    {
        $container = $this->compile(['public_key' => 'qm_pub_test']);

        $tags = $container->getDefinition(VisitListener::class)->getTag('kernel.event_listener');
        $this->assertSame('kernel.response', $tags[0]['event']);
        $this->assertSame('onKernelResponse', $tags[0]['method']);
    }

    public function test_sans_pageview_automatique_aucune_fenetre_de_visite_n_est_ouverte(): void
    {
        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'auto_pageview' => false,
        ]);

        $this->assertFalse(
            $container->has(VisitListener::class),
            'la fenetre accompagne un hit mesure : sans hit, rien a ecrire chez le visiteur',
        );

        $this->assertTrue(
            $container->has(OptOutListener::class),
            'le refus, lui, reste posable : il ne depend d aucune option de mesure',
        );
    }

    /**
     * Premier hit : la fenetre s'ouvre, et le hit ne porte pas `c`.
     *
     * `qm_visit=1` vaut la meme chose chez tout le monde : il n'identifie
     * personne, il dit qu'une visite est en cours sur ce navigateur. Sans lui,
     * une empreinte qui change en cours de visite (4G puis wifi) compte deux
     * visiteurs uniques pour une seule personne.
     */
    public function test_le_listener_ouvre_la_fenetre_de_visite_sur_un_hit_mesure(): void
    {
        $request = Request::create('https://monsite.fr/tarifs', 'GET', server: ['HTTP_USER_AGENT' => 'NavigateurTest/1.0']);
        $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $this->visite($request, $response);
        $this->listener()->onKernelTerminate(new TerminateEvent($this->stubKernel(), $request, $response));

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(Client::VISIT_MARKER, $cookies[0]->getName());
        $this->assertSame('1', $cookies[0]->getValue(), 'valeur constante : elle n identifie personne');
        $this->assertSame('/', $cookies[0]->getPath());
        $this->assertSame('lax', strtolower((string) $cookies[0]->getSameSite()));
        $this->assertTrue($cookies[0]->isSecure(), 'requete en https : la fenetre est secure');
        $this->assertFalse($cookies[0]->isHttpOnly(), 'le traceur JS doit lire la meme fenetre');
        $this->assertEqualsWithDelta(
            time() + Client::VISIT_LIFETIME,
            $cookies[0]->getExpiresTime(),
            60,
            'dix minutes, comme le traceur JS',
        );

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertArrayNotHasKey('c', $payload, 'premier hit : aucune visite n etait en cours');
    }

    /**
     * Le hit suivant porte `c`, et la fenetre glisse.
     *
     * L'etat est lu sur la Request (ce que le navigateur a envoye) et jamais
     * dans `$_COOKIE` : sous RoadRunner ou FrankenPHP la superglobale peut
     * appartenir a la requete precedente, et la visite d'un visiteur serait
     * recollee a celle du suivant.
     */
    public function test_le_hit_suivant_porte_c_et_repousse_la_fenetre(): void
    {
        $request = Request::create(
            'https://monsite.fr/tarifs',
            'GET',
            [],
            [Client::VISIT_MARKER => '1'],
            [],
            ['HTTP_USER_AGENT' => 'NavigateurTest/1.0'],
        );
        $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $this->visite($request, $response);
        $this->listener()->onKernelTerminate(new TerminateEvent($this->stubKernel(), $request, $response));

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame(1, $payload['c'], 'une visite etait deja en cours sur ce navigateur');

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies, 'expiration glissante : chaque hit repousse la fenetre');
        $this->assertEqualsWithDelta(time() + Client::VISIT_LIFETIME, $cookies[0]->getExpiresTime(), 60);
    }

    /** Rien de mesure, rien d'ecrit : la fenetre suit le hit, pas la requete. */
    public function test_aucune_fenetre_de_visite_quand_rien_n_est_mesure(): void
    {
        $cases = [
            [Request::create('https://monsite.fr/api'), new Response('{}', 200, ['Content-Type' => 'application/json'])],
            [Request::create('https://monsite.fr/form', 'POST'), new Response('ok', 200, ['Content-Type' => 'text/html'])],
            [Request::create('https://monsite.fr/oups'), new Response('non', 500, ['Content-Type' => 'text/html'])],
            [
                Request::create('https://monsite.fr/tarifs', 'GET', server: ['HTTP_SEC_PURPOSE' => 'prefetch;prerender']),
                new Response('ok', 200, ['Content-Type' => 'text/html']),
            ],
        ];

        foreach ($cases as [$request, $response]) {
            $this->visite($request, $response);
            $this->assertSame([], $response->headers->getCookies(), (string) $request->getRequestUri());
        }
    }

    /** On n'ecrit RIEN chez quelqu'un qui a refuse la mesure. */
    public function test_aucune_fenetre_de_visite_chez_une_personne_exclue(): void
    {
        $deja = new Response('ok', 200, ['Content-Type' => 'text/html']);
        $this->visite(
            Request::create('https://monsite.fr/tarifs', 'GET', [], [Client::OPT_OUT_MARKER => '1']),
            $deja,
        );
        $this->assertSame([], $deja->headers->getCookies(), 'refus deja pose');

        // La requete qui POSE le refus n'ouvre pas de fenetre non plus : le
        // refus vaut des la requete qui le demande.
        $pose = new Response('ok', 200, ['Content-Type' => 'text/html']);
        $request = Request::create('https://monsite.fr/tarifs?'.Client::OPT_OUT_MARKER.'=1');
        $this->visite($request, $pose);
        (new OptOutListener)->onKernelResponse(new ResponseEvent(
            $this->stubKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $pose,
        ));

        $noms = array_map(static fn ($cookie) => $cookie->getName(), $pose->headers->getCookies());
        $this->assertSame([Client::OPT_OUT_MARKER], $noms, 'le refus se pose, la visite non');
    }

    /** Le listener de visite, joue sur la phase reponse. */
    private function visite(Request $request, Response $response): void
    {
        (new VisitListener)->onKernelResponse(new ResponseEvent(
            $this->stubKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
    }
}
