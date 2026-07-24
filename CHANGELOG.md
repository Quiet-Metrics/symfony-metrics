# Changelog

All notable changes to `quiet-metrics/symfony-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [Unreleased]

Full pre-publication review pending (the core PHP SDK and the Laravel bridge already went through theirs).

## [0.1.0] - 2026-07-24

First tagged snapshot (private beta).

### Added
- `QuietMetricsBundle` (Symfony 6.4 / 7.x): automatic pageviews on `kernel.terminate` for successful HTML `GET`s, semantic configuration (`quiet_metrics` alias), core SDK `Client` wired as a service.
- `auto_pageview: false` mode for manual control.

### Fixed
- Default endpoint documentation now `https://quietmetrics.dev/api/v1/collect`.
