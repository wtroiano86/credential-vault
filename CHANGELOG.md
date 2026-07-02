# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-02

### Added

- `VaultOwner` abstract base model for applications that want vault support without wiring the `HasVault` trait manually.
- Standalone package test infrastructure (Orchestra Testbench `TestCase`, `Pest.php`, `rector.php`).
- `VaultService` now resolves the vault entry model from `packages.skillcraft-credential-vault.general.model` config (falls back to `VaultEntry` when invalid).

### Changed

- PHPStan raised to max level with zero ignored lines or baselines.
- Test coverage raised to 100% across `src/`.
- Package tooling paths corrected for standalone Composer installs (PHPUnit bootstrap, PHPStan Larastan include, migration test paths).
- `VaultService`, `LaravelVaultEncryption`, and `VaultEntry` hardened for max-level static analysis (typed config resolution, interface binding guards, metadata normalization).
- Rector applied: readonly service classes, `#[Override]` attributes, typed class constants.

### Fixed

- `CredentialVaultServiceProvider` throws `ConfigurationException` when configured encryption or vault classes do not implement the expected contracts.
