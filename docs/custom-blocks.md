# Native custom blocks

Custom blocks must be registered by a plugin with `load = "STARTUP"` in `plugin.toml`, inside `onEnable()`. Quark finalizes the block palette after STARTUP plugins enable and before any world loads, because chunks are decoded with it. Registering from a POSTWORLD plugin, or at any later point, throws a `LogicException`. Changing definitions requires a restart.

Every block gets an inventory item automatically. Don't register it again with `ItemRegistry`.

## Basic cube

```php
use quark\block\BlockToolType;
use quark\block\custom\BlockDefinition;
use quark\block\custom\BlockRegistry;
use quark\inventory\CreativeCategory;
use quark\item\ToolTier;

// Inside PluginBase::onEnable() of a STARTUP plugin
$blocks = BlockRegistry::getInstance();
$blocks->register(
	BlockDefinition::cube("suko:ruby_block", "Ruby Block", texture: "suko_ruby_block")
		->hardness(5.0)
		->blastResistance(30.0)
		->tool(BlockToolType::PICKAXE, ToolTier::IRON)
		->creative(CreativeCategory::CONSTRUCTION),
	$this
);

$block = $blocks->create("suko:ruby_block"); // independent instance, default state
$world->setBlock($pos, $block);
$item = $block->asItem();

$blocks->getIdentifier($world->getBlock($pos)); // "suko:ruby_block" in any state, null for other blocks
```

Definitions are immutable, so keep the result of each call. Identifiers use lowercase `namespace:path`; `minecraft` and `quark` are reserved and the first plugin to use a namespace owns it. A tier makes the tool required for drops. Leave out `creative()` to keep a block out of the creative inventory; it still works with `/give`, recipes and placement.

## Presets

| Preset | Server behavior |
| --- | --- |
| `cube` | Opaque full cube |
| `transparent` | Full cube that lets light through, like glass |
| `decorative` | Non-solid, no collision, cross geometry by default |
| `directional` | Cube facing away from the placer, state `<ns>:facing` |
| `slab`, `stairs`, `fence`, `wall`, `door`, `trapdoor`, `fenceGate` | Quark's own `Slab`, `Stair`, `Fence`, `Wall`, `Door`, `Trapdoor` and `FenceGate` |
| `plant` | Flower-like, needs dirt or mud below |
| `crop` | Farmland crop with growth 0 - 7, its item is the seed |

`cube`, `transparent`, `decorative` and `directional` accept `states()`, `lightLevel()`, `lightFilter()`, `friction()`, `flammable()`, `replaceable()`, `collision()` and `withFactory()`. Other presets use the behavior of their Quark class; subclass it with `fromFactory()` to change that.

## States

```php
use quark\block\custom\BlockState;

$blocks->register(
	BlockDefinition::cube("suko:lamp", "Lamp", "suko_lamp")
		->states(
			BlockState::bool("suko:lit"),
			BlockState::int("suko:charge", 0, 3),
			BlockState::enum("suko:tone", ["warm", "cold"])->persistsInItem()
		),
	$this
);

$lamp = $blocks->create("suko:lamp")->setState("suko:lit", true)->setState("suko:charge", 2);
$lamp->getState("suko:tone"); // "warm"
```

States must be namespaced and use typed values. Block-only states reset when the block is picked or dropped; `persistsInItem()` keeps a state on the item, like a color variant.

Quark stores a block's whole state in 11 bits, so the product of all state value counts must fit in 2048 combinations (for example 256 × 8). Larger definitions are rejected at registration. Every state is checked to round-trip through its saved form before anything is registered, and a failed registration leaves nothing behind.

## Visuals and permutations

```php
use quark\block\custom\BlockComponents;

$blocks->register(
	BlockDefinition::cube("suko:lamp", "Lamp")
		->states(BlockState::bool("suko:lit"))
		->lightLevel(0)
		->withComponents((new BlockComponents())
			->geometry(BlockComponents::FULL_BLOCK)
			->texture("suko_lamp_off")
			->permutation("query.block_state('suko:lit') == true", (new BlockComponents())->texture("suko_lamp_on"))
		),
	$this
);
```

`BlockComponents` covers what the client draws: `geometry()` (with bone visibility and culling), `texture()` / `material()` per face with render methods, `transformation()` inside permutations, `selectionBox()` and `mapColor()`. Permutation conditions may only reference the block's own states.

Everything the client must agree on is derived from the server block for every state: collision boxes (up to 16, within 0 - 1 horizontally and 0 - 1.5 vertically), selection box, light emission, light dampening, friction, flammability, replaceability, mining time, tool tags and explosion resistance. When these vary by state, Quark emits the needed permutations with conditions on only the states that matter. Directional blocks and families with a facing rotate the geometry, so author models facing north.

## Light-emitting blocks and custom behavior

