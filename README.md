# quiet-metrics/symfony-metrics

![Quiet Metrics: Symfony bundle](art/banner.png)

> 🇫🇷 [Version française](README.fr.md)

Symfony bundle (6.4 and 7.x) for the [Quiet Metrics](https://quietmetrics.dev) PHP SDK: cookie-free audience measurement, 100% server-side, unblockable by ad blockers. Page views are sent automatically on `kernel.terminate`, without JavaScript and without ever slowing the site down.

## Installation

```bash
composer require quiet-metrics/symfony-metrics
```

With Symfony Flex, the bundle is registered automatically (`symfony-bundle` type). Without Flex, add it to `config/bundles.php`:

```php
// config/bundles.php
return [
    // ...
    QuietMetrics\Symfony\QuietMetricsBundle::class => ['all' => true],
];
```

### Before the Packagist release

`symfony-metrics` is not on Packagist yet, and its repository is private. Declare it (access required); the core package it depends on comes from Packagist:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Quiet-Metrics/symfony-metrics" }
    ]
}
```

```bash
composer require quiet-metrics/symfony-metrics:^0.1
```

## Configuration

The configuration alias is `quiet_metrics`.

```yaml
# config/packages/quiet_metrics.yaml
quiet_metrics:
    public_key: '%env(QUIET_METRICS_PUBLIC_KEY)%'   # site public key (required)
    secret_key: '%env(QUIET_METRICS_SECRET_KEY)%'   # essential server-side: signs every hit (HMAC)

    # endpoint: 'https://quietmetrics.dev/api/v1/collect'  # default: core SDK's Quiet Metrics SaaS endpoint
    # trust_proxy_headers: true   # application behind a reverse proxy (X-Forwarded-For / X-Forwarded-Proto)
    # auto_pageview: false        # disables the automatic pageview (manual events only)
```

```bash
# .env.local
QUIET_METRICS_PUBLIC_KEY=qm_pub_xxx
QUIET_METRICS_SECRET_KEY=qm_sec_xxx
```

> **Why the secret key matters.** It enables signed mode, the only case where
> the visitor IP and User-Agent carried by your server are trusted. Without
> it, every hit is attributed to your server's IP: all your visitors would
> count as one.

## Usage

Pageviews for successful HTML responses are sent on their own: nothing to do.

For custom events, inject the core SDK client (`QuietMetrics\Client`, wired by the bundle):

```php
use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutController
{
    public function __construct(private readonly Client $quietMetrics) {}

    public function confirm(): Response
    {
        $this->quietMetrics->event('purchase', ['amount' => 49, 'plan' => 'pro']);
        // ...
    }
}
```

With `auto_pageview: false`, you keep control over page views:

```php
// Context (URL, referrer, IP, User-Agent, language) inferred from the
// current request, overridable key by key:
$this->quietMetrics->pageview();
$this->quietMetrics->pageview(['url' => 'https://mysite.com/thank-you']);
```

## How it works

- Sending happens on `kernel.terminate`: the response has already reached the visitor, zero perceived latency. The core SDK client is itself non-blocking (write-and-forget socket, short-timeout cURL fallback, silent failures): analytics never breaks the host site.
- The listener only counts real pages: `GET` requests, 2xx responses, HTML `Content-Type`, excluding AJAX requests.
- The context is read from the `Request` object (never from superglobals): correct under RoadRunner and FrankenPHP, in tests, and aligned with the host application's trusted proxies.
- With `secret_key`, every send is HMAC-SHA256 signed (`X-QM-Timestamp` and `X-QM-Signature` headers); the visitor IP and User-Agent carried by the SDK are then trusted on the collection side.

## License

MIT. A [La Boîte à Code](https://laboiteacode.fr) product for [Quiet Metrics](https://quietmetrics.dev).
