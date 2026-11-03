# Native custom items

Register items from your plugin's `onEnable()`. Both STARTUP and POSTWORLD plugins are supported. The registry closes after POSTWORLD enable, before packet caches and network listeners start. A full server restart is required to change the item palette. Disabling a plugin does not unregister its types while clients are connected.

## Items, weapons and food

```php
use quark\item\custom\ItemComponents;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\inventory\CreativeCategory;

// Inside PluginBase::onEnable()
$registry = ItemRegistry::getInstance();
$registry->register(
    ItemDefinition::item("suko:ruby", "Ruby")
        ->withComponents((new ItemComponents())->icon("suko:ruby")->rarity("rare"))
        ->withCreativeCategory(CreativeCategory::ITEMS),
    $this
);
$registry->register(
    ItemDefinition::sword("suko:ruby_sword", "Ruby Sword", damage: 9, durability: 1800)
        ->withComponents((new ItemComponents())->icon("suko:ruby_sword"))
        ->withCreativeCategory(CreativeCategory::EQUIPMENT),
    $this
);
$registry->register(ItemDefinition::food("suko:apple", "Ruby Apple", nutrition: 6, saturation: 7.2), $this);

$sword = $registry->create("suko:ruby_sword");
$rubies = $registry->create("suko:ruby", 16);
```

Damage is **total server melee damage**, including the player's base damage of one. Sword tier defaults to DIAMOND and supplies mining, enchantability, fuel and fireproof behavior. An optional `ToolTier` selects another native tier. `ItemDefinition::tool()` accepts `BlockToolType::AXE`, `PICKAXE`, `SHOVEL` or `HOE` and a `ToolTier`; these use the existing native tool classes.

Definitions and component methods return new values. Keep the returned value when chaining. Every `create()` returns a separate stack. Stack counts must be 1 through the item's maximum, which is 1 for weapons and armor. Names are lowercase `namespace:path`, at most 128 bytes. The first plugin registering a namespace owns it for that server process. `minecraft` and `quark` are reserved. Only fully qualified custom identifiers are installed in `/give`'s existing string parser.

## Armor set

```php
use quark\inventory\ArmorInventory;
use quark\inventory\CreativeCategory;
use quark\item\ArmorTypeInfo;
use quark\item\VanillaArmorMaterials;
use quark\item\custom\ItemComponents;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;

$registry = ItemRegistry::getInstance();
foreach([
    ["helmet", ArmorInventory::SLOT_HEAD, 3, 364],
    ["chestplate", ArmorInventory::SLOT_CHEST, 8, 529],
    ["leggings", ArmorInventory::SLOT_LEGS, 6, 496],
    ["boots", ArmorInventory::SLOT_FEET, 3, 430],
] as [$piece, $slot, $protection, $durability]){
    $registry->register(
        ItemDefinition::armor("suko:ruby_$piece", "Ruby " . ucfirst($piece), new ArmorTypeInfo(
            defensePoints: $protection,
            maxDurability: $durability,
            armorSlot: $slot,
            fireProof: true,
            material: VanillaArmorMaterials::DIAMOND()
        ))->withComponents((new ItemComponents())->icon("suko:ruby_$piece")->dyeable())
          ->withCreativeCategory(CreativeCategory::EQUIPMENT),
        $this
    );
}
```

Armor is native `Armor`. Slot validation, protection enchantments, Unbreaking, Mending, equip sounds, hand equipping, dispensers, breakage and equipment synchronization follow the existing engine behavior. Material supplies enchantability and equip sound. `fireProof` protects dropped items from fire, rather than granting the wearer fire resistance. `Armor::setCustomColor()` stores per-stack dye color; a matching resource pack must render it.

Quark uses its existing linear armor reduction. `ArmorTypeInfo::getToughness()` is **not used** in combat. Protection values are validated per piece (0–20); choose set totals deliberately, since the existing formula does not cap the sum. This system does not change that formula, reach, knockback, invulnerability, critical hits, attack events or latency-sensitive output.

## Custom behavior

```php
use quark\entity\Entity;
use quark\item\custom\CustomSword;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\item\ItemIdentifier;

class RubySword extends CustomSword{
    public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
        $victim->setOnFire(2);
        return parent::onAttackEntity($victim, $returnedItems);
    }
}

ItemRegistry::getInstance()->register(
    ItemDefinition::fromFactory("suko:special_sword", "Special Sword",
        static fn(ItemIdentifier $id) => new RubySword($id, "Special Sword", 9, 1800)
    ),
    $this
);
```

