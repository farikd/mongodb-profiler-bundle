# Changelog

## 1.0.2 — 2026-07-30

Post-release review fixes. No API or configuration change.

**Fixed**

- `commandSucceeded()`/`commandFailed()` now sit inside the same drop guard as
  `commandStarted()`. The guard has to wrap the whole method, not live inside `finish()`:
  the event accessors run as *arguments*, before the callee is entered, so a throw there
  surfaced at the caller's own `find()` — precisely what the guard exists to prevent.
- The capped-queries warning in the panel told you to raise `MONGO_PROFILER_MAX_QUERIES`,
  which is not a thing this package reads. It names `mongodb_profiler.max_queries` now.
- `MongoDataCollector`'s "do not override `getName()`" note described the wrong mechanism.
  The rule is real; the reason is that the profiler indexes collectors by `getName()` while
  `TemplateManager` looks panels up by the `data_collector` tag's `id`. The DI service id
  plays no part.

**Changed**

- The explain controller no longer declares `->public()`:
  `RegisterControllerArgumentLocatorsPass` already makes every `controller.service_arguments`
  service public.
- README gained a Requirements section, and the `explain` config comment no longer claims
  the *route* is conditionally registered — a bundle cannot register routes; only the
  services behind it are conditional.
- PHPStan runs with `--memory-limit=1G`. Level max peaks at ~124 MB against the image's
  128M default, so the gate's outcome was turning on result-cache state.
- CI also runs on `v*` tag pushes, so the exact tree Packagist imports is the one gated.

**Tests**

- The `enabled` default is now covered by booting a real non-debug kernel (and by forcing
  it back on with `enabled: true`), rather than only through the explicit `enabled: false`
  branch. That default is the whole production-safety story and nothing exercised it.
- The explain-services import is asserted from both sides, so the negative case cannot pass
  merely because a private service is invisible to the test container.
- Every name in `ProfilerSubscriber::EXPLAINABLE_COMMANDS` is now driven through
  `ExplainCommandBuilder`. The two lists have separate owners, and a name added to only the
  first ships an Explain button that 500s on the builder's default arm.

## 1.0.1 — 2026-07-29

Metadata only, no code change. Renamed the `n+1` keyword to `n-plus-one`: Packagist
requires keywords to match `[\p{N}\p{L} ._-]+`, and rejected the branch import over it.
`composer validate --strict` now runs in CI, since Packagist only validates after a tag
is already pushed.

## 1.0.0 — 2026-07-29

Initial release: driver-level command capture, web-profiler panel with N+1 duplicate
detection and a bounded query cap, on-demand `explain` with IXSCAN/COLLSCAN classification,
and a CLI/messenger summary.

Requires PHP >= 8.4 and Symfony `^7.4 || ^8.0`. Symfony 7.3 is not supported: it is
end-of-life and every 7.3.x release carries unpatched security advisories, so Composer's
default policy refuses to install it.
