<p align="center">
  <img src="assets/quark-logo.png" alt="Quark" width="256">
</p>

<h1 align="center">Quark</h1>

<p align="center">
  A performance-first, PvP-first Minecraft: Bedrock Edition server written in PHP.
</p>

Quark is built for servers where tick stability and responsive combat matter equally. It keeps expensive work bounded, removes avoidable hot-path overhead, and prioritizes latency-sensitive PvP feedback without changing Minecraft's combat rules. Quark 6 also introduces native redstone, bounded TNT processing, optional Snappy network compression, and an independent API namespace.

<p align="center">
    Join our <a href="https://discord.gg/Yv9qPRQNc3">Discord</a>!
</p>

> [!IMPORTANT]
> Quark 6 requires **64-bit PHP 8.4 or 8.5** and Quark-compatible native extensions. It is a breaking release and does not load PocketMine-MP plugins without migration.

## Features

- Performance-first runtime design focused on stable tick times under real server load.
- PvP-first networking that flushes synchronous hit feedback before the normal end-of-tick packet flush.
- Native redstone with world policies, runtime overrides, and per-tick safety limits.
- Optional Snappy packet compression with automatic zlib fallback.
- Configurable TNT limits across all core ignition sources.
- Configurable synchronous and asynchronous packet compression.
- Adaptive garbage collection and memory controls.
- Hardened inventory transactions and damage handling.
- Quark-owned PHP namespace, root Composer package, configuration, tooling, and release artifact.

### Faster combat feedback

When `network.combat-low-latency-feedback` is enabled, Quark immediately flushes the synchronous effects of a successful hit to the involved players instead of always waiting for the normal end-of-tick network flush. At 20 TPS, this can save most of one 50 ms tick in the best case, depending on when the hit occurs, server load, and network conditions.

This improves how quickly hits feel visible to players; it does not reduce Minecraft's attack cooldown, alter hit registration rules, or guarantee a fixed millisecond improvement.

See the complete [Quark 6.0.0 changelog](CHANGELOG.md) and [PocketMine-MP 5.47 migration guide](docs/MIGRATING-FROM-POCKETMINE-5.md).

Existing installations can inspect required changes with `--migration-dry-run` and migrate with `--migrate-legacy` before starting Quark 6.

## Requirements

- A 64-bit operating system
- PHP 8.4 or 8.5
- The extensions listed in `composer.json`
- `ext-snappy` when using Snappy compression

Supported PHP builds are published through [Bedrock-Phanatics/PHP-Binaries](https://github.com/Bedrock-Phanatics/PHP-Binaries).

## Installation

Install dependencies for development:

```bash
composer install
```

Build the production server:

```bash
composer make-server
```

Start Quark:

```bash
php Quark.phar
```

On first launch, review `config/server.toml` and `config/plugins.toml` before exposing the server publicly.

## Configuration

Core settings are in `config/server.toml`; plugin loading rules are in `config/plugins.toml`. Notable server sections include:

- `network` — compression, batching, latency, encryption, and MTU behavior
- `memory` — limits, garbage collection, and memory dumps
- `redstone` — world policy and component-processing limits
- `tnt-limits` — active entity and ignition limits
- `crash-dumps` — crash details in the console and local `.txt` reports
- `auto-updater` and `timings` — external services

The templates are [server.toml](resources/server.toml) and [plugins.toml](resources/plugins.toml).

## Plugin development

Quark plugins use the `quark\` PHP namespace and target `quark/quark` through Composer. Plugins written for PocketMine-MP 5.x must be migrated and rebuilt.

Declare plugin API `6.0.0`. Quark does not advertise Composer compatibility with `pocketmine/pocketmine-mp`; its external `pocketmine/*` and `axolotl-pm/*` library identifiers remain unchanged.

See the [Quark 6 folder plugin](examples/plugins/QuarkExample) and [script plugin](examples/plugins/ExampleScriptPlugin.php) examples.

Before submitting changes, run:

```bash
vendor/bin/phpstan analyze --no-progress --memory-limit=2G
vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-warning tests/phpunit
composer update-codegen
```

## Project repositories

| Repository | Purpose |
|---|---|
| [Quark](https://github.com/Bedrock-Phanatics/Quark) | Server source and releases |
| [BedrockProtocol](https://github.com/Bedrock-Phanatics/BedrockProtocol) | Bedrock protocol implementation |
| [PHP-Binaries](https://github.com/Bedrock-Phanatics/PHP-Binaries) | Supported PHP runtimes |

### Upstream PMMP resources

These links belong to PocketMine-MP and are useful as upstream references; they are not Quark services or Quark release channels.

- [PocketMine-MP API documentation](https://apidoc.pmmp.io/)
- [PocketMine-MP translations on Crowdin](https://crowdin.com/project/pocketmine)
- [PocketMine-MP releases and downloads](https://github.com/pmmp/PocketMine-MP/releases)

## License

Quark is distributed under the GNU Lesser General Public License v3.0. It incorporates and derives from open-source projects whose copyright and license terms remain applicable.

The redstone implementation is based substantially on [Cosmoverse/Redstone](https://github.com/Cosmoverse/Redstone), created by [Muqsit](https://github.com/Muqsit).
