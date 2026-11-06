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

### Forms

Quark provides built-in forms through `quark\form\type` and reusable controls through `quark\form\element`. Send them with `$player->sendForm($form)`. When replacing an external form library, update its imports and adapt builder-style calls and callbacks to these constructors.

| Form | Submit callback |
|---|---|
| `MenuForm` | `function(Player $player, int $selectedOption) : void` |
| `ModalForm` | `function(Player $player, bool $choice) : void` |
| `CustomForm` | `function(Player $player, CustomFormResponse $response) : void` |

- `MenuForm` takes `MenuOption` objects and uses one submit callback for all buttons. Selection indexes start at zero in the supplied option order, regardless of array keys.
- `ModalForm` returns `true` for its first button and `false` for its second. Cancellation is separate from either choice.
- All three forms accept an optional `onClose` closure with signature `function(Player $player) : void` for client cancellation. Cancellation does not call `onSubmit`. For `ModalForm`, `onClose` follows the optional button-text arguments and can be supplied by name.
- Custom controls take a unique name as their first constructor argument. Read submitted values by name through `CustomFormResponse::getString()`, `getBool()`, `getInt()` and `getFloat()`, or use `getAll()` for the complete map. Dropdowns and step sliders return zero-based option indexes. Use `getFloat()` for numeric sliders, which may submit either integers or floats.
- Missing response names and incompatible typed getters throw `InvalidArgumentException`. `getValue()` preserves the original value, including `null` for labels.
- Custom responses must contain one value per element in display order, including `null` for each label. Invalid types, indexes, counts and out-of-range slider values are rejected before the submit callback runs. Slider bounds, defaults and steps must be finite, and steps must be positive.
- A pending response is removed before its callback runs. Duplicate responses are ignored. `Player::closeAllForms()` clears pending forms without invoking their callbacks, so later responses cannot submit them. Disposal also releases pending forms.
- `ServerSettingsForm` only serializes custom-form data with an optional icon. The core does not register settings-menu forms. Sending one with `sendForm()` displays a regular custom form without a settings-menu entry or icon.

For example, replace positional custom-form response access with named controls:

```php
use quark\form\element\Input;
use quark\form\element\Slider;
use quark\form\element\Toggle;
use quark\form\type\CustomForm;
use quark\form\type\CustomFormResponse;
use quark\player\Player;

$form = new CustomForm(
    "Preferences",
    [
        new Input("nickname", "Nickname"),
        new Toggle("notifications", "Notifications", true),
        new Slider("volume", "Volume", 0, 100, 1, 50)
    ],
    static function(Player $player, CustomFormResponse $response) : void{
        $nickname = $response->getString("nickname");
        $notifications = $response->getBool("notifications") ? "on" : "off";
        $volume = $response->getFloat("volume");
        $player->sendMessage("Nickname: $nickname, notifications: $notifications, volume: $volume");
    },
    onClose: static function(Player $player) : void{
        $player->sendMessage("Preferences unchanged");
    }
);

$player->sendForm($form);
```

### Events

- `PlayerExhaustEvent` → `EntityExhaustEvent` (Replace `getPlayer()` with `getEntity()`, returning the affected `Human`).

### Enums

- `LegacyEnumShimTrait` has been removed from all migrated native enums (`DyeColor`, `WoodType`, `GameMode`, `ToolTier`, `PotionType`, etc.).
- `Enum::CASE()` → `Enum::CASE`, `Enum::getAll()` → `Enum::cases()`, `$enum->name()` → `$enum->name`, `$enum->equals($other)` → `$enum === $other`, `$enum->id()` → `spl_object_id($enum)`.

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
3. Review form, event, TNT, damage, redstone, armor, and compression compatibility.
4. Install the required PHP runtime and native extensions.
5. Migrate legacy configurations and review both TOML files.
6. Test plugins and production world copies under representative load.
7. Check logs for missing classes, compatibility issues, TNT rejections, and redstone limits before deployment.
