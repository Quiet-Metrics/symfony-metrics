# Changelog

All notable changes to `quiet-metrics/symfony-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [0.3.0] - 2026-08-28

### Added
- **Visit continuity cookie.** The visitor fingerprint is recomputed on every hit from the subscriber network and the browser. When that network changes MID-VISIT (switching from mobile data to wifi), the fingerprint changes and the same person was counted as two unique visitors on the same day. `qm_visit` closes that: a first-party cookie of the tracked site, value `1`, sliding ten-minute window refreshed on each hit (`path=/`, `samesite=lax`, `secure` over https). It holds no identifier, its value being the same for everyone, and it is never set for someone who has opted out. Only its presence travels, as the `c` boolean of the payload.

### Changed
- New `VisitListener` on `kernel.response`. Unlike `OptOutListener`, it is registered only when `auto_pageview` is on: a refusal does not depend on a measurement option, a measurement cookie does.
- Requires `quiet-metrics/php-metrics` `^0.3`.

## [0.2.0] - 2026-08-28

Full pre-publication review pending (the core PHP SDK and the Laravel bridge already went through theirs).

### Added
- Opt-out marker: a visitor loading any page of the tracked site with `?qm_ignore=1` stops being counted, and `?qm_ignore=0` puts them back into measurement. The marker is a first-party `qm_ignore` cookie of that site (`path=/`, `samesite=lax`, `secure` over https, five years); it holds no identifier, is never transmitted to Quiet Metrics, and exists only to stop measurement. Nothing is sent while it is present.

### Changed
- **The opt-out marker no longer depends on `auto_pageview`.** It used to travel in the same listener as the automatic pageview, so `auto_pageview: false` disabled it too: on those applications `?qm_ignore=1` did nothing, while reading the refusal kept working. A visitor could therefore stay excluded if they had opted out elsewhere, but could no longer opt out here. A refusal mechanism does not depend on a measurement option. The marker now lives in its own `OptOutListener`, registered on `kernel.response` whatever `auto_pageview` is set to.
- Requires `quiet-metrics/php-metrics` `^0.2`: under 0.x, `^0.1` excludes 0.2.
- The published promise is now "no identification or tracking cookies" rather than "cookie-free". Nothing is stored on the visitor's device in order to measure them; the one exception is the opt-out marker, which they store themselves and which is exempt from consent as an expression of refusal.

## [0.1.1] - 2026-08-27

Documentation and artwork only. The bundle code is unchanged since 0.1.0.

### Changed
- Banner redrawn to the current brand: product typefaces instead of webfonts that were never part of the design system, the damped wave instead of the pre-redesign bars, title in ink rather than in the accent colour.
- Install instructions: `quiet-metrics/php-metrics` is on Packagist, so only this repository needs a VCS entry.

## [0.1.0] - 2026-07-24

First tagged snapshot (private beta).

### Added
- `QuietMetricsBundle` (Symfony 6.4 / 7.x): automatic pageviews on `kernel.terminate` for successful HTML `GET`s, semantic configuration (`quiet_metrics` alias), core SDK `Client` wired as a service.
- `auto_pageview: false` mode for manual control.

### Fixed
- Default endpoint documentation now `https://quietmetrics.dev/api/v1/collect`.
