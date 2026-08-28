<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony;

use QuietMetrics\Client;
use QuietMetrics\Symfony\EventListener\TrackRequestListener;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class QuietMetricsBundle extends AbstractBundle
{
    // Clé de configuration « webanalytics » (l'alias auto serait quiet_metrics).
    protected string $extensionAlias = 'quiet_metrics';

    public function configure(DefinitionConfigurator $definition): void
    {
        // config/packages/quiet_metrics.yaml
        $definition->rootNode()
            ->children()
                ->scalarNode('public_key')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('secret_key')->defaultNull()->end()
                // null : on laisse l'endpoint par défaut du SDK cœur (SaaS Quiet Metrics).
                ->scalarNode('endpoint')->defaultNull()->end()
                ->booleanNode('trust_proxy_headers')->defaultFalse()->end()
                // false → désactive la pageview auto (events manuels uniquement).
                ->booleanNode('auto_pageview')->defaultTrue()->end()
            ->end();
    }

    /**
     * @param array{public_key:string,secret_key:?string,endpoint:?string,trust_proxy_headers:bool,auto_pageview:bool} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $options = ['trust_proxy_headers' => $config['trust_proxy_headers']];
        if ($config['endpoint'] !== null) {
            $options['endpoint'] = $config['endpoint'];
        }

        $services->set(Client::class)
            ->args([
                $config['public_key'],
                $config['secret_key'],
                $options,
            ])
            ->public();

        if ($config['auto_pageview']) {
            $services->set(TrackRequestListener::class)
                ->args([service(Client::class)])
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.terminate',
                    'method' => 'onKernelTerminate',
                ])
                // Le marqueur d'exclusion se pose pendant la phase réponse :
                // sur kernel.terminate la réponse est déjà partie chez le
                // visiteur, il y serait trop tard pour un Set-Cookie.
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.response',
                    'method' => 'onKernelResponse',
                ]);
        }
    }
}
