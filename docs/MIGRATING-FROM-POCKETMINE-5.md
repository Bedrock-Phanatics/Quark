# Migrating from PocketMine-MP 5.47 to Quark 6

Quark 6 is based on PocketMine-MP 5.47.0, but it is not a drop-in replacement. The project identity, PHP namespace, package coordinates, runtime files, and selected APIs have changed.

## Required plugin changes

### Namespace

Replace imports and fully qualified class names under `pocketmine\` with `quark\`.

```php
// Before
use pocketmine\plugin\PluginBase;

// Quark 6
use quark\plugin\PluginBase;
```

Update reflection strings, serialized class names, PHPStan configuration, test namespaces, and scripts that reference the old namespace. A text replacement is a starting point, not a substitute for testing.

### Composer

Target `quark/quark` for the server. Quark continues to use the existing `axolotl-pm/*` and `pocketmine/*` library packages, so plugins may keep direct dependencies on those libraries where appropriate. Regenerate the lock file and autoloader after changing the root server dependency.

### Runtime files

| PocketMine-MP 5.47 | Quark 6 |
|---|---|
| `PocketMine-MP.phar` | `Quark.phar` |
| `src/PocketMine.php` | `src/Quark.php` |
| `pocketmine.yml` | `quark.yml` |
The `pmmpthread` extension name is unchanged.

Update deployment scripts, service units, containers, health checks, file paths, environment validation, and download automation.

## API and behavior changes

### TNT ignition

`quark\block\TNT::ignite(int $fuse = 80)` returns `bool` in Quark 6.

```php
if(!$tnt->ignite()){
    // Ignition was rejected by the configured TNT limiter.
}
```

Do not consume an ignition item or assume that a primed entity exists unless the method returns `true`.

### Redstone

Quark provides a native redstone manager:

```php
$redstone = $server->getRedstoneManager();
$redstone->setWorldEnabled($world, false);
$redstone->setChunkEnabled($world, $chunkX, $chunkZ, true);
$redstone->clearChunkOverride($world, $chunkX, $chunkZ);
$redstone->clearWorldOverride($world);
```

Chunk overrides take precedence over world policy. Runtime overrides are non-persistent and world access must remain on the main thread.

Plugins implementing their own redstone should disable Quark redstone for the affected worlds or chunks to avoid competing updates.

### Damage events

Protected- and final-damage access has been expanded, and selected `EntityDamageEvent` modifier constants use different raw integer values. Use named constants and public methods instead of persisted or hardcoded modifier integers.

### Removed mechanics

- Thorns and Frost Walker no longer execute or appear through normal acquisition paths.
- Worn armor no longer receives unconditional per-tick callbacks.
- Turtle Helmets no longer grant Water Breathing.

Plugins depending on these mechanics must implement their own scheduled behavior.

### Compression

Network compression is selected by configuration. Plugins should not assume zlib-only batches or bypass Quark's `Compressor` abstraction. Snappy requires `ext-snappy`; unavailable or invalid modes fall back to zlib.

## Configuration migration

Start with the Quark 6 `resources/quark.yml` and transfer only settings you understand. Important additions include:

- `network.compression-algorithm`
- `network.async-compression`
- `network.async-compression-threshold`
- `network.combat-low-latency-feedback`
- `memory.garbage-collection.threshold`
- `memory.garbage-collection.period`
- `redstone.*`
- `tnt-limits.*`

Do not rename an old configuration file and assume equivalent behavior.

## Migration checklist

1. Back up worlds, player data, plugins, and configuration.
2. Update every plugin namespace and Composer dependency.
3. Review TNT, damage, redstone, armor, and compression assumptions.
4. Install the Quark PHP runtime and required native extensions.
5. Generate a fresh `quark.yml` and migrate settings selectively.
6. Test on copies of production worlds with representative player and plugin load.
7. Review logs for deprecated assumptions, missing classes, rejected TNT activation, and redstone limits before deployment.
