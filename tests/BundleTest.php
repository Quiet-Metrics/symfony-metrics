<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Symfony\Tests;

use LaBoiteACode\WebAnalytics\Client;
use LaBoiteACode\WebAnalytics\Symfony\EventListener\TrackRequestListener;
use LaBoiteACode\WebAnalytics\Symfony\WebAnalyticsBundle;
use LaBoiteACode\WebAnalytics\Tests\CaptureServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Le bundle testé sans kernel complet (robuste et rapide) :
 * — câblage : compilation d'un ContainerBuilder avec l'extension du bundle ;
 * — comportement : listener réel contre le serveur de capture HTTP du cœur.
 */
final class BundleTest extends TestCase
{
    private static CaptureServer $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../vendor/laboiteacode/webanalytics-php/tests/CaptureServer.php';
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
        $extension = (new WebAnalyticsBundle())->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $container->getCompilerPassConfig()->setRemovingPasses([]); // garde les services privés inspectables
        $container->compile();

        return $container;
    }

    public function test_l_extension_s_appelle_webanalytics_et_cable_le_client(): void
    {
        $container = $this->compile([
            'public_key' => 'wa_pub_test',
            'secret_key' => 'wa_sec_test',
            'endpoint' => 'https://collecte.exemple.fr/api/v1/collect',
        ]);

        $this->assertTrue($container->has(Client::class));

        $definition = $container->getDefinition(Client::class);
        $this->assertSame('wa_pub_test', $definition->getArgument(0));
        $this->assertSame('wa_sec_test', $definition->getArgument(1));
        $this->assertSame('https://collecte.exemple.fr/api/v1/collect', $definition->getArgument(2)['endpoint']);

        // Pageview auto : listener présent et branché sur kernel.terminate.
        $listener = $container->getDefinition(TrackRequestListener::class);
        $tags = $listener->getTag('kernel.event_listener');
        $this->assertSame('kernel.terminate', $tags[0]['event']);
        $this->assertSame('onKernelTerminate', $tags[0]['method']);
    }

    public function test_auto_pageview_desactivable(): void
    {
        $container = $this->compile([
            'public_key' => 'wa_pub_test',
            'auto_pageview' => false,
        ]);

        $this->assertTrue($container->has(Client::class), 'les événements manuels restent possibles');
        $this->assertFalse($container->has(TrackRequestListener::class));
    }

    public function test_le_listener_envoie_la_pageview_sur_kernel_terminate(): void
    {
        $listener = new TrackRequestListener(new Client('wa_pub_test', 'wa_sec_test', [
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

        $timestamp = $request['headers']['x-wa-timestamp'];
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$request['body'], 'wa_sec_test'),
            $request['headers']['x-wa-signature'],
        );
    }

    public function test_le_listener_ignore_json_erreurs_et_non_get(): void
    {
        $listener = new TrackRequestListener(new Client('wa_pub_test', null, [
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
}
