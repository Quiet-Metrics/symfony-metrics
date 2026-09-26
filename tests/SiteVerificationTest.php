<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietMetrics\Client;
use QuietMetrics\Symfony\EventListener\SiteVerificationListener;
use QuietMetrics\Symfony\QuietMetricsBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Preuve de propriete du site pour le crawl SEO, servie par le bundle.
 *
 * Meme approche que BundleTest : le cablage par compilation d'un
 * ContainerBuilder, le comportement par le listener reel. Le jeton attendu est
 * recalcule ici a la main, contrat avec la plateforme oblige.
 */
final class SiteVerificationTest extends TestCase
{
    private const PATH = '/.well-known/quietmetrics.json';

    private const SECRET = 'qm_sec_test';

    private const ENV = 'QUIET_METRICS_SEO_CRAWL';

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config, bool $resolveEnv = false): ContainerBuilder
    {
        // Le sac a placeholders d'environnement d'un vrai kernel : sans lui,
        // `%env(...)%` serait lu comme un parametre ordinaire introuvable.
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => sys_get_temp_dir(),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));
        $extension = (new QuietMetricsBundle)->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile($resolveEnv);

        return $container;
    }

    private function expectedDocument(): string
    {
        return '{"site_verification":["'.hash_hmac('sha256', 'quietmetrics-site-verification:v1', self::SECRET).'"]}';
    }

    private function dispatch(Client $client, Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, $type);
        (new SiteVerificationListener($client))->onKernelRequest($event);

        return $event;
    }

    private function enabledClient(): Client
    {
        return new Client('qm_pub_test', self::SECRET, ['seo_crawl' => true]);
    }

    public function test_the_option_is_off_by_default(): void
    {
        $container = $this->compile(['public_key' => 'qm_pub_test', 'secret_key' => self::SECRET]);

        $this->assertFalse($container->getDefinition(Client::class)->getArgument(2)['seo_crawl']);
        $this->assertNull($container->get(Client::class)->siteVerificationDocument());
    }

    public function test_the_option_reaches_the_client(): void
    {
        $container = $this->compile(['public_key' => 'qm_pub_test', 'secret_key' => self::SECRET, 'seo_crawl' => true]);

        $this->assertSame($this->expectedDocument(), $container->get(Client::class)->siteVerificationDocument());
    }

    public function test_the_listener_runs_on_kernel_request_before_routing(): void
    {
        $container = $this->compile(['public_key' => 'qm_pub_test']);

        $definition = $container->getDefinition(SiteVerificationListener::class);
        $tags = $definition->getTag('kernel.event_listener');

        $this->assertSame('kernel.request', $tags[0]['event']);
        $this->assertSame('onKernelRequest', $tags[0]['method']);
        // RouterListener ecoute kernel.request en priorite 32.
        $this->assertGreaterThan(32, $tags[0]['priority']);
        $this->assertSame(Client::class, (string) $definition->getArgument(0));
    }

    public function test_the_environment_variable_turns_it_on(): void
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = 'true';

        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'secret_key' => self::SECRET,
            'seo_crawl' => '%env(bool:QUIET_METRICS_SEO_CRAWL)%',
        ], true);

        $this->assertSame($this->expectedDocument(), $container->get(Client::class)->siteVerificationDocument());
    }

    public function test_the_environment_variable_can_say_false(): void
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = 'false';

        $container = $this->compile([
            'public_key' => 'qm_pub_test',
            'secret_key' => self::SECRET,
            'seo_crawl' => '%env(bool:QUIET_METRICS_SEO_CRAWL)%',
        ], true);

        $this->assertNull($container->get(Client::class)->siteVerificationDocument());
    }

    public function test_get_on_the_exact_path_is_answered_with_the_document(): void
    {
        $event = $this->dispatch($this->enabledClient(), Request::create(self::PATH.'?cache=bust'));

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->expectedDocument(), $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_head_is_answered_too(): void
    {
        $request = Request::create(self::PATH, 'HEAD');
        $response = $this->dispatch($this->enabledClient(), $request)->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $response->prepare($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function requestsLeftToTheApplication(): array
    {
        return [
            'post' => [self::PATH, 'POST'],
            'trailing slash' => [self::PATH.'/', 'GET'],
            'suffix' => [self::PATH.'x', 'GET'],
            'other well-known file' => ['/.well-known/security.txt', 'GET'],
            'home' => ['/', 'GET'],
        ];
    }

    #[DataProvider('requestsLeftToTheApplication')]
    public function test_other_requests_are_left_to_the_application(string $uri, string $method): void
    {
        $this->assertFalse($this->dispatch($this->enabledClient(), Request::create($uri, $method))->hasResponse());
    }

    public function test_a_sub_request_is_left_alone(): void
    {
        $event = $this->dispatch($this->enabledClient(), Request::create(self::PATH), HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($event->hasResponse());
    }

    public function test_nothing_is_intercepted_when_the_option_is_off(): void
    {
        $event = $this->dispatch(new Client('qm_pub_test', self::SECRET), Request::create(self::PATH));

        $this->assertFalse($event->hasResponse());
    }

    public function test_nothing_is_intercepted_without_a_secret_key(): void
    {
        $event = $this->dispatch(new Client('qm_pub_test', null, ['seo_crawl' => true]), Request::create(self::PATH));

        $this->assertFalse($event->hasResponse());
    }
}
