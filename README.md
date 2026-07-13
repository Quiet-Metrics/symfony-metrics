# laboiteacode/webanalytics-symfony

Bundle Symfony (6.4 et 7.x) du SDK PHP [Affluence](https://app.affluence.fr) : mesure d'audience sans cookies, 100 % côté serveur, imblocable par les adblockers. Les pages vues partent automatiquement en `kernel.terminate`, sans JavaScript et sans jamais ralentir le site.

## Installation

```bash
composer require laboiteacode/webanalytics-symfony
```

Avec Symfony Flex, le bundle est enregistré automatiquement (type `symfony-bundle`). Sans Flex, ajoutez-le à `config/bundles.php` :

```php
// config/bundles.php
return [
    // ...
    LaBoiteACode\WebAnalytics\Symfony\WebAnalyticsBundle::class => ['all' => true],
];
```

### Avant la publication sur Packagist (installation locale)

Depuis un projet Symfony sur la même machine, déclarez les deux path repositories (le bundle dépend du package cœur en version de développement) :

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

## Configuration

L'alias de configuration est `webanalytics` (pas `web_analytics`).

```yaml
# config/packages/webanalytics.yaml
webanalytics:
    public_key: '%env(WEBANALYTICS_PUBLIC_KEY)%'   # clé publique du site (obligatoire)
    secret_key: '%env(WEBANALYTICS_SECRET_KEY)%'   # facultative : signe chaque envoi (HMAC)

    # endpoint: 'https://app.affluence.fr/api/v1/collect'  # défaut : endpoint SaaS Affluence du SDK cœur
    # trust_proxy_headers: true   # application derrière un reverse proxy (X-Forwarded-For / X-Forwarded-Proto)
    # auto_pageview: false        # désactive la pageview automatique (événements manuels uniquement)
```

```bash
# .env.local
WEBANALYTICS_PUBLIC_KEY=wa_pub_xxx
WEBANALYTICS_SECRET_KEY=wa_sec_xxx
```

## Usage

Les pageviews des réponses HTML réussies partent toutes seules : rien à faire.

Pour les événements personnalisés, injectez le client du SDK cœur (`LaBoiteACode\WebAnalytics\Client`, câblé par le bundle) :

```php
use LaBoiteACode\WebAnalytics\Client;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutController
{
    public function __construct(private readonly Client $webAnalytics) {}

    public function confirm(): Response
    {
        $this->webAnalytics->event('achat', ['montant' => 49, 'plan' => 'pro']);
        // ...
    }
}
```

Avec `auto_pageview: false`, vous gardez la main sur les pages vues :

```php
// Contexte (URL, referrer, IP, User-Agent, langue) déduit de la requête
// courante, surchargeable clé par clé :
$this->webAnalytics->pageview();
$this->webAnalytics->pageview(['url' => 'https://monsite.fr/merci']);
```

## Comment ça marche

- L'envoi a lieu sur `kernel.terminate` : la réponse est déjà partie chez le visiteur, aucune latence perçue. Le client du SDK cœur est lui-même non bloquant (socket write-and-forget, repli cURL avec timeout court, échecs silencieux) : l'analytics ne casse jamais le site hôte.
- Le listener ne compte que les vraies pages : requêtes `GET`, réponse 2xx, `Content-Type` HTML, hors requêtes AJAX.
- Le contexte est lu depuis l'objet `Request` (jamais les superglobales) : correct sous RoadRunner et FrankenPHP, dans les tests, et aligné sur les trusted proxies configurés dans l'application hôte.
- Avec `secret_key`, chaque envoi est signé HMAC-SHA256 (en-têtes `X-WA-Timestamp` et `X-WA-Signature`) ; l'IP et le User-Agent du visiteur transmis par le SDK font alors foi côté collecte.

## Licence

MIT. Un produit [La Boîte à Code](https://laboiteacode.fr).
