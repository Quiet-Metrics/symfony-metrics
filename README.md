# laboiteacode/webanalytics-symfony — bundle Symfony

Intégration Symfony (≥ 6.4) du [package cœur PHP](../php) : pageviews serveur automatiques via `kernel.terminate` et configuration sémantique. **Tracking côté serveur** — sans cookie, sans JS, imblocable par les adblockers.

## Installation (sur le site du client)

```bash
composer require laboiteacode/webanalytics-symfony
```

```yaml
# config/packages/webanalytics.yaml
webanalytics:
    public_key: '%env(WEBANALYTICS_PUBLIC_KEY)%'
    secret_key: '%env(WEBANALYTICS_SECRET_KEY)%'
    # auto_pageview: false   # pour ne garder que les événements manuels
```

## Usage

Les pageviews des réponses HTML `GET` réussies partent automatiquement (listener `kernel.terminate` : zéro latence perçue). Événements personnalisés par injection du client :

```php
use LaBoiteACode\WebAnalytics\Client;

final class CheckoutController
{
    public function __construct(private Client $wa) {}

    public function confirm(): Response
    {
        $this->wa->event('achat', ['montant' => 49]);
        // …
    }
}
```

## Tests

```bash
composer update && composer test
```

4 tests : câblage vérifié par compilation d'un `ContainerBuilder` (client configuré, listener taggé `kernel.terminate`, `auto_pageview: false` → listener absent) et comportement du listener réel contre le serveur de capture HTTP du cœur (pageview signée, exclusions JSON/POST/erreurs). L'alias de configuration est `webanalytics` (pas `web_analytics`).

## Installer en local (avant la publication Packagist)

Depuis un projet Symfony sur la même machine — les **deux** path repositories sont nécessaires (le bundle dépend du cœur en `@dev`) :

```json
{
    "repositories": [
        { "type": "path", "url": "../WebAnalytics/packages/php", "options": { "symlink": true } },
        { "type": "path", "url": "../WebAnalytics/packages/symfony", "options": { "symlink": true } }
    ]
}
```

```bash
composer require laboiteacode/webanalytics-symfony:@dev
```

Flex enregistre le bundle automatiquement (`type: symfony-bundle`) ; créez ensuite `config/packages/webanalytics.yaml` comme ci-dessus.

## Reste à faire avant v1

- [x] Tests : configuration (compilation du conteneur), listener, conditions d'exclusion.
- [ ] Option `exclude_paths` (motifs ignorés par le listener).
- [ ] Recette Symfony Flex (pré-création du yaml + variables d'env).
