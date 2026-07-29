# Changelog

## 1.0.0 — 2026-07-29

Initial release: driver-level command capture, web-profiler panel with N+1 duplicate
detection and a bounded query cap, on-demand `explain` with IXSCAN/COLLSCAN classification,
and a CLI/messenger summary.

Requires PHP >= 8.4 and Symfony `^7.4 || ^8.0`. Symfony 7.3 is not supported: it is
end-of-life and every 7.3.x release carries unpatched security advisories, so Composer's
default policy refuses to install it.
