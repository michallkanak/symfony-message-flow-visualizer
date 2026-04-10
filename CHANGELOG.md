# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Beta]

## [0.1.1] - 2026-04-10

### Fixed

- [Storage] Fix mergeStepData field name mismatch losing queue wait timing by @corentin-larose in #1
- [Storage] Implement avgDurationMs computation in DoctrineStorage by @corentin-larose in #4
- [DependencyInjection] Fix Configuration default for enabled option by @corentin-larose in #2
- [Trace] Replace deprecated mt_rand() with random_int() in SamplingDecider by @corentin-larose in #3
- [Controller] Fix query parameter defaults in DashboardController by @corentin-larose in #5

## [0.1.0] - 2025-12-31

### Added

- Core tracing middleware for Symfony Messenger
- FlowRun and FlowStep entities for flow tracking
- Storage implementations: Filesystem (default), Doctrine, Redis, InMemory
- TraceStamp for context propagation with sampling continuity
- Dual timing metrics: processing duration vs queue wait time
- Symfony Profiler integration with interactive graph visualization
- CLI commands: `messenger:flow:show`, `messenger:flow:list`, `messenger:flow:stats`, `messenger:flow:cleanup`
- Optional web dashboard controller
- Configurable sampling for production environments
- PHPStan level 8 compliance
- Support for PHP 8.2, 8.3, 8.4
- Support for Symfony 6.4 and 7.x
