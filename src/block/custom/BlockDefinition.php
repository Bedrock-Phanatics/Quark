<?php

declare(strict_types=1);

namespace quark\block\custom;

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use quark\block\Block;
use quark\block\BlockBreakInfo;
use quark\block\BlockIdentifier;
use quark\block\BlockToolType;
use quark\block\BlockTypeInfo;
use quark\block\Door;
use quark\block\Fence;
use quark\block\FenceGate;
use quark\block\Slab;
use quark\block\Stair;
use quark\block\tile\Tile;
use quark\block\Trapdoor;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\SlabType;
use quark\block\utils\StairShape;
use quark\block\utils\WoodType;
use quark\block\Wall;
use quark\data\bedrock\block\convert\property\BoolProperty;
use quark\data\bedrock\block\convert\property\EnumFromRawStateMap;
use quark\data\bedrock\block\convert\property\IntFromRawStateMap;
use quark\data\bedrock\block\convert\property\IntProperty;
use quark\data\bedrock\block\convert\property\Property;
use quark\data\bedrock\block\convert\property\ValueFromStringProperty;
use quark\data\bedrock\block\convert\property\WallConnectionTypeShim;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeGroup;
use quark\item\custom\ItemDefinition;
use quark\item\Item;
use quark\item\ToolTier;
use quark\utils\Utils;
use function array_values;
use function explode;
use function strlen;

/**
 * @phpstan-type Settings array{states?: list<BlockState>, transparent?: bool, solid?: bool, collisionBoxes?: list<AxisAlignedBB>, lightLevel?: int, lightFilter?: int, frictionFactor?: float, flameEncouragement?: int, flammability?: int, replaceable?: bool, facingState?: string}
 * @phpstan-type CustomFactory \Closure(BlockIdentifier, string, BlockTypeInfo, CustomBlockSettings) : CustomBlock
 */
final class BlockDefinition{
	private BlockComponents $components;
	private ?CreativeCategory $category = null;
	private ?CreativeGroup $group = null;
	private float $hardness = 1.0;
	private ?float $blastResistance = null;
	private int $toolType = BlockToolType::NONE;
	private ?ToolTier $toolTier = null;
	/** @var class-string<Tile>|null */
	private ?string $tileClass = null;
	/** @phpstan-var Settings|null CustomBlockSettings arguments, null for families */
	private ?array $settings;
	/** @phpstan-var CustomFactory|null */
	private ?\Closure $customFactory = null;

	/**
	 * @param (\Closure(BlockIdentifier, string, BlockTypeInfo) : Block)|null $factory
	 * @param list<Property<*>> $properties codecs that persist the block's state
	 * @phpstan-param Settings|null $settings
	 */
	private function __construct(
		private readonly string $identifier,
		private readonly string $name,
		private readonly ?\Closure $factory,
		private array $properties,
		?array $settings,
		private readonly ?string $facingProperty = null,
		private readonly bool $needsGeometry = false
	){
		ItemDefinition::validateIdentifier($identifier);
		Utils::checkUTF8($name);
		if($name === "" || strlen($name) > 256){
			throw new \InvalidArgumentException("Block display name must contain 1 - 256 UTF-8 bytes");
		}
		$this->settings = $settings;
		$this->components = new BlockComponents();
	}

	/** @phpstan-param Settings $settings */
	private static function custom(string $identifier, string $name, array $settings,?string $texture = null, string $renderMethod = "opaque", string $geometry = BlockComponents::FULL_BLOCK) : self{
		$result = new self($identifier, $name, null, [], $settings, $settings["facingState"] ?? null);
		$result->components->geometry($geometry);
		if($texture !== null){
			$result->components->texture($texture, $renderMethod);
		}
		return $result;
	}

	/** An opaque full cube. */
	public static function cube(string $identifier, string $name, ?string $texture = null) : self{
		return self::custom($identifier, $name, [], $texture);
	}

	/** A full cube that light passes through, like glass. */
	public static function transparent(string $identifier, string $name, ?string $texture = null) : self{
		return self::custom($identifier, $name, ["transparent" => true], $texture, "blend");
	}

	/** A non-solid block without collision, rendered as a cross unless another geometry is set. */
	public static function decorative(string $identifier, string $name, ?string $texture = null) : self{
		return self::custom($identifier, $name, ["transparent" => true, "solid" => false, "collisionBoxes" => []], $texture, "alpha_test_single_sided", BlockComponents::CROSS);
	}