Factories accept the assigned `ItemIdentifier`, run once and are discarded by the registry. Return a clean count-one Item, Sword, Armor, Tool, Food or appropriate subclass using that identifier and the definition's display name. Do not allocate another type ID or return a vanilla prototype. Do not put names, lore, enchantments or player-specific NBT in the registration prototype. Apply stack data to instances. Custom subclass mutable object fields must implement correct `__clone()` behavior and call the parent clone. Configuration should be readonly; do not retain players, worlds or sessions in prototypes.

Subclass state must be stored with `serializeCompoundTag()` / `deserializeCompoundTag()` (calling their parents), not additional runtime state bits. Normal lifecycle overrides remain available. The registry does not add callbacks to the combat path.

## Components

`ItemComponents` groups typed, validated component methods without arbitrary NBT or Molang injection:

| Configuration | Source / constraints |
| --- | --- |
| Icon, display name, rarity, glint, tags | `icon()`, definition name, `rarity()`, `glint()`, `tags()` |
| Maximum stack, damage, durability, enchantability | Derived from server prototype; no conflicting overrides |
| Wearable slot, protection, fire resistance | Derived from native Armor / Item methods |
| Food and saturation | Derived from `FoodSourceItem`; saturation is server restoration points |
| Cooldown, fuel | Derived from `getCooldownTicks()`, `getCooldownTag()`, `getFuelTime()` |
| Use animation and movement | `useAnimation(UseAnimation::...)`, `useModifiers()`; modifiers require `Releasable` |
| Dye color | `dyeable()` requires Armor |
| Repair ingredient hints | `repairable(["minecraft:diamond"])` requires Durable |
| Mining hints | `digger("minecraft:stone", ...)` requires Tool; speed derives from the tool |
| Projectile, throwing | `projectile("minecraft:snowball")`, `throwable()` require a ProjectileItem subclass which creates that entity on the server |
| Swing animation length | `swingDuration()` is visual; it does not change server attack cooldowns |

Projectile entity identifiers must match the entity created by the subclass. Quark cannot introspect an entity factory without spawning it. These components configure presentation and prediction; the server subclass remains responsible for actual behavior. Client food duration does not create a new server consumption timer. Repair ingredient hints do not add an anvil transaction implementation: Quark currently provides an anvil inventory but no native material-repair handler. Existing supported enchanting, crafting, furnace fuel, durable repair and plugin workstation paths operate on the same Item classes and serializers.

Protocol target: Quark's `ProtocolInfo` (2193, Bedrock 1.26.50). The wire snapshot uses the existing component-based item version 1 and `components.item_properties` NBT representation, rather than inserting pack JSON as wire NBT. Components are prepared and validated at startup. Adding a new component means adding a typed validating method to `ItemComponents` with protocol tests. There is deliberately no raw component injection API. Kinetic/piercing combat, scripting components and storage components are not advertised as supported gameplay features; adding them requires corresponding native server behavior and separate tests.

Limits are 4096 custom types, 64 KiB component data per type, 4 MiB total custom component data and 8 MiB for the encoded registry packet. Durability is 1–32767, stack size 1–64, attack damage 1–32767. Rejected definitions leave no registered type, parser or serializer mapping. A failed factory can consume an internal ID; IDs are never reused.

## Resource packs

Use Quark's normal zipped `.mcpack` / `.zip` resource stack in `resource-packs/resource_packs.toml`. Use a resources module in `manifest.json` with an appropriate `min_engine_version`. No behavior pack, script module or experiment toggle is required for these native definitions. Quark sends these as resource packs (`isAddonPack: false`, `hasScripts: false`). The server warns when custom items are registered with no resource packs. It does not inspect encrypted textures or prove that geometry renders correctly.

For an icon, include:

```text
manifest.json
textures/item_texture.json
textures/items/ruby_sword.png
```

```json
{
  "resource_pack_name": "suko",
  "texture_name": "atlas.items",
  "texture_data": {
    "suko:ruby_sword": {"textures": "textures/items/ruby_sword"}
  }
}
```

The atlas key must match `icon()` (the full item identifier by default). Server item registration supplies the client item definition; a duplicate behavior-pack item definition is unnecessary.

For equipped armor, also include `attachables/ruby_helmet.attachable.json`, its referenced geometry under `models/entity/`, the texture under `textures/models/armor/`, and referenced render controllers under `render_controllers/` if not using built-in ones. The attachable's `minecraft:attachable.description.identifier` must exactly match the registered item, e.g. `suko:ruby_helmet`. Supply a separate matching attachable per armor identifier, with head/chest/legs/feet bone bindings and dye material support where used. Use the vanilla armor attachable layout appropriate to the target client as the starting point. Server protection and equipped appearance are independent; real-client rendering still needs testing on the supported client.

