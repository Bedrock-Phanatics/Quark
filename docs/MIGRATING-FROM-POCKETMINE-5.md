# Migrating from PocketMine-MP 5.47 to Quark 6

Quark 6 is based on PocketMine-MP 5.47.0 but introduces breaking changes to namespaces, dependencies, configuration, and APIs.

## Plugin Migration

### Namespaces

- Core namespace: `pocketmine\` → `quark\`
- Math and colour types come from the native ext-qmath extension: `pocketmine\math` → `quark\math` and `pocketmine\color` → `quark\color`, with the same class names. See [Math](#math).
- Other external libraries such as `pocketmine\nbt` remain unchanged.
- Update reflection strings, serialized class names, PHPStan configuration, tests, and scripts referencing the old namespace.

### Math

`pocketmine/math` and `pocketmine/color` are replaced by [ext-qmath](https://github.com/Bedrock-Phanatics/ext-qmath), which Quark requires at runtime. Results match pmmp/Math 1.0 and pocketmine/color 0.3 exactly. Besides the namespace:

- `Vector3::getX()`, `getY()`, `getZ()` and `Vector2::getX()`, `getY()` are gone, read `->x`, `->y`, `->z`.
- `RayTraceResult::getBoundingBox()`, `getHitFace()`, `getHitVector()` are gone, read `->bb`, `->hitFace`, `->hitVector`. The properties are readonly.
- `Vector3::sidesArray()` is gone. `sides()` and `sidesAroundAxis()` return arrays keyed by facing instead of generators.
- `Vector3::maxPlainDistance()`, `getIntermediateWith*Value()`, `AxisAlignedBB::isVectorIn*()` and `Matrix` are gone.
- `VoxelRayTrace` returns a lazy `VoxelRayIterator` instead of a generator.
- Serialized vectors from PocketMine do not unserialize into the new classes.
- `Block::calculateIntercept()` is final, block raycasts use the block's collision boxes.
- `quark\world\generator\noise\Simplex` is final and samples natively.
- Collision queries outside ±33,554,432 blocks horizontally are not supported. Entities there stay in place instead of moving.
- `World::getBlockCollisionBoxes()` and `getCollisionRegion()` cover at most 4096 chunk sections per query, about 256 × 256 × 256 blocks, and throw `quark\math\collision\CollisionDataException` beyond that.

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
- Event priorities were renamed and renumbered so lower values run first: `LOWEST` → `EARLIEST`, `LOW` → `EARLY`, `HIGH` → `LATE`, `HIGHEST` → `LATEST`. `NORMAL` and `MONITOR` keep their names. The old names no longer exist, so update both `@priority` tags and `EventPriority::` constants. Never persist the numeric values, they changed.

### Inventories

Inventories now only store items. Who is looking at one and where it was opened from lives in an `InventoryWindow`, which is created each time a player opens something and thrown away when it closes. See [inventories.md](inventories.md) for the full guide.

- `Player->setCurrentWindow()` takes an `InventoryWindow` and `getCurrentWindow()` returns one. Create a new window every time, windows can't be reused or shared between players.
- Open block menus with `$block->openToUnchecked($player)` on any `MenuAccessor` block instead of passing the tile's inventory.
- `InventoryOpenEvent`, `InventoryCloseEvent`, `InventoryTransactionEvent` actions and `SlotChangeAction` expose the window (`getWindow()` / `getInventoryWindow()`). `getInventory()` still returns the storage. `InventoryOpenEvent` and `InventoryCloseEvent` no longer take a `Player` argument.
- `InventoryTransaction->getInventories()` → `getInventoryWindows()`.
- Removed `PlayerInventory`, `PlayerOffHandInventory`, `PlayerCursorInventory`, `PlayerCraftingInventory`, `PlayerEnderInventory`, `DelegateInventory`, `TemporaryInventory`, `DispenserInventory` and every `block\inventory\*Inventory` class. Tiles use `SimpleInventory`, check the window type (`PlayerInventoryWindow::getType()`, `FurnaceInventoryWindow`, ...) or the block instead of the inventory class.
- Hotbar: `$human->getInventory()->getHeldItemIndex()` → `$human->getHotbar()->getSelectedIndex()`, `getItemInHand()`/`setItemInHand()` → `$human->getMainHandItem()`/`setMainHandItem()`, `getOffHandInventory()->getItem(0)` → `getOffHandItem()`.
- `ArmorInventory`, inventories and block windows no longer have `getHolder()`. `BlockInventoryWindow::getHolder()` returns the block position.
- `DoubleChestInventory` → `CombinedInventoryProxy`, created on demand by `Chest->getInventory()`. The chest tile's `getInventory()` returns only its own half, `getRealInventory()` is gone.
- `tile\Container` → `tile\ContainerTile`, `tile\ContainerTrait` → `tile\ContainerTileTrait`. `block\utils\Container` is the new block-level interface.
- `TransactionBuilder->getInventory()` → `getActionBuilder(InventoryWindow)`, `TransactionBuilderInventory` → `SlotChangeActionBuilder`.
- `Inventory->removeAllViewers()` → `removeAllWindows()`, and `onOpen()`/`onClose()` take a window. Use `Player->setCurrentWindow()` rather than calling them.
- `PlayerEnchantingOptionsRequestEvent->getInventory()` → `getWindow()`.
- Shulker boxes now reject other shulker boxes through slot validation. `addItem()` doesn't run slot validators.

### Tiles

Tiles no longer need a position to exist, so their data can be built, copied or moved without a world.

- `new MyTile()` takes no arguments. Blocks still create their tiles automatically. Internally `World->addTile($tile, $pos)` attaches a tile and `World->removeTile($tile)` detaches it without closing it.
- `Tile->getPosition()` throws while the tile isn't in a world. Check `Tile->isAttached()` first. `Tile->$position` is private.
- `Tile->saveNBT()` no longer writes `x`/`y`/`z`, and `TileFactory->createFromData($nbt)` doesn't take a world. Copy a tile with `TileFactory::getInstance()->createFromData($tile->saveNBT())`.

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

### Chunk Loading

- `ChunkLoader` is now a final class instead of an interface. Replace implementations with `new ChunkLoader()` instances, one per owner.

### Damage

- Expanded protected-damage and final-damage access in `EntityDamageEvent`.
- Some damage modifier constants have changed integer values. Use named constants instead of hardcoded values.

### Effects

- `EffectInstance` is immutable, so one instance can be applied to any number of entities. `setDuration()`, `decreaseDuration()`, `hasExpired()`, `setAmplifier()`, `setVisible()`, `setAmbient()`, `setColor()` and `resetColor()` have been removed. Construct a new instance, or use `withDuration()`.
- `EffectInstance::getDuration()` is the duration the effect was created with. Use `EffectManager::getRemainingDuration()` for the ticks left on an active effect.
- `Effect::canTick()` has been removed. Implement `getApplyInterval()` instead.
- `EntityEffectRemoveEvent::isExpired()` tells whether the effect ran out.
- `EntityEffectAddEvent::getEffect()` no longer allows in-place changes. Call `setEffect()` with a modified copy of the same effect type.

### Enchantments

- `Item::addEnchantment()` takes the enchantment and level directly: `addEnchantment(VanillaEnchantments::SHARPNESS(), 5)` instead of `addEnchantment(new EnchantmentInstance(...))`. The level defaults to 1.

### Entities

- `Entity::getNetworkTypeId()` is an instance method. Remove the `static` keyword from custom entities, so one class can report different network types.

### Player Data

Player data storage is asynchronous so a provider backed by SQL or a remote service never blocks the main thread.

- `PlayerDataProvider::hasData()`, `loadData()` and `saveData()` return a `Vow\Promise`. A failed load rejects with `PlayerDataLoadException` and a failed save with `PlayerDataSaveException`. Operations for the same player run one at a time in call order, so providers don't have to order them.
- `Server::getOfflinePlayer()`, `getOfflinePlayerData()`, `hasOfflinePlayerData()`, `saveOfflinePlayerData()` and `Player::save()` return promises too. Saves never reject, failures are logged.
- `OfflinePlayer` exposes `getInventory()`, `getArmorInventory()`, `getOffHandInventory()` and `getEnderInventory()` read from the saved data. To change them, use `Server::updateOfflinePlayer($name, fn(OfflinePlayer $player) => ...)`, which loads, updates and saves as one queued step so a joining player can't overwrite the change. It resolves with `false` without saving if the player is online or connecting, or has no saved data. Unlike a join, an item that can't be loaded makes the getters throw `SavedDataLoadingException` instead of turning into air, so the update rejects without saving.
- The server waits up to 30 seconds for pending saves on shutdown.

### Removed Mechanics

- Thorns and Frost Walker no longer function or appear through normal acquisition.
- Armor no longer receives unconditional per-tick callbacks.
- Turtle Helmets no longer grant Water Breathing.
- Plugins relying on these mechanics must implement their own behavior.

### Block Placement

- `Block::place()` no longer receives the clicked block. Use `$blockReplace->getSide(Facing::opposite($face))` for the block the placement is attached to.

### Plugin Resources

- `PluginBase::getResource()` has been removed because it returned a handle that had to be closed with `fclose()`. Use `file_get_contents($this->getResourcePath($name))` or `fopen()` on the same path instead.
- `ResourceProvider` and `DiskResourceProvider` have been removed, and the `Plugin` constructor no longer takes a resource provider.
- `getResourceFolder()` and `getResourcePath()` return the plugin's read-only `resources` folder and a file inside it.
- `PluginBase::saveDefaultConfig()` is no longer public. `getConfig()` and `reloadConfig()` already write the default config on first use, so remove the call.
- `saveResource()` returns `false` for directories and for names that resolve outside the resources or data folder.

### Compression

- Compression algorithm is configurable rather than fixed to zlib.
- Plugins should use Quark's `Compressor` abstraction.
- Snappy requires `ext-snappy`; invalid or unavailable compression modes fall back to zlib.

### Async Tasks

- `AsyncTask::publishProgress()` and `onProgressUpdate()` have been removed. Share progress from the task through a `ThreadSafeArray` and read it from a repeating scheduler task, or split the work into smaller tasks.

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
3. Review form, event, inventory, TNT, damage, redstone, armor, and compression compatibility.
4. Install the required PHP runtime and native extensions.
5. Migrate legacy configurations and review both TOML files.
6. Test plugins and production world copies under representative load.
7. Check logs for missing classes, compatibility issues, TNT rejections, and redstone limits before deployment.
