<?php

declare(strict_types=1);

require \dirname(__DIR__).'/vendor/autoload.php';

/*
 * PHPUnit snapshots the process' exception-handler stack around every single test and flags
 * "did not remove its own exception handlers" as risky if the depth changed during the test.
 * `Symfony\Bundle\FrameworkBundle\FrameworkBundle::boot()` unconditionally installs
 * `Symfony\Component\ErrorHandler\ErrorHandler` as the global exception handler the first time
 * ANY debug-mode kernel boots in the process — and, correctly, never uninstalls it, because a
 * real request/worker process is expected to keep it for its whole lifetime. Without this, the
 * very first kernel-booting test in the suite would always be flagged risky (the handler gets
 * installed during that test and PHPUnit forcibly, if noisily, restores the pre-test state
 * after), and it stays flagged for every subsequent test that boots a fresh kernel too, since
 * PHPUnit's restore always resets back to "nothing registered".
 *
 * Registering it here, once, before PHPUnit's first per-test snapshot, makes every later kernel
 * boot in the suite a no-op re-registration: `ErrorHandler::register()` sees an instance of
 * itself already installed and restores that same instance instead of pushing a new one, so no
 * test observes a net change. Deliberately the bare registration call, not
 * `Symfony\Component\ErrorHandler\Debug::enable()` — that also reconfigures process-wide
 * `error_reporting`/`display_errors`/`assert.*` ini settings and installs `DebugClassLoader`,
 * none of which this fix needs or wants in a package whose whole point is deprecation-sensitive
 * framework integration.
 */
Symfony\Component\ErrorHandler\ErrorHandler::register();
