# Native custom items

Register items in your plugin's `onEnable()`. STARTUP and POSTWORLD plugins are supported. Registration closes after POSTWORLD enable; changing definitions requires a server restart.

Placeable blocks get their items from the block registry instead; see [custom blocks](custom-blocks.md).

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

`damage` is total server melee damage, including the player's base damage of one. Sword tier defaults to `ToolTier::DIAMOND` and supplies mining and enchantability. `ItemDefinition::tool()` accepts `BlockToolType::AXE`, `PICKAXE`, `SHOVEL` or `HOE` and a `ToolTier`.

Builders return new values, so keep the result when chaining. `create()` returns an independent stack with a validated count. Identifiers use lowercase `namespace:path`; `minecraft` and `quark` are reserved. The first plugin registering a namespace owns it. Registered identifiers also work with `/give`.

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

Armor uses Quark's normal protection, enchantments, durability, equipping and synchronization. Material supplies enchantability and equip sound. `fireProof` protects dropped items from fire. Use `Armor::setCustomColor()` for per-stack dye color, with a resource pack that supports it.

Quark's armor reduction is linear and does not use toughness. Protection is 0–20 per piece; choose set totals carefully because the existing formula does not cap their sum.

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

Factories run once. Return a clean, count-one item using the supplied identifier and display name. Apply custom names, lore, enchantments and player data to created stacks, not the prototype. Keep configuration readonly and avoid retaining players, worlds or sessions. Clone mutable subclass fields in `__clone()`, calling the parent.

Persist subclass state with `serializeCompoundTag()` / `deserializeCompoundTag()`, calling their parents. Additional runtime state bits are not supported.

## Components

Server properties are derived automatically. Use `ItemComponents` for client configuration:

| Configuration | Source / constraints |
| --- | --- |
| Icon, display name, rarity, glint, tags | `icon()`, definition name, `rarity()`, `glint()`, `tags()` |
| Maximum stack, damage, durability, enchantability | Derived from server prototype; no conflicting overrides |
| Armor slot, protection, fireproof items | Derived from native Armor / Item methods |
| Food and saturation | Derived from `FoodSourceItem`; saturation is server restoration points |
| Cooldown, fuel | Derived from `getCooldownTicks()`, `getCooldownTag()`, `getFuelTime()` |
| Use animation and movement | `useAnimation(UseAnimation::...)`, `useModifiers()` require `Releasable` |
| Dye color | `dyeable()` requires Armor |
| Repair ingredient hints | `repairable(["minecraft:diamond"])` requires Durable |
| Mining hints | `digger("minecraft:stone", ...)` requires Tool; speed derives from the tool |
| Projectile, throwing | `projectile("minecraft:snowball")`, `throwable()` require a ProjectileItem subclass which creates that entity on the server |
| Swing animation length | `swingDuration()` is visual; it does not change server attack cooldowns |

Projectile identifiers must match the entity created by the server subclass. Use duration and swing animation do not change server consumption or attack timing. Repair hints do not implement material repairs in anvils; that requires a plugin handler. Kinetic/piercing combat, scripting and storage components are not supported.

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
// Remove on plugin disable if the listener outlives the plugin.
$player->getArmorInventory()->getListeners()->remove($listener);
```

Listeners also receive durability changes. Compare set membership before recomputing expensive bonuses.

```php
use quark\item\Item;

$tag = $sword->nbtSerialize();
$restored = Item::nbtDeserialize($tag);
```

World and player saves preserve stable identifiers and stack NBT, including durability, enchantments, names, lore, color and plugin data. Never persist numeric type or runtime IDs.

If the defining plugin is missing, items load as inert `UnknownCustomItem` placeholders that preserve their original data and saved counts. Placeholders cannot merge into larger stacks. Restore the plugin and reload the saved data to recover them. Treat placeholders as opaque; ordinary Item setters do not edit the preserved data. Back up saves before renaming identifiers or reducing durability limits.
