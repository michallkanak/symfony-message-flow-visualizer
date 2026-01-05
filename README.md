# Message Flow Visualizer for Symfony Messenger (Beta)

[![CI](https://github.com/michallkanak/symfony-message-flow-visualizer/actions/workflows/ci.yml/badge.svg)](https://github.com/michallkanak/symfony-message-flow-visualizer/actions/workflows/ci.yml)
[![PHP 8.2](https://img.shields.io/badge/php-8.2-blue)](https://www.php.net/)
[![PHP 8.3](https://img.shields.io/badge/php-8.3-blue)](https://www.php.net/)
[![PHP 8.4](https://img.shields.io/badge/php-8.4-blue)](https://www.php.net/)
[![Symfony 6.4|7.x](https://img.shields.io/badge/symfony-6.4%20%7C%207.x-purple)](https://symfony.com/)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208-brightgreen)](https://phpstan.org/)
[![License MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Visual tracing and debugging tool for Symfony Messenger. Track message flows, visualize DAG graphs, analyze async processing, and debug message chains.

## Features

- 📊 **Flow Graph Visualization** - Interactive DAG diagrams showing message relationships
- ⏱️ **Timeline View** - Jaeger-style timeline with processing and queue wait times
- 🔍 **Symfony Profiler Integration** - Dedicated panel in the web debug toolbar
- 💻 **CLI Commands** - Inspect flows from the command line
- 📈 **Statistics** - Track message patterns and performance
- 🔗 **Parent-Child Tracking** - Trace message chains with correlation IDs
- ⚡ **Dual Timing Metrics** - Separate processing time vs queue wait time
- 🎯 **Sampling Support** - Production-safe with configurable sampling

## Screenshots

### Dashboard - Flow List
![Dashboard showing list of message flows](docs/images/dashboard_list.png)

### Flow Graph Visualization
![Interactive DAG graph showing message relationships with async markers](docs/images/flow_graph.png)

### Timeline View
![Jaeger-style timeline with processing and queue wait times](docs/images/timeline_view.png)

### Steps Table
![Detailed table with message, handler, transport, status and timing info](docs/images/steps_table.png)

### Symfony Profiler Integration
![Message Flow panel integrated in Symfony Profiler](docs/images/profiler_graph.png)

### Profiler Timeline
![Timeline view in Symfony Profiler showing message timing](docs/images/profiler_timeline.png)

### Profiler Steps Table
![Steps table in Symfony Profiler with all message details](docs/images/profiler_steps.png)

## Installation & Configuration

```bash
composer require michallkanak/symfony-message-flow-visualizer
```

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    MichalKanak\MessageFlowVisualizerBundle\MessageFlowVisualizerBundle::class => ['all' => true],
];
```

Add to your environment:

```bash
# .env
MESSAGE_FLOW_ENABLED=true
```

Create configuration file:

```yaml
# config/packages/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: '%env(bool:MESSAGE_FLOW_ENABLED)%'
    storage:
        type: filesystem  # filesystem | doctrine | redis | memory
        path: '%kernel.project_dir%/var/message_flow'  # for filesystem
        connection: 'default'  # for redis/doctrine
    sampling:
        enabled: false    # Enable for production
        rate: 0.01        # 1% of flows when sampling enabled
    cleanup:
        retention_days: 7
    slow_threshold_ms: 500  # Mark flows >500ms as slow
```

### Full Configuration Reference

```yaml
message_flow_visualizer:
    # Enable/disable the bundle (default: env MESSAGE_FLOW_ENABLED)
    enabled: true
    
    # Storage backend configuration
    storage:
        # Storage type: filesystem, doctrine, redis, memory
        type: filesystem
        
        # Path for filesystem storage (default: %kernel.project_dir%/var/message_flow)
        path: '%kernel.project_dir%/var/message_flow'
        
        # Connection name for doctrine/redis (default: 'default')
        # For Redis: 'redis://localhost:6379' or 'redis://user:pass@host:6379'
        connection: 'default'
    
    # Sampling configuration - recommended for production
    sampling:
        # Enable to track only a percentage of flows (default: false)
        enabled: false
        
        # Percentage of flows to track: 0.01 = 1%, 0.1 = 10%, 1.0 = 100%
        # When a flow is sampled, ALL nested messages are tracked (sampling continuity)
        rate: 0.01
    
    # Data cleanup settings
    cleanup:
        # Days to keep flow data before cleanup command removes it (default: 7)
        retention_days: 7
    
    # Performance threshold - flows exceeding this are marked as slow (default: 500)
    slow_threshold_ms: 500
```

### Environment-specific Examples

**Development** (full tracking):
```yaml
# config/packages/dev/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: filesystem
    sampling:
        enabled: false  # Track all flows
    slow_threshold_ms: 2000  # Higher threshold for dev
```

**Production** (sampling + Doctrine):
```yaml
# config/packages/prod/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: doctrine
    sampling:
        enabled: true
        rate: 0.01  # 1% sampling
    cleanup:
        retention_days: 30
    slow_threshold_ms: 1000
```

**Production** (sampling + Redis):
```yaml
# config/packages/prod/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: redis
        connection: '%env(REDIS_URL)%'
    sampling:
        enabled: true
        rate: 0.05  # 5% sampling
```

**Test** (in-memory):
```yaml
# config/packages/test/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: memory  # No persistence, fast for tests
```

Register the middleware:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - MichalKanak\MessageFlowVisualizerBundle\Middleware\TraceMiddleware
```

## Usage

### Symfony Profiler

The bundle automatically adds a "Message Flow" panel to the Symfony Profiler showing:

- Flow graph visualization
- Timeline view with timing breakdown
- List of all message steps with details

### CLI Commands

```bash
# List recent flows
bin/console messenger:flow:list --limit=20 --status=failed

# List with pagination
bin/console messenger:flow:list --limit=10 --page=2

# Show only slow flows
bin/console messenger:flow:list --slow

# Filter by message class
bin/console messenger:flow:list --message-class=OrderMessage

# Show specific flow details
bin/console messenger:flow:show --id=abc12345

# Show flow by trace ID
bin/console messenger:flow:show --trace=xyz789

# View statistics
bin/console messenger:flow:stats --from="1 hour ago"

# Cleanup old data
bin/console messenger:flow:cleanup --older-than="7 days"

# Dry-run cleanup (preview what would be deleted)
bin/console messenger:flow:cleanup --older-than="7 days" --dry-run
```

### Optional Dashboard

The bundle includes an optional web dashboard. To enable it, add routing:

```yaml
# config/routes/message_flow.yaml
message_flow:
    resource: '@MessageFlowVisualizerBundle/src/Controller/'
    type: attribute
```

Then access at `/message-flow/`.

## Storage Options

The bundle supports multiple storage backends for persisting flow data. Choose based on your needs:

| Storage | Best For | Persistence | Setup Effort |
|---------|----------|-------------|--------------|
| **Filesystem** | Development, simple deployments | File-based | None |
| **Redis** | High-performance, distributed systems | In-memory with TTL | Low |
| **Doctrine** | Production, long-term analytics | Database | Medium |
| **InMemory** | Testing only | None (request-scoped) | None |

### Filesystem (Default)

Zero configuration required. Stores JSON files in your project directory.

```yaml
# config/packages/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: filesystem
        path: '%kernel.project_dir%/var/message_flow'
```

**Setup:** None required. Directory is created automatically.

**Cleanup:**
```bash
bin/console messenger:flow:cleanup --older-than="7 days"
```

---

### Redis

Fast, ephemeral storage with automatic TTL-based cleanup. Requires [predis/predis](https://github.com/predis/predis).

**1. Install Predis:**
```bash
composer require predis/predis
```

**2. Configure bundle:**
```yaml
# config/packages/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: redis
        connection: 'redis://localhost:6379'
        # Or with password: 'redis://user:password@localhost:6379'
```

**3. Configure Predis service (optional, for custom options):**
```yaml
# config/services.yaml
services:
    Predis\Client:
        arguments:
            - '%env(REDIS_URL)%'
```

**Note:** Data expires automatically based on TTL (default: 7 days).

---

### Doctrine

Persistent database storage with full query capabilities. Uses your existing Doctrine connection.

**1. Configure bundle:**
```yaml
# config/packages/message_flow_visualizer.yaml
message_flow_visualizer:
    enabled: true
    storage:
        type: doctrine
```

**2. Generate and run migrations:**
```bash
# Generate migration for bundle tables
bin/console doctrine:migrations:diff

# Run migrations
bin/console doctrine:migrations:migrate
```

This creates two tables:
- `mfv_flow_run` - Flow runs with indexes on `trace_id`, `started_at`, `status`
- `mfv_flow_step` - Steps with foreign key to flow runs

**Cleanup:**
```bash
bin/console messenger:flow:cleanup --older-than="30 days"
```

---

### InMemory (Testing Only)

⚠️ **WARNING:** Not suitable for production with async messages!

Data is stored only in PHP process memory and lost when process ends. Since Messenger workers run in separate processes, async message statuses will NOT be updated.

```yaml
# config/packages/message_flow_visualizer.yaml (test environment only)
message_flow_visualizer:
    enabled: true
    storage:
        type: memory
```

**Use cases:**
- Unit and integration tests
- Development with synchronous transport only

## Timing Metrics

Each message step tracks:

- **Processing Duration** - Time spent in the handler
- **Queue Wait Duration** - Time waiting in async queue
- **Total Duration** - Sum of both

```
[dispatch] ----[queue wait]---- [processing] [complete]
            ↑                   ↑
            queueWaitDurationMs processingDurationMs
            ←───────── totalDurationMs ──────────→
```

## Sampling for Production

Enable sampling to reduce overhead in production:

```yaml
message_flow_visualizer:
    sampling:
        enabled: true
        rate: 0.01  # Track 1% of flows
```

**Important**: When a flow is sampled, ALL related messages are tracked (sampling continuity). No fragmented traces.

## Data Model

### FlowRun

Represents a complete message flow:

| Field | Description |
|-------|-------------|
| id | Unique identifier |
| traceId | Correlation ID for distributed tracing |
| status | running / completed / failed |
| startedAt | Flow start timestamp |
| finishedAt | Flow end timestamp |
| initiator | What triggered the flow (HTTP request, CLI, etc.) |

### FlowStep

Represents a single message dispatch/handling:

| Field | Description |
|-------|-------------|
| messageClass | FQCN of the message |
| handlerClass | FQCN of the handler |
| transport | sync / doctrine / redis / rabbitmq |
| isAsync | Whether processed asynchronously |
| status | pending / handled / failed / retried |
| processingDurationMs | Handler execution time |
| queueWaitDurationMs | Time in queue (async only) |
| totalDurationMs | Total time from dispatch to complete |

## Technologies Used

### Graph Visualization

The bundle uses **native SVG with JavaScript** for rendering interactive DAG (Directed Acyclic Graph) visualizations:

- **SVG-based rendering** - Lightweight, no external dependencies required
- **Hierarchical layout** - Automatic tree-like positioning for message flows
- **Interactive tooltips** - Hover over nodes to see message details, handler info, and timing metrics
- **Async indicators** - Visual distinction between sync and async message processing
- **Responsive design** - Graphs automatically adjust to container size

The graph visualization is implemented in vanilla JavaScript to minimize bundle dependencies and ensure maximum compatibility with Symfony projects.

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x
- Symfony Messenger component

## License

MIT License. See [LICENSE](LICENSE) for details.

## Contributing

Contributions are welcome! Please read the contributing guidelines before submitting PRs.

## Credits

Created by [Michał Kanak](https://github.com/michallkanak)
