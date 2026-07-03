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

## Reste à faire avant v1

- [ ] Tests (kernel de test) : configuration, listener, conditions d'exclusion.
- [ ] Option `exclude_paths` (motifs ignorés par le listener).
- [ ] Recette Symfony Flex (pré-création du yaml + variables d'env).
