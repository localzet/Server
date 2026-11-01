# Contributing to Localzet Server

[Документ на русском языке](CONTRIBUTING_ru.md)

Thank you for helping improve Localzet Server. Changes should preserve the public PHP API, protocol behavior and
non-blocking process lifecycle unless the proposal explicitly targets one of those contracts.

## Getting Started

Before opening a change, read:

- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Security policy](SECURITY.md) for vulnerability reports
- [English README](../README.md) and [Russian README](../README.ru.md)

The project supports PHP 8.1 and later. The core has no mandatory third-party runtime package; optional extensions and
event-loop backends must remain optional unless a change is explicitly approved as a compatibility change.

## How to Contribute

Useful contributions include:

- Report reproducible bugs with PHP version, OS, Server branch/package version, minimal input and expected behavior.
- Propose features with their use case and public API or wire-format impact.
- Submit focused fixes with regression coverage and documentation where behavior changes.
- Improve documentation or test coverage.

## Local checks

Install development tools and run the narrow checks relevant to your change:

```sh
composer install
composer validate --strict
composer run lint
composer run test
composer run analyze
```

For protocol, lifecycle or gateway changes, also run the corresponding suite:

```sh
composer run test:revolt
composer run test:gateway
composer run test:fuzz
composer run test:memory
composer run test:integration
```

`test:gateway` and `test:integration` start local network listeners and child processes. `release:audit` aggregates
the smoke and integration checks; it is broader than a quick local check. The optional `composer benchmark` is a parser
microbenchmark, not a performance acceptance test. Do not include benchmark output as a performance claim without
recording the host and PHP environment.

## Engineering expectations

- Preserve PHP 8.1 compatibility, PSR-4 namespaces and public signatures unless the change explicitly updates the
  compatibility contract.
- Keep socket I/O non-blocking; clean up timers, listeners and child processes on close and shutdown.
- For framing changes, cover partial and coalesced packets, malformed input and disconnect/shutdown behavior.
- Avoid adding runtime dependencies for optional integrations. Document new extensions and test unavailable-backend
  behavior.
- Do not include secrets, production data, generated vendor files or unrelated formatting changes.
- Use concise English comments only when they explain non-obvious behavior. Keep user-facing documentation in sync in
  English and Russian when practical.

## Creating a Pull Request

1. Fork the repository and branch from the current default branch.
2. Keep the change focused; explain compatibility, security and operational impact in the pull request.
3. Add or update regression checks for behavior changes and run the relevant local commands above.
4. Ensure CI passes and disclose checks that could not be run, including optional extension/platform coverage.
5. Submit a pull request with a concise title and a summary of the change and verification.

Do not publish vulnerability details through a public pull request; use the private process in the security policy.
