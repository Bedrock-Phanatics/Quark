# Quark 6.0.0

Quark 6.0.0 is the first release under the Quark identity. It is based on PocketMine-MP 5.47.0 and introduces a new namespace, performance-oriented networking, native redstone, TNT safeguards, configuration controls, and intentional API changes.

## Highlights

- Rebranded the server, executable, configuration, packages, namespaces, tooling, and services as Quark.
- Added native redstone simulation with configurable world policies and per-tick safety limits.
- Added optional Snappy packet compression with automatic zlib fallback.
- Added configurable TNT limits covering all core ignition paths.
- Raised the production runtime requirement to 64-bit PHP 8.4.

## Networking and performance

- Added `zlib` and `snappy` packet-batch compression modes.
- Added safe fallback to zlib when Snappy is selected but `ext-snappy` is unavailable.
- Added configurable compression thresholds, levels, asynchronous compression, and asynchronous size thresholds.
- Added optional low-latency combat feedback with at most one early packet batch per player per tick.
- Reduced work in entity, armor, world-storage, serialization, and network hot paths.
- Expanded use of native extensions for encoding, serialization, compression, database access, and networking.
- Added configurable adaptive and periodic garbage collection, including options to disable Quark-managed collection.

## Redstone

- Added native propagation and scheduled component processing.
- Added wires, torches, repeaters, comparators, lamps, buttons, levers, pressure plates, doors, pistons, dispensers, and related powered behavior.
- Added per-world enablement with `all`, `allowlist`, and `blocklist` policies.
- Added runtime world and chunk overrides through `Server::getRedstoneManager()`.
- Added limits for wire-network size, queued updates, updates per tick, piston actions, and dispenser actions.
- Deferred excess work across ticks to prevent a single circuit from monopolizing the server tick.
- Added redstone-specific timings and transient world/chunk state management.

## TNT

- Added centralized TNT activation checks for redstone, fire, fire charges, Flint and Steel, Fire Aspect, projectiles, explosions, unstable blocks, and dispensers.
- Added optional limits for active TNT per origin chunk, active TNT in the surrounding 3x3 chunk area, ignitions per chunk per tick, and dispenser ignitions per second.
- Tracks primed TNT against its origin chunk even after the entity moves.
- Individual numeric limits can be set to `0` for unlimited behavior.
- Changed `TNT::ignite(int $fuse = 80)` to return `bool`; `false` means activation was rejected by the limiter.
- Consumable ignition items are only consumed or damaged when ignition succeeds.

## Gameplay and correctness

- Fixed absorption-heart client/server desynchronization.
- Hardened enchanting, anvil, and crafting transaction validation.
- Removed active Thorns and Frost Walker behavior and acquisition paths while retaining inert saved/network enchantment data.
- Removed per-tick worn-item callbacks from armor processing.
- Removed the Turtle Helmet Water Breathing effect.

## API

- Changed the root PHP namespace from `pocketmine` to `quark`.
- Renamed the Composer root package to `quark/quark` while retaining the existing `axolotl-pm/*` and `pocketmine/*` dependencies.
- Renamed the bootstrap to `src/Quark.php`, the release artifact to `Quark.phar`, and the main configuration to `quark.yml`.
- Retained the existing `pmmpthread` native extension contract.
- Added the redstone manager and runtime world/chunk override API.
- Changed `TNT::ignite()` to return activation success as `bool`.
- Expanded protected- and final-damage APIs.
- Updated selected `EntityDamageEvent` modifier constant values; plugins must not rely on their former raw integers.
- Removed or changed internal behavior tied to mechanics eliminated for performance.

See [Migrating from PocketMine-MP 5.47](docs/MIGRATING-FROM-POCKETMINE-5.md) for plugin migration guidance.

## Configuration

Added or expanded settings for:

- Network compression algorithm, batch threshold, compression level, asynchronous compression, and asynchronous threshold.
- Low-latency combat feedback.
- Adaptive and periodic garbage collection.
- Redstone enablement, world policy, world list, component limits, piston limits, and dispenser limits.
- TNT enablement and activation/entity limits.
- Crash reports, anonymous statistics, updater, timings, console behavior, memory limits, and plugin data placement.

Review the new `resources/quark.yml` instead of copying an older configuration unchanged.

## Compatibility

Quark 6.0.0 is a breaking release. PocketMine-MP plugins are not drop-in compatible because the PHP namespace and package ecosystem have changed. Existing worlds should be backed up before migration, and every plugin must be reviewed and rebuilt for Quark.
