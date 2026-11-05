# Migrating from PocketMine-MP 5.47 to Quark 6

Quark 6 is based on PocketMine-MP 5.47.0 but introduces breaking changes to namespaces, dependencies, configuration, and APIs.

## Plugin Migration

### Namespaces

- Core namespace: `pocketmine\` → `quark\`
- External libraries such as `pocketmine\math` and `pocketmine\nbt` remain unchanged.
- BedrockProtocol types: moved to `quark\network\mcpe\protocol` under their existing names.
- Packets: `quark\network\mcpe\protocol\packet`
- Packet constants: defined directly on packet classes (e.g. `AnimatePacket::ACTION_SWING_ARM`).
- Update reflection strings, serialized class names, PHPStan configuration, tests, and scripts referencing the old namespace.

### Composer

- Server dependency: `pocketmine/pocketmine-mp` → `quark/quark`
- Existing `axolotl-pm/*` and `pocketmine/*` library dependencies remain supported where applicable.
- Quark no longer replaces `pocketmine/pocketmine-mp` through Composer.
- Regenerate the lock file and autoloader after updating dependencies.

### Files & Runtime

| PocketMine-MP 5 | Quark 6 |
|---|---|
| `PocketMine-MP.phar` | `Quark.phar` |
| `src/PocketMine.php` | `src/Quark.php` |
| `pocketmine.yml` | `config/server.toml` |
| `plugin.yml` | `plugin.toml` |

- `pmmpthread` retains its existing extension name.
- Update deployment scripts, containers, service configurations, health checks, and file paths.

## API & Behavior Changes

### Events

- `PlayerExhaustEvent` → `EntityExhaustEvent` (Replace `getPlayer()` with `getEntity()`, returning the affected `Human`).

### TNT

- `TNT::ignite(int $fuse = 80)` now returns `bool`.
- Returns `false` when ignition is rejected by the configured TNT limiter.
- Only consume ignition items or assume TNT was primed after a successful return.

### Redstone

- Native redstone management is available through `$server->getRedstoneManager()`.
- `setWorldEnabled($world, $enabled)` — Controls redstone per world.
- `setChunkEnabled($world, $chunkX, $chunkZ, $enabled)` — Overrides redstone per chunk.
- `clearChunkOverride($world, $chunkX, $chunkZ)` — Removes a chunk override.
- `clearWorldOverride($world)` — Removes a world override.
- Chunk overrides take precedence over world settings.
- Runtime overrides are not persistent and world operations must run on the main thread.
- Disable native redstone where plugins provide their own implementation.

### Damage

- Expanded protected-damage and final-damage access in `EntityDamageEvent`.
- Some damage modifier constants have changed integer values. Use named constants instead of hardcoded values.

### Removed Mechanics

- Thorns and Frost Walker no longer function or appear through normal acquisition.
- Armor no longer receives unconditional per-tick callbacks.
- Turtle Helmets no longer grant Water Breathing.
- Plugins relying on these mechanics must implement their own behavior.

### Compression

- Compression algorithm is configurable rather than fixed to zlib.
- Plugins should use Quark's `Compressor` abstraction.
- Snappy requires `ext-snappy`; invalid or unavailable compression modes fall back to zlib.

## Configuration Migration

Use `--migration-dry-run` to preview migration, then `--migrate-legacy` to convert supported legacy configurations with a recoverable snapshot.

Review the generated [server.toml](../resources/server.toml) and [plugins.toml](../resources/plugins.toml).

### New Configuration Options

- `network.compression-algorithm`
- `network.async-compression`
- `network.async-compression-threshold`
- `network.combat-low-latency-feedback`
- `memory.garbage-collection.threshold`
- `memory.garbage-collection.period`
- `redstone.*`
- `tnt-limits.*`

Migration stops on invalid or conflicting values. Resolve the reported configuration key and rerun `--migrate-legacy`.

## Migration Checklist

1. Back up worlds, player data, plugins, and configurations.
2. Update plugin namespaces, metadata, and Composer dependencies.
3. Review event, TNT, damage, redstone, armor, and compression compatibility.
4. Install the required PHP runtime and native extensions.
5. Migrate legacy configurations and review both TOML files.
6. Test plugins and production world copies under representative load.
7. Check logs for missing classes, compatibility issues, TNT rejections, and redstone limits before deployment.