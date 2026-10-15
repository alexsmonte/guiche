# Security Policy

Security is part of the public contract of Guichê.

## Supported versions

The stable `1.x` line is the supported security line. Security fixes are expected to target the latest maintained `1.x` release unless this document states otherwise.

## Reporting a vulnerability

Please do **not** open a public issue containing exploit details, credentials, tokens, private URLs, personal data or other sensitive evidence.

Prefer GitHub Private Vulnerability Reporting when it is enabled for this repository. If private reporting is not available, contact the repository owner through a private channel before publishing technical details.

A useful report includes:

- affected Guichê version or commit;
- affected public API or runtime path;
- reproducible steps using sanitized data;
- expected and observed behavior;
- whether a side effect, secret, state value or result can be exposed or executed incorrectly;
- proposed mitigation, when available.

Never send production credentials as evidence. If a real credential may have been exposed, rotate/revoke it independently of the code fix.

## Security invariants

The project treats the following as release-blocking invariants:

- Tool calls from an AI provider are requests, not authorization;
- every Tool execution crosses the hard execution gate immediately before `execute()`;
- WRITE operations do not use generic retry/cache behavior;
- duplicate WRITE operations use explicit idempotency/replay protection;
- result projection prevents internal payloads from being sent to the AI provider;
- runtime observability must not persist raw sensitive payloads;
- `.env`, private keys, credentials, `storage/`, `vendor/` and development-only files are not part of the distributed package;
- session-bound `execution_context.identity` cannot be switched after binding;
- a resolved `tenant_id` cannot be switched for the same session;
- tenant identity comes from trusted runtime context, not from AI-generated Tool arguments;
- ContextAware Tools fail closed before side effects when session/tenant context is unavailable;
- CI does not require repository secrets to execute the quality gate.

## Dependency and CI policy

The stable-line CI:

- tests PHP 8.4 and PHP 8.5;
- runs `composer validate --strict`;
- resolves dependencies independently for each PHP version;
- runs `composer audit`;
- runs the complete `composer check`;
- validates the Composer distribution archive;
- uses least-privilege `GITHUB_TOKEN` permissions;
- does not use `pull_request_target`;
- pins external GitHub Actions to immutable full commit SHAs.

See [`docs/security.md`](docs/security.md) for the architecture-level security model.
