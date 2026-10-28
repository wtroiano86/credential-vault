# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-28

### Added

- Laravel Boost skills for vault development, encryption upgrades, and Laravel AI agent tools, with consumer installation and update instructions.
- Optional Laravel AI SDK tools `ListCredentialKeys` and `GetCredentialStatus`, with explicit owner/key scope, per-call Gate authorization, bounded discovery, and fixed responses that exclude metadata and secrets.
- Production agent integration documentation, including queued jobs, error contracts, and SDK audit events.
- Security and SDK integration tests for the tools; `laravel/ai:^1.3` is a development dependency and optional consumer dependency.
- `VaultOwner` abstract base model for applications that want vault support without wiring the `HasVault` trait manually.
- Standalone package test infrastructure (Orchestra Testbench `TestCase`, `Pest.php`, `rector.php`).
- `VaultService` now resolves the vault entry model from `packages.credential-vault.general.model` config (falls back to `VaultEntry` when invalid).

### Security

- Bound new ciphertext to owner type/ID, credential name, and vault connection/database/table context; reject copied or downgraded payloads.
- Disabled legacy service reads by default. Added the resumable `credential-vault:upgrade-encryption` command and `--dry-run`; existing installations must follow the documented upgrade procedure.
- Removed PHP object deserialization from the built-in encryption driver; legacy decoding accepts serialized strings only.
- Redacted plaintext arguments at service, facade, trait, and encryption entry points.
- Reject unsaved owners, invalid IDs, and owners on a different connection from the vault model.

### Changed

- Clarified application authorization, upgrade scope/observer behavior, the SDK tool response format, and package development checks in the documentation.
- Mutation events dispatch after the outer transaction commits. Observer vetoes throw `CredentialPersistenceException`; deleting a missing credential emits no event.
- Storing a deleted credential restores its row with the replacement value/metadata and clears old expiry and rotation time.
- Added the direct `illuminate/console` dependency for the upgrade command.
- Removed the `skillcraft/support` dependency and its Composer repository. Configuration and migrations now use Laravel's built-in service provider methods.
- Updated the README, package reference, and Boost guidelines to match current configuration, model, encryption, expiry, event, and exception behavior.
- PHPStan raised to max level with zero ignored lines or baselines.
- Expanded test coverage across `src/`, including credential security and agent-tool integration.
- Package tooling paths corrected for standalone Composer installs (PHPUnit bootstrap, PHPStan Larastan include, migration test paths).
- `VaultService`, `LaravelVaultEncryption`, and `VaultEntry` hardened for max-level static analysis (typed config resolution, interface binding guards, metadata normalization).
- Rector applied: readonly service classes, `#[Override]` attributes, typed class constants.

### Fixed

- Wrap credential mutations in transactions with row locking and checked persistence outcomes.
- Anchor default key validation with `\A`/`\z` to reject trailing newlines; reject malformed pattern configuration.
- Corrected the standalone `test:compact` Composer script and removed the fixed Composer version so releases use VCS tags.
- Added the documented `skillcraft-credential-vault-config` and `skillcraft-credential-vault-migrations` publish tags while retaining the existing `credential-vault-*` tags.
- `CredentialVaultServiceProvider` throws `ConfigurationException` when configured encryption or vault classes do not implement the expected contracts.
