# Security policy

[Русская версия](SECURITY_ru.md)

## Supported versions

Security fixes are currently evaluated against the default development branch. The repository does not publish a
maintenance or backport schedule for older branches or the separate 4.x package line. Users should upgrade to a
maintained release when one is available and verify which source line their application actually installs.

## Reporting a vulnerability

Report suspected vulnerabilities privately to **Ivan Zorin**, <creator@localzet.com>. Do not disclose exploit details,
credentials, personal data or production traffic in a public issue, discussion or pull request.

Please include, when safe:

- affected Server version, commit or package line and PHP version;
- operating system and relevant optional extensions/backend;
- impact and preconditions;
- a minimal reproduction or proof of concept that uses synthetic data;
- expected and observed behavior.

The maintainer will acknowledge and investigate reports as availability permits. No response-time or remediation SLA is
currently published. Coordinate public disclosure with the maintainer after a fix or mitigation is available.

## Deployment security boundary

A passing test suite does not certify an application deployment. Application operators remain responsible for TLS,
network exposure, trusted proxy configuration, protocol and connection limits, session storage, upstream allowlists,
PHP extensions and process privileges. Do not expose administrative process-control commands to untrusted users.

Fixes are verified against the current default branch. A maintenance schedule for older release lines has not been established. A successful local test or CI run does not guarantee the security of an application deployment: validate proxy trust, TLS, protocol limits, session storage and exposed listeners for your application.