```php
use quark\math\Vector3;
use quark\block\BlockIdentifier;
use quark\block\BlockTypeInfo;
use quark\block\custom\CustomBlock;
use quark\block\custom\CustomBlockSettings;
use quark\item\Item;
use quark\player\Player;

class Lamp extends CustomBlock{
	public function getLightLevel() : int{
		return $this->getState("suko:lit") === true ? 15 : 0;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->position->getWorld()->setBlock($this->position, $this->setState("suko:lit", $this->getState("suko:lit") !== true));
		return true;
	}
}

$blocks->register(
	BlockDefinition::cube("suko:lamp", "Lamp", "suko_lamp")
		->states(BlockState::bool("suko:lit"))
		->withFactory(static fn(BlockIdentifier $id, string $name, BlockTypeInfo $info, CustomBlockSettings $settings) => new Lamp($id, $name, $info, $settings)),
	$this
);
```

For a fixed light level, `cube(...)->lightLevel(12)` is enough. Light values reach the main-thread and worker light engines. Factories run once and must pass the supplied settings through.

Any Quark block class works with `fromFactory()`, as long as every described state is persisted by one of the supplied `quark\data\bedrock\block\convert\property` codecs with namespaced names:

```php
use quark\block\Stair;

$blocks->register(BlockDefinition::fromFactory("suko:ice_stairs", "Ice Stairs",
	static fn(BlockIdentifier $id, string $name, BlockTypeInfo $info) => new MyStair($id, $name, $info),
	$codecs // property codecs for facing, upside down and shape
)->withComponents($visuals), $this);
```

## Slabs, stairs and other families

```php
$model = (new BlockComponents())->geometry("geometry.suko_ruby_slab")->texture("suko_ruby_block");
$blocks->register(BlockDefinition::slab("suko:ruby_slab", "Ruby")->withComponents($model)->creative(), $this); // "Ruby Slab"
$blocks->register(BlockDefinition::stairs("suko:ruby_stairs", "Ruby Stairs")->withComponents($stairModel)->creative(), $this);
```

Families need a geometry from your resource pack. Their states live in the block's namespace:

| Family | States |
| --- | --- |
| slab | `slab_type` bottom / top / double |
| stairs | `facing`, `upside_down`, `stair_shape` straight / inner_left / inner_right / outer_left / outer_right |
| fence | `connection_north/south/west/east` |
| wall | `wall_post`, `wall_north/south/west/east` none / short / tall |
| door | `facing`, `upper`, `hinge_right`, `open` |
| trapdoor | `facing`, `top`, `open` |
| fenceGate | `facing`, `open`, `in_wall` |
| crop | `growth` 0 - 7 |

Use these in bone visibility or permutations to show the right part of the model, for example `query.block_state('suko:stair_shape') == 'outer_left'`. Placement, half-slab merging, connections, opening, redstone and drops are Quark's normal behavior.

## Persistent tile data

```php
use pocketmine\nbt\tag\CompoundTag;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;

class SafeTile extends Tile{
	public string $owner = "";

	public function readSaveData(CompoundTag $nbt) : void{ $this->owner = $nbt->getString("owner", ""); }

	protected function writeSaveData(CompoundTag $nbt) : void{ $nbt->setString("owner", $this->owner); }
}

TileFactory::getInstance()->register(SafeTile::class, ["suko:safe"]);
$blocks->register(BlockDefinition::cube("suko:safe", "Safe", "suko_safe")->tile(SafeTile::class), $this);
```

Only blocks with `tile()` get a tile. Quark creates it when the block is placed, saves and loads it with the chunk, and closes it when the block is replaced or the chunk unloads. Keep compact state in block states and use tiles only for data that doesn't fit, like inventories or names.

## Resource packs

Custom blocks need a resource pack on the server's resource stack:

- `textures/terrain_texture.json` mapping each texture name used in `texture()` / `material()` to an image.
- `models/blocks/*.geo.json` for any geometry other than `minecraft:geometry.full_block` and `minecraft:geometry.cross`.
- `texts/en_US.lang` is optional; the definition name is sent as the display name.

Block JSON definitions (`blocks.json` or behavior packs) aren't needed; the server sends every block's properties, states and components in StartGame. Experimental toggles are not required.

## Missing plugins

If a world contains custom blocks whose plugin isn't installed, or whose saved state the current definition no longer accepts (a removed value or renamed state), Quark loads them as inert, unbreakable `UnknownCustomBlock` placeholders that keep the original identifier and state. Saving writes them back unchanged, and restoring the plugin restores the blocks on the next load. Clients see them as `minecraft:info_update`. Placeholders let light through, so main-thread and worker lighting agree. Their items stay in inventories but can't be placed. Tile data is not kept while the tile class is missing, so a restored block comes back without it. Back up worlds before renaming identifiers or states.

## Limits

- 4096 custom blocks, 8192 client state combinations per block and 65536 in total.
- 2048 server states per block (11 state bits).
- Collision boxes fit the client's 16 × 24 × 16 pixel limit; selection boxes stay within the block.
- Network runtime IDs follow the client's ordering: block names sorted by FNV-1a 64 hash, and each block's states with the first property (sorted by name) changing fastest. This matches vanilla's palette and the behavior other servers rely on, but hasn't been checked against a real client in this release.
- `packed_bools` in material instances copies vanilla's encoding. Map colors and destructible-by-explosion components use the commonly sent format.
- Worker threads only know custom light values. Generators running on workers can write custom state IDs prepared on the main thread, but can't create custom block objects.
