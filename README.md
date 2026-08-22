# Quark

Quark is a performance-focused Minecraft: Bedrock Edition server derived from PocketMine-MP and Axolotl. It keeps the PocketMine plugin model where practical while reducing overhead in frequently executed code paths.

> [!IMPORTANT]
> Quark currently supports **64-bit PHP 8.4 only** for production use. PHP 8.5 is exercised in CI for forward compatibility, but is not yet a supported production runtime.

## Getting started

Use a prebuilt PHP runtime from [Bedrock-Phanatics/PHP-Binaries](https://github.com/Bedrock-Phanatics/PHP-Binaries). Quark requires the extensions declared in `composer.json`; optional features such as Snappy network compression also require their corresponding native extension.

Install dependencies and start the server using the same workflow as other PocketMine-derived servers:

```bash
composer install --no-dev --classmap-authoritative
php PocketMine-MP.phar
```

For development, omit `--no-dev` so PHPUnit and PHPStan are installed.

## Project repositories

- [Quark](https://github.com/Bedrock-Phanatics/Quark) — server source, workflows, and releases
- [BedrockProtocol](https://github.com/Bedrock-Phanatics/BedrockProtocol) — Quark's Bedrock packet and login protocol implementation
- [PHP-Binaries](https://github.com/Bedrock-Phanatics/PHP-Binaries) — supported PHP runtimes and required native extensions
- [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) — primary upstream project

## Goals

- Improve throughput and reduce latency in hot paths.
- Prefer measurable optimizations over speculative complexity.
- Use native extensions for compression, encoding, serialization, database, and networking workloads when they provide a meaningful benefit.
- Preserve PocketMine plugin compatibility where it does not conflict with correctness or performance.
- Keep runtime behavior configurable when different server workloads need different tradeoffs.
- Move non-essential vanilla mechanics out of unconditional tick paths.

## Notable changes

### Performance

- Optional Snappy compression for Bedrock network packet batches.
- Greater use of native encoding and serialization extensions.
- Reduced unnecessary work in entity, armor, world-storage, and networking paths.

### Runtime and tooling

- PHP 8.4 is the minimum and currently supported production runtime.
- CI validates PHP 8.4 and checks PHP 8.5 compatibility.
- Quark uses the [Bedrock-Phanatics BedrockProtocol fork](https://github.com/Bedrock-Phanatics/BedrockProtocol).

### Gameplay

- Thorns and Frost Walker runtime effects and acquisition paths are removed. Existing items retain inert enchantment data for save and network compatibility.
- Per-tick worn-item callbacks are removed from armor processing.
- Turtle helmets behave as ordinary helmets and do not grant Water Breathing.

### Correctness and API

- Fixed absorption-heart client/server desynchronization.
- Hardened enchanting, anvil, and crafting transaction validation.
- Added configurable garbage-collector behavior, including the option to disable Quark-managed collection.
- Expanded protected- and final-damage APIs.
- Updated selected `EntityDamageEvent` modifier constant values.

> [!NOTE]
> Plugins using the provided damage constants should continue to work. Plugins depending on previous raw integer values or undocumented internals may require changes.

## Configuration

Snappy affects Bedrock network packet compression only. LevelDB, player data, Java-world compatibility, and crash reports retain their format-required compression codecs.

Random block ticking can be reduced or disabled through `chunk-ticking.blocks-per-subchunk-per-tick` and `chunk-ticking.disable-block-ticking` in `pocketmine.yml`.

## Compatibility

Quark aims to support PocketMine plugins where practical, but performance and correctness changes may alter internal or undocumented behavior. Plugins should avoid hardcoded internal constants, direct dependency on implementation details, and assumptions about removed vanilla mechanics.

The `pocketmine` PHP namespace, world compatibility tags, and other persisted identifiers remain unchanged where renaming them would break plugins or existing worlds.

## Development

Before submitting changes, run:

```bash
composer install
vendor/bin/phpstan analyze --no-progress --memory-limit=2G
vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-warning tests/phpunit
composer update-codegen
```

Performance changes should include evidence that they target a real bottleneck, preserve correctness, and avoid trading stability for negligible gains.

## License and credits

Quark builds on PocketMine-MP, Axolotl, BedrockProtocol, and the wider PocketMine ecosystem. It retains the licensing requirements of upstream projects and incorporated components.
