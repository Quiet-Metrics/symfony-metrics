<?php

declare(strict_types=1);

namespace QuietMetrics\Symfony;

use QuietMetrics\Client;
use QuietMetrics\Symfony\EventListener\OptOutListener;
use QuietMetrics\Symfony\EventListener\SiteVerificationListener;
use QuietMetrics\Symfony\EventListener\TrackRequestListener;
use QuietMetrics\Symfony\EventListener\VisitListener;
use QuietMetrics\Tracker;
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
                ->booleanNode('track_404')->defaultFalse()->end()
                // Crawl SEO : sert la preuve de propriété du site sur
                // /.well-known/quietmetrics.json (exige secret_key). Éteint
                // par défaut : la plateforme n'explore alors pas le site.
                // Typiquement '%env(bool:QUIET_METRICS_SEO_CRAWL)%'.
                ->booleanNode('seo_crawl')->defaultFalse()->end()
            ->end();
    }

    /**
     * @param array{public_key:string,secret_key:?string,endpoint:?string,trust_proxy_headers:bool,auto_pageview:bool,track_404:bool,seo_crawl:bool|string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // `seo_crawl` peut être un paramètre d'environnement encore non
        // résolu à la compilation : il est passé tel quel, jamais testé ici.
        $options = [
            'trust_proxy_headers' => $config['trust_proxy_headers'],
            'seo_crawl' => $config['seo_crawl'],
        ];
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

        // Ce que le code appelant doit typer : un test remplace ce service
        // (config/services_test.yaml) sans toucher au client, qui est final.
        $services->alias(Tracker::class, Client::class)->public();

        // TOUJOURS enregistré, y compris quand `auto_pageview` vaut false.
        // Le marqueur voyageait dans le listener de mesure, si bien que couper
        // la page vue automatique coupait aussi la possibilité de se retirer,
        // alors que la LECTURE du refus, elle, continuait de fonctionner. Un
        // mécanisme de refus ne se désactive pas avec une option de confort.
        //
        // Sur kernel.response : à kernel.terminate la réponse est déjà partie
        // chez le visiteur, il y serait trop tard pour un Set-Cookie.
        $services->set(OptOutListener::class)
            ->tag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => 'onKernelResponse',
            ]);

        // La preuve de propriété exigée par le crawl SEO. Enregistrée quelle
        // que soit la configuration, parce que `seo_crawl` peut n'être connu
        // qu'à l'exécution (%env()%) : c'est le client qui décide, et sans
        // document le listener ne touche à rien. Coût par requête : une
        // comparaison de chemin.
        //
        // Priorité 200 : après ValidateRequestListener (256), avant la
        // session (128), le routeur (32) et le pare-feu (8). La réponse part
        // sans route à déclarer, sans session ni authentification.
        $services->set(SiteVerificationListener::class)
            ->args([service(Client::class)])
            ->tag('kernel.event_listener', [
                'event' => 'kernel.request',
                'method' => 'onKernelRequest',
                'priority' => 200,
            ]);

        if ($config['auto_pageview']) {
            $services->set(TrackRequestListener::class)
                ->args([service(Tracker::class), $config['track_404']])
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.terminate',
                    'method' => 'onKernelTerminate',
                ]);

            // La fenêtre de continuité de visite, sur kernel.response : à
            // kernel.terminate la réponse est déjà partie, il y serait trop
            // tard pour un Set-Cookie.
            //
            // Enregistré ici, DANS la condition, à la différence
            // d'OptOutListener : ce cookie accompagne un hit mesuré, et sans
            // page vue automatique il n'y a pas de hit à accompagner. Un
            // mécanisme de refus ne dépend pas d'une option de mesure ; une
            // continuité de mesure, si.
            $services->set(VisitListener::class)
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.response',
                    'method' => 'onKernelResponse',
                ]);
        }
    }
}
