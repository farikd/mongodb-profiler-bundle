# Changelog

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
