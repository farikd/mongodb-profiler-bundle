# mongodb-profiler-bundle

A dev-only MongoDB query profiler for Symfony, for apps using the raw `mongodb/mongodb`
driver (no Doctrine ODM). It hooks the driver's own command-monitoring API, so it sees
*every* command issued in the process — direct `collection()->find()` calls, health-check
pings, anything — regardless of which repository or service issued it.

It gives you:

- A **web-profiler panel** listing every MongoDB command in the request: operation,
  collection, filter/pipeline (pretty-printed, BSON-aware), duration, and errors.
- **N+1 / duplicate detection** — byte-identical (operation, collection, filter) commands
  seen more than once in the same request, grouped and counted, with the repository call
  site that issued them.
- A **bounded query cap** (`max_queries`) so a runaway loop can't blow up memory: past the
  cap, totals and duplicate counts stay exact, but individual rows stop being stored.
- **On-demand `explain`** — click a captured `find`/`aggregate`/`count` in the panel to
  re-run it with `executionStats` and see whether it used an index (`IXSCAN`) or scanned
  the collection (`COLLSCAN`), plus docs-examined/docs-returned.
- A **CLI/messenger summary** — the same counts and duplicate groups, printed at the end
  of a console command or `messenger:consume` run, when opted in.

It is dev/test tooling only. With `enabled: false` (or outside `kernel.debug`, its
default), the bundle registers **no services at all** — a production container is
identical to one without the package installed.

## Installation

```bash
composer require --dev farikd/mongodb-profiler-bundle
```

```php
// config/bundles.php
return [
    // ...
    Farikd\MongodbProfilerBundle\MongodbProfilerBundle::class => ['dev' => true, 'test' => true],
];
```

## Configuration reference

All keys are optional; every default is shown below.

```yaml
# config/packages/mongodb_profiler.yaml
mongodb_profiler:
    # Register the profiler services at all. Defaults to %kernel.debug%, so it is
    # normally left unset — set it explicitly only to force it on/off.
    enabled: null

    # Record during CLI runs (console commands, messenger workers). The web (HTTP)
    # path records unconditionally; CLI opts in explicitly so ordinary crons and
    # consumers pay nothing by default.
    cli: false

    # Per-request cap on individually stored commands. Totals and duplicate-group
    # counts stay exact past it; only per-row storage (and its backtrace) stops.
    max_queries: 2000

    # Command names never captured. Replaces the built-in list wholesale — it is
    # NOT merged with it. See "What it captures / what it ignores" below for the
    # built-in default and why `ping` is deliberately absent from it.
    ignored_commands:
        - isMaster
        - ismaster
        - hello
        - saslStart
        - saslContinue
        - buildInfo
        - getMore

    # Extra class-name prefixes to skip when picking the "caller" frame from the
    # backtrace, on top of this package's own namespace and `MongoDB\`. Use this to
    # push the reported origin past your own repository/persistence-layer plumbing,
    # e.g. ['App\Infrastructure\Persistence\'].
    ignored_trace_prefixes: []

    # Connection used to re-run a captured read with `explain`. Leaving either
    # unset disables the explain feature (and its controller/route are never
    # registered).
    explain:
        uri: null
        database: null
```

## Routes

The bundle never registers routes itself — a bundle registering routes into an app
regardless of environment is exactly the kind of surprise a dev-only tool must not cause.
Import them yourself, scoped to `dev`/`test`:

```yaml
# config/routes/mongodb_profiler.yaml
when@dev:
    mongodb_profiler:
        resource: '@MongodbProfilerBundle/config/routes.php'
when@test:
    mongodb_profiler:
        resource: '@MongodbProfilerBundle/config/routes.php'
```

This is what makes the panel's **Explain** button work; without it the panel still
renders, just without that button.

## Security

The explain endpoint lives under `/_mongodb-profiler/`. If your firewall guards
everything by default, carve out an exception the same way the standard `_profiler`/`_wdt`
paths already get one:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        mongodb_profiler:
            pattern: ^/_mongodb-profiler
            security: false
```

## CLI usage

Set `mongodb_profiler.cli: true` to also profile console commands and
`messenger:consume` workers — off by default, so ordinary crons and consumers pay
nothing. With it on, every command prints a summary to stdout when it terminates
(`console.terminate`):

```
 MongoDB profile
 ================

 12 command(s) in 34.56 ms

 ------- ------------ ------- ----------
  op      collection   count   total ms
 ------- ------------ ------- ----------
  find    videos       8       28.10
  insert  videos       4       6.46
 ------- ------------ ------- ----------
```

Failed commands, duplicate/N+1 groups, and a capped-query warning are appended the same
way they appear in the web panel. No config check gates the print itself: the subscriber
only records anything in CLI when `cli` is true, so a non-empty count already means
profiling was on, and a plain run records (and prints) nothing.

## What it captures / what it ignores

Capture attaches at the MongoDB PHP driver's command-monitoring layer — the only hook
that sees every command a process issues, direct `collection()->countDocuments()` calls
and health-check pings included, with the driver's own measured duration for free.

A handful of connection-handshake / topology commands are ignored by default (see
`ignored_commands` above): `isMaster`/`ismaster`, `hello`, `saslStart`/`saslContinue`,
`buildInfo`, and `getMore` — internal chatter that is never something *your* code issued
on purpose.

**`ping` is deliberately not in that list.** An application-issued `ping` (e.g. a health
check that calls `getDatabase()->command(['ping' => 1])` straight past its repository
layer) is exactly the driver-level capture this profiler exists to prove works — and the
PHP driver issues no background heartbeat pings of its own, so there is no noise to filter
out by excluding it.