	/** A cube that faces away from the player who placed it. Author its model facing north. */
	public static function directional(string $identifier, string $name, ?string $texture = null) : self{
		$facing = self::stateName($identifier, "facing");
		return self::custom($identifier, $name, [
			"states" => [BlockState::enum($facing, ["north", "south", "west", "east"])],
			"facingState" => $facing
		], $texture);
	}

	/** Quark appends " Slab" to slab names, so pass "Ruby" for "Ruby Slab". */
	public static function slab(string $identifier, string $name) : self{
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Slab($id, $n, $info), [
			new ValueFromStringProperty(self::stateName($identifier, "slab_type"), EnumFromRawStateMap::string(SlabType::class, fn(SlabType $t) => match($t){
				SlabType::BOTTOM => "bottom",
				SlabType::TOP => "top",
				SlabType::DOUBLE => "double"
			}), fn(Slab $b) => $b->getSlabType(), fn(Slab $b, SlabType $v) => $b->setSlabType($v))
		], false);
	}

	public static function stairs(string $identifier, string $name) : self{
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Stair($id, $n, $info), [
			self::facingProperty($identifier),
			self::boolProperty($identifier, "upside_down", fn(Stair $b) => $b->isUpsideDown(), fn(Stair $b, bool $v) => $b->setUpsideDown($v)),
			new ValueFromStringProperty(self::stateName($identifier, "stair_shape"), EnumFromRawStateMap::string(StairShape::class, fn(StairShape $s) => match($s){
				StairShape::STRAIGHT => "straight",
				StairShape::INNER_LEFT => "inner_left",
				StairShape::INNER_RIGHT => "inner_right",
				StairShape::OUTER_LEFT => "outer_left",
				StairShape::OUTER_RIGHT => "outer_right"
			}), fn(Stair $b) => $b->getShape(), fn(Stair $b, StairShape $v) => $b->setShape($v))
		]);
	}

	public static function fence(string $identifier, string $name) : self{
		$properties = [];
		foreach([Facing::NORTH => "north", Facing::SOUTH => "south", Facing::WEST => "west", Facing::EAST => "east"] as $facing => $side){
			$properties[] = self::boolProperty($identifier, "connection_$side", fn(Fence $b) => $b->isConnectedAt($facing), fn(Fence $b, bool $v) => $b->setConnectedAt($facing, $v));
		}
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Fence($id, $n, $info), $properties, false);
	}

	public static function wall(string $identifier, string $name) : self{
		$properties = [self::boolProperty($identifier, "wall_post", fn(Wall $b) => $b->isPost(), fn(Wall $b, bool $v) => $b->setPost($v))];
		foreach([Facing::NORTH => "north", Facing::SOUTH => "south", Facing::WEST => "west", Facing::EAST => "east"] as $facing => $side){
			$properties[] = new ValueFromStringProperty(
				self::stateName($identifier, "wall_$side"),
				EnumFromRawStateMap::string(WallConnectionTypeShim::class, fn(WallConnectionTypeShim $c) => $c->value),
				fn(Wall $b) => WallConnectionTypeShim::serialize($b->getConnection($facing)),
				fn(Wall $b, WallConnectionTypeShim $v) => $b->setConnection($facing, $v->deserialize())
			);
		}
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Wall($id, $n, $info), $properties, false);
	}

	public static function door(string $identifier, string $name) : self{
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Door($id, $n, $info), [
			self::facingProperty($identifier),
			self::boolProperty($identifier, "upper", fn(Door $b) => $b->isTop(), fn(Door $b, bool $v) => $b->setTop($v)),
			self::boolProperty($identifier, "hinge_right", fn(Door $b) => $b->isHingeRight(), fn(Door $b, bool $v) => $b->setHingeRight($v)),
			self::boolProperty($identifier, "open", fn(Door $b) => $b->isOpen(), fn(Door $b, bool $v) => $b->setOpen($v))
		]);
	}

	public static function trapdoor(string $identifier, string $name) : self{
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Trapdoor($id, $n, $info), [
			self::facingProperty($identifier),
			self::boolProperty($identifier, "top", fn(Trapdoor $b) => $b->isTop(), fn(Trapdoor $b, bool $v) => $b->setTop($v)),
			self::boolProperty($identifier, "open", fn(Trapdoor $b) => $b->isOpen(), fn(Trapdoor $b, bool $v) => $b->setOpen($v))
		]);
	}

	public static function fenceGate(string $identifier, string $name, WoodType $wood = WoodType::OAK) : self{
		return self::family($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new FenceGate($id, $n, $info, $wood), [
			self::facingProperty($identifier),
			self::boolProperty($identifier, "open", fn(FenceGate $b) => $b->isOpen(), fn(FenceGate $b, bool $v) => $b->setOpen($v)),
			self::boolProperty($identifier, "in_wall", fn(FenceGate $b) => $b->isInWall(), fn(FenceGate $b, bool $v) => $b->setInWall($v))
		]);
	}

	/** A flower-like plant that needs dirt below. */
	public static function plant(string $identifier, string $name, ?string $texture = null) : self{
		$result = new self($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new CustomPlant($id, $n, $info), [], null);
		$result->hardness = 0.0;
		$result->components->geometry(BlockComponents::CROSS);
		if($texture !== null){
			$result->components->texture($texture, "alpha_test_single_sided");
		}
		return $result;
	}

	/** A farmland crop with growth 0 - 7. Its item is the seed, and mature crops also drop the produce. */
	public static function crop(string $identifier, string $name, ?Item $produce = null) : self{
		$produce = $produce === null ? null : (clone $produce)->setCount(1);
		$result = new self($identifier, $name, static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new CustomCrop($id, $n, $info, $produce), [
			new IntProperty(self::stateName($identifier, "growth"), 0, CustomCrop::MAX_AGE, fn(CustomCrop $b) => $b->getAge(), fn(CustomCrop $b, int $v) => $b->setAge($v))
		], null, needsGeometry: true);
		$result->hardness = 0.0;
		return $result;
	}

	/**
	 * Uses your own Block subclass. The factory runs once and must return a fresh block with the supplied arguments.
	 * Every state described by the block must be persisted by exactly one of the codecs, using namespaced names.
	 *
	 * @param \Closure(BlockIdentifier, string, BlockTypeInfo) : Block $factory
	 * @param list<Property<*>> $properties
	 */
	public static function fromFactory(string $identifier, string $name, \Closure $factory, array $properties = []) : self{
		return new self($identifier, $name, $factory, $properties, null);
	}

	/**
	 * @phpstan-template T of object
	 * @phpstan-param \Closure(T) : bool $getter
	 * @phpstan-param \Closure(T, bool) : mixed $setter
	 * @phpstan-return BoolProperty<T>
	 */
	private static function boolProperty(string $identifier, string $name, \Closure $getter, \Closure $setter) : BoolProperty{
		return new BoolProperty(self::stateName($identifier, $name), $getter, $setter);
	}

	/** @phpstan-return ValueFromStringProperty<HorizontalFacing, int> */
	private static function facingProperty(string $identifier) : ValueFromStringProperty{
		return new ValueFromStringProperty(self::stateName($identifier, "facing"), IntFromRawStateMap::string([
			Facing::NORTH => "north",
			Facing::SOUTH => "south",
			Facing::WEST => "west",
			Facing::EAST => "east"
		]), fn(HorizontalFacing $b) => $b->getFacing(), fn(HorizontalFacing $b, int $v) => $b->setFacing($v));
	}

	/** Family states live in the block's own namespace, e.g. "suko:facing". */
	public static function stateName(string $identifier, string $name) : string{
		return explode(":", $identifier, 2)[0] . ":" . $name;
	}

	/**
	 * @param \Closure(BlockIdentifier, string, BlockTypeInfo) : Block $factory
	 * @param list<Property<*>> $properties
	 */
	private static function family(string $identifier, string $name, \Closure $factory, array $properties, bool $rotates = true) : self{
		return new self($identifier, $name, $factory, $properties, null, $rotates ? self::stateName($identifier, "facing") : null, true);
	}

	/**
	 * @phpstan-param Settings $changes
	 */
	private function withSettings(string $what, array $changes) : self{
		if($this->settings === null){
			throw new \LogicException("Only cube, transparent, decorative and directional blocks accept $what. Subclass a family block instead");
		}
		$result = clone $this;
		$result->settings = $changes + $this->settings;
		return $result;
	}

	/** Adds states to a CustomBlock preset. */
	public function states(BlockState ...$states) : self{
		return $this->withSettings("states", ["states" => [...($this->settings["states"] ?? []), ...array_values($states)]]);
	}

	public function lightLevel(int $level) : self{ return $this->withSettings("light levels", ["lightLevel" => $level]); }

	public function lightFilter(int $filter) : self{ return $this->withSettings("light filters", ["lightFilter" => $filter]); }

	/** Quark friction factor, 0.6 for most blocks and 0.98 for ice. */
	public function friction(float $factor) : self{ return $this->withSettings("friction", ["frictionFactor" => $factor]); }

	public function flammable(int $encouragement = 5, int $flammability = 20) : self{
		return $this->withSettings("flammability", ["flameEncouragement" => $encouragement, "flammability" => $flammability]);
	}

	public function replaceable() : self{ return $this->withSettings("replaceable", ["replaceable" => true]); }

	/** Collision boxes in block-local coordinates, within 0 - 1 horizontally and 0 - 1.5 vertically. */
	public function collision(AxisAlignedBB ...$boxes) : self{
		$copies = [];
		foreach($boxes as $box){
			$copies[] = clone $box;
		}
		return $this->withSettings("collision boxes", ["collisionBoxes" => $copies]);
	}

	/**
	 * Builds a CustomBlock subclass instead, for custom behavior. The factory runs once.
	 * @phpstan-param CustomFactory $factory
	 */
	public function withFactory(\Closure $factory) : self{
		$result = $this->withSettings("withFactory()", []);
		$result->customFactory = $factory;
		return $result;
	}

	public function hardness(float $hardness) : self{
		if($hardness < 0 || $hardness > 1_000_000){ throw new \InvalidArgumentException("Invalid hardness"); }
		$result = clone $this;
		$result->hardness = $hardness;
		return $result;
	}

	public function blastResistance(float $resistance) : self{
		if($resistance < 0 || $resistance > 18_000_000){ throw new \InvalidArgumentException("Invalid blast resistance"); }
		$result = clone $this;
		$result->blastResistance = $resistance;
		return $result;
	}

	/** @param int $toolType BlockToolType flags. A tier makes that tool required for drops. */
	public function tool(int $toolType, ?ToolTier $tier = null) : self{
		if($toolType < 0 || $toolType > (BlockToolType::SWORD | BlockToolType::SHOVEL | BlockToolType::PICKAXE | BlockToolType::AXE | BlockToolType::SHEARS | BlockToolType::HOE)){
			throw new \InvalidArgumentException("Invalid tool type");
		}
		$result = clone $this;
		$result->toolType = $toolType;
		$result->toolTier = $tier;
		return $result;
	}

	/** @param class-string<Tile> $class registered with TileFactory */
	public function tile(string $class) : self{
		Utils::testValidInstance($class, Tile::class);
		$result = clone $this;
		$result->tileClass = $class;
		return $result;
	}

	public function withComponents(BlockComponents $components) : self{
		$result = clone $this;
		$result->components = clone $components;
		return $result;
	}

	public function texture(string $texture, string $renderMethod = "opaque") : self{
		$result = clone $this;
		$result->components = (clone $this->components)->texture($texture, $renderMethod);
		return $result;
	}

	public function creative(CreativeCategory $category = CreativeCategory::CONSTRUCTION, ?CreativeGroup $group = null) : self{
		$result = clone $this;
		$result->category = $category;
		$result->group = $group;
		return $result;
	}

	public function getIdentifier() : string{ return $this->identifier; }

	public function getName() : string{ return $this->name; }

	public function getComponents() : BlockComponents{ return clone $this->components; }

	public function getCreativeCategory() : ?CreativeCategory{ return $this->category; }

	public function getCreativeGroup() : ?CreativeGroup{ return $this->group; }

	public function getToolType() : int{ return $this->toolType; }

	public function getToolTier() : ?ToolTier{ return $this->toolTier; }

	/** @internal */
	public function getFacingProperty() : ?string{ return $this->facingProperty; }

	/** @internal */
	public function needsGeometry() : bool{ return $this->needsGeometry; }

	/**
	 * @internal
	 * @return array{Block, list<Property<*>>}
	 */
	public function createPrototype(int $typeId) : array{
		$breakInfo = $this->toolTier !== null ?
			BlockBreakInfo::tier($this->hardness, $this->toolType, $this->toolTier, $this->blastResistance) :
			new BlockBreakInfo($this->hardness, $this->toolType, 0, $this->blastResistance);
		$id = new BlockIdentifier($typeId, $this->tileClass);
		$info = new BlockTypeInfo($breakInfo);
		if($this->settings !== null){
			$settings = new CustomBlockSettings(...$this->settings);
			$block = $this->customFactory !== null ? ($this->customFactory)($id, $this->name, $info, $settings) : new CustomBlock($id, $this->name, $info, $settings);
			if($block->getSettings() !== $settings){
				throw new \InvalidArgumentException("Custom block factories must pass the supplied settings");
			}
			return [$block, $settings->states];
		}
		return [($this->factory ?? throw new \LogicException("Missing block factory"))($id, $this->name, $info), $this->properties];
	}
}
