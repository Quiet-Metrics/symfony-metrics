<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Symfony;

use LaBoiteACode\WebAnalytics\Client;
use LaBoiteACode\WebAnalytics\Symfony\EventListener\TrackRequestListener;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class WebAnalyticsBundle extends AbstractBundle
{
    // Clé de configuration « webanalytics » (l'alias auto serait web_analytics).
    protected string $extensionAlias = 'webanalytics';

    public function configure(DefinitionConfigurator $definition): void
    {
        // config/packages/webanalytics.yaml
        $definition->rootNode()
            ->children()
                ->scalarNode('public_key')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('secret_key')->defaultNull()->end()
                ->scalarNode('endpoint')->defaultValue('https://collect.example.fr/api/v1/collect')->end()
                ->booleanNode('trust_proxy_headers')->defaultFalse()->end()
                // false → désactive la pageview auto (events manuels uniquement).
                ->booleanNode('auto_pageview')->defaultTrue()->end()
            ->end();
    }

    /**
     * @param array{public_key:string,secret_key:?string,endpoint:string,trust_proxy_headers:bool,auto_pageview:bool} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(Client::class)
            ->args([
                $config['public_key'],
                $config['secret_key'],
                [
                    'endpoint' => $config['endpoint'],
                    'trust_proxy_headers' => $config['trust_proxy_headers'],
                ],
            ])
            ->public();

        if ($config['auto_pageview']) {
            $services->set(TrackRequestListener::class)
                ->args([service(Client::class)])
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.terminate',
                    'method' => 'onKernelTerminate',
                ]);
        }
    }
}