## Equipment callbacks and persistence

```php
use quark\inventory\ArmorInventory;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;

$listener = CallbackInventoryListener::onAnyChange(static function(Inventory $inventory) : void{
    if($inventory instanceof ArmorInventory){
        // Recompute the holder's plugin-owned set bonus on this change.
        $holder = $inventory->getHolder();
    }
});
$player->getArmorInventory()->getListeners()->add($listener);
// Remove this listener on plugin disable; keep it if it belongs to the inventory's lifetime.
$player->getArmorInventory()->getListeners()->remove($listener);
```

No recurring inventory scan or scheduled task is required. Existing inventory listeners see slot changes, including durability updates, so plugins should compare only their relevant set membership before applying expensive effects.

```php
use quark\item\Item;

$tag = $sword->nbtSerialize();
$restored = Item::nbtDeserialize($tag);
```

World and player persistence use the same stable string identifier, together with named NBT for durability, enchantments, custom names, lore, color and plugin data. Neither internal IDs nor network runtime IDs are persistent identity. Runtime IDs are assigned by sorted identifier at finalization; adding other items may change them safely on restart.

When a defining plugin is absent, a non-vanilla item becomes an inert `UnknownCustomItem`, displayed through the existing unknown-item network fallback. Its opaque original NBT, identifier, metadata and count survive saving. Its enchantments and other abilities are inactive, and distinct missing identifiers cannot stack together. Restore the plugin and reload the saved data to recover the real item. Treat placeholders as opaque: changing their ordinary Item properties does not edit the preserved original data. Malformed identifiers and invalid registered custom stack counts/durability still produce explicit loading/conversion errors. Back up data before deliberately changing durability limits or renaming identifiers.

## Design references

Reviewed [Customies](https://github.com/axolotl-plugin-orphanage/Customies) and [Nexly](https://github.com/Zwuiix-cmd/Nexly). Prototype cloning and deriving components from native item behavior are useful patterns. This implementation replaces their private-field reflection, registration-order runtime IDs and mutation-prone builders with native APIs, separate identities, immutable value builders and one finalized packet. It adds namespace ownership, bounds checks and missing-plugin persistence. It does not port either implementation or introduce packet listeners, another event dispatcher or worker-shared Item objects.

## Verification and measurements

Run with Quark's packaged PHP 8.4 runtime and Composer development dependencies:

```sh
php -d zend.assertions=1 vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-warning tests/phpunit
php vendor/bin/phpstan analyze --no-progress --memory-limit=3G
php -d zend.assertions=-1 tools/benchmark-custom-items.php
```

`CustomItemTest` covers registration failures, factories, namespace ownership, independent stacks, immutable components, stable-name persistence across registration-order changes, unknown items, malformed counts/NBT, components and protocol conversion. `CustomEquipmentTest` executes the real Player/Living attack methods with isolated world/socket dependencies, covering cancellation, critical/enchantment modifiers, cooldowns, equipment changes during events, vanilla/custom/mixed armor, breakage, slot validation, hand/dispenser equips and forged creative creation. TesterPlugin also exercises STARTUP and POSTWORLD registration, freeze, creative entries, persistence and network round trips in the packaged server.

Example measurements on 2026-11-03, PHP 8.4.27 ZTS, Linux under WSL, assertions disabled. Median of seven rounds after warm-up; 5000 operations per round (500 packet rebuilds, 2000 attacks):

| Operation | Vanilla, microseconds | Custom, microseconds |
| --- | ---: | ---: |
| Create stack | 0.216 | 0.283 |
| Serialize stack | 1.154 | 1.158 |
| Convert to network stack | 2.347 | 2.322 |
| Inventory set/get | 1.010 | 1.048 |
| Attack including equipment reset | 64.456 | 56.588 |

Cached registry access measured 0.045 microseconds versus 930.556 microseconds for the former per-session rebuild/encode algorithm on the same dictionary. Cached creative preparation measured 0.116 microseconds. Process peak was 120 MiB, including PHPUnit mocks, vanilla dictionaries and bootstrap data; this is not a per-item allocation measurement. Attack timings include mock-world/event overhead and vary between runs (another sample measured 62.324/65.205 microseconds), so they do not establish a combat speedup. The attack implementation itself is unchanged.

Real-client armor geometry, icon rendering, prediction and multiplayer latency under load still require testing on the target Bedrock client. Protocol encoding and an isolated attack harness cannot prove those behaviors.

Changed files pass PHPStan. The full repository scan still reports two pre-existing references to the removed `Internal\Toml\Toml` parser in `build/benchmark-config-load.php` (lines 28 and 29). That unrelated benchmark is unchanged.
