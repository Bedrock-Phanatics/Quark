<?php

declare(strict_types=1);

namespace quark\block\custom;

use Bedwire\Nbt;
use pocketmine\nbt\NBT as TagType;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use quark\block\Block;
use quark\block\BlockIdentifier;
use quark\block\BlockToolType;
use quark\block\BlockTypeIds;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\tile\TileFactory;
use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\convert\BlockObjectToStateSerializer;
use quark\data\bedrock\block\convert\BlockStateReader;
use quark\data\bedrock\block\convert\BlockStateToObjectDeserializer;
use quark\data\bedrock\block\convert\BlockStateWriter;
use quark\data\bedrock\item\BlockItemIdMap;
use quark\data\runtime\InvalidSerializedRuntimeDataException;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeGroup;
use quark\inventory\CreativeInventory;
use quark\item\custom\ItemDefinition;
use quark\item\StringToItemParser;
use quark\math\AxisAlignedBB;
use quark\network\mcpe\cache\StaticPacketCache;
use quark\network\mcpe\convert\BlockStateDictionaryEntry;
use quark\network\mcpe\convert\TypeConverter;
use quark\plugin\Plugin;
use quark\scheduler\AsyncPool;
use quark\utils\Utils;
use quark\world\format\io\GlobalBlockStateHandlers;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function hash;
use function igbinary_serialize;
use function in_array;
use function ksort;
use function max;
use function min;
use function preg_match;
use function sort;
use function str_starts_with;
use function strcmp;
use function strlen;
use function usort;
use const SORT_STRING;

/**
 * Startup-only custom block registration. Everything a block needs is validated and computed once during
 * register(), and finalize() publishes the network palette before any world loads.
 */
final class BlockRegistry{
	public const int MAX_BLOCKS = 4096;
	public const int MAX_CLIENT_STATES_PER_BLOCK = 8192;
	public const int MAX_CLIENT_STATES = 65536;
	public const int MAX_COMPONENT_BYTES = 131072;
	public const int MAX_TOTAL_COMPONENT_BYTES = 8 * 1024 * 1024;
	public const int MAX_PRESERVED_STATES = 16384;
	public const int FIRST_NETWORK_BLOCK_ID = 10000;

	private static ?self $instance = null;

	/** @var array<string, Block> */
	private array $blocks = [];
	/** @var array<string, array{CompoundTag, list<array<string, Tag>>, ?CreativeCategory, ?CreativeGroup}> */
	private array $pending = [];
	/** @var array<string, string> */
	private array $namespaceOwners = [];
	/** @var array<string, int> */
	private array $preserved = [];
	/** @var array<string, array<string, array<string, true>>> */
	private array $clientValues = [];
	/** @var list<BlockPaletteEntry> */
	private array $paletteEntries = [];
	/** @var list<ItemTypeEntry> */
	private array $itemEntries = [];
	private bool $finalized = false;
	private bool $registering = false;
	private int $clientStates = 0;
	private int $componentBytes = 0;

	/** @internal Use getInstance() in plugins. Explicit dependencies allow isolated registries in tests. */
	public function __construct(
		private RuntimeBlockStateRegistry $runtime,
		private BlockObjectToStateSerializer $serializer,
		private BlockStateToObjectDeserializer $deserializer,
		private StringToItemParser $parser,
		private BlockItemIdMap $blockItems
	){
		$deserializer->setCustomStateFallback($this->preserve(...));
	}

	public static function getInstance() : self{
		return self::$instance ??= new self(
			RuntimeBlockStateRegistry::getInstance(),
			GlobalBlockStateHandlers::getSerializer(),
			GlobalBlockStateHandlers::getDeserializer(),
			StringToItemParser::getInstance(),
			BlockItemIdMap::getInstance()
		);
	}

	public function register(BlockDefinition $definition, Plugin $owner) : void{
		if(\pmmp\thread\Thread::getCurrentThread() !== null){ throw new \LogicException("Custom blocks must be registered on the main thread"); }
		if($this->finalized){ throw new \LogicException("Custom block registration is closed. Blocks must be registered by a plugin with load: STARTUP, before worlds load"); }
		if($this->registering){ throw new \LogicException("Block factories must not register other blocks"); }
		$id = $definition->getIdentifier();
		[$namespace] = explode(":", $id, 2);
		$ownerName = $owner->getName();
		if(in_array($namespace, ["minecraft", "quark"], true)){ throw new \InvalidArgumentException("Namespace '$namespace' is reserved"); }
		if(isset($this->namespaceOwners[$namespace]) && $this->namespaceOwners[$namespace] !== $ownerName){
			throw new \InvalidArgumentException("Namespace '$namespace' belongs to plugin " . $this->namespaceOwners[$namespace]);
		}
		if(isset($this->blocks[$id]) || $this->deserializer->getDeserializerForId($id) !== null || $this->parser->hasAlias($id) || $this->blockItems->lookupBlockId($id) !== null || $this->blockItems->lookupItemId($id) !== null){
			throw new \InvalidArgumentException("Block '$id' is already registered");
		}
		if(count($this->blocks) >= self::MAX_BLOCKS){ throw new \InvalidArgumentException("Custom block limit exceeded"); }

		$this->registering = true;
		try{
			$typeId = BlockTypeIds::newId();
			[$block, $properties] = $definition->createPrototype($typeId);
			if($block->getTypeId() !== $typeId || $block instanceof UnknownCustomBlock){
				throw new \InvalidArgumentException("Factory must return a new block using the supplied identifier");
			}
			if(($tile = $block->getIdInfo()->getTileClass()) !== null && !TileFactory::getInstance()->isRegistered($tile)){
				throw new \InvalidArgumentException("Register tile $tile with TileFactory first, so saved tile data can load");
			}
			$serialize = static function(Block $b) use ($id, $properties) : BlockStateData{
				$writer = BlockStateWriter::create($id);
				foreach($properties as $property){
					$property->serialize($b, $writer); //@phpstan-ignore argument.type
				}
				return $writer->getBlockStateData();
			};
			$read = static function(BlockStateReader $in) use ($block, $properties) : Block{
				$b = clone $block;
				foreach($properties as $property){
					$property->deserialize($b, $in); //@phpstan-ignore argument.type
				}
				return $b;
			};
			[$states, $data, $values] = self::enumerateStates($block, $serialize, $read);
			$clientProperties = self::clientProperties($values);
			$product = 1;
			foreach($clientProperties as $propertyValues){
				$product *= count($propertyValues);
			}
			if($product > self::MAX_CLIENT_STATES_PER_BLOCK || $this->clientStates + $product > self::MAX_CLIENT_STATES){
				throw new \InvalidArgumentException("Block has $product client state combinations, exceeding the supported limit");
			}
			$nbt = $this->buildNbt($definition, $block, $states, $data, $clientProperties);
			$bytes = strlen(Nbt::write(new TreeRoot($nbt), Nbt::NETWORK));
			if($bytes > self::MAX_COMPONENT_BYTES || $this->componentBytes + $bytes > self::MAX_TOTAL_COMPONENT_BYTES){
				throw new \InvalidArgumentException("Block component data exceeds the byte limit");
			}

			$runtime = $this->runtime;
			$runtime->register($block);
			$this->serializer->map($block, $serialize);
			$this->deserializer->map($id, static function(BlockStateReader $in) use ($read, $runtime) : Block{
				$b = $read($in);
				try{
					$valid = $runtime->hasStateId($b->getStateId());
				}catch(InvalidSerializedRuntimeDataException|\InvalidArgumentException|\LogicException){
					$valid = false;
				}
				return $valid ? $b : throw new BlockStateDeserializeException("Invalid state combination");
			});
			$this->parser->registerBlock($id, static fn() => clone $block);
			$this->blockItems->mapCustom($id);
			$this->blocks[$id] = $block;
			$this->pending[$id] = [$nbt, self::clientStates($clientProperties), $definition->getCreativeCategory(), $definition->getCreativeGroup()];
			foreach(Utils::stringifyKeys($values) as $name => $tags){
				$this->clientValues[$id][$name] = array_map(static fn() => true, $tags);
			}
			$this->namespaceOwners[$namespace] = $ownerName;
			$this->clientStates += $product;
			$this->componentBytes += $bytes;
		}catch(\Throwable $e){
			throw new \InvalidArgumentException("Cannot register custom block '$id' for plugin '$ownerName': " . $e->getMessage(), 0, $e);
		}finally{
			$this->registering = false;
		}
	}

	/** Returns an independent block in its default state. */
	public function create(string $identifier) : Block{
		return clone ($this->blocks[$identifier] ?? throw new \InvalidArgumentException("Unknown custom block '$identifier'"));
	}

	public function isRegistered(string $identifier) : bool{ return isset($this->blocks[$identifier]); }

	public function isFinalized() : bool{ return $this->finalized; }

	/** @return list<string> */
	public function getIdentifiers() : array{ return array_keys($this->blocks); }

	/**
	 * @param \Closure(Block) : BlockStateData   $serialize
	 * @param \Closure(BlockStateReader) : Block $read
	 * @return array{list<Block>, list<BlockStateData>, array<string, array<string, Tag>>}
	 */
	private static function enumerateStates(Block $block, \Closure $serialize, \Closure $read) : array{
		$states = [];
		$data = [];
		$values = [];
		$names = null;
		$seen = [];
		foreach($block->generateStatePermutations() as $state){
			$stateData = $serialize($state);
			$tags = $stateData->getStates();
			$keys = array_keys($tags);
			sort($keys, SORT_STRING);
			if($names === null){
				$names = $keys;
			}elseif($names !== $keys){
				throw new \InvalidArgumentException("State codecs must write the same properties for every state");
			}
			foreach(Utils::stringifyKeys($tags) as $name => $tag){
				BlockState::validateName($name);
				$key = self::valueKey($tag);
				$first = $values[$name] ?? [];
				if($first !== [] && $first[array_key_first($first)]::class !== $tag::class){
					throw new \InvalidArgumentException("State '$name' mixes value types");
				}
				$values[$name][$key] = $tag;
			}
			$raw = BlockStateDictionaryEntry::encodeStateProperties($tags);
			if(isset($seen[$raw])){
				throw new \InvalidArgumentException("Two block states serialize identically. Every described state must be persisted by a codec");
			}
			$seen[$raw] = true;
			$reader = new BlockStateReader($stateData);
			$back = $read($reader);
			$reader->checkUnreadProperties();
			if($back->getStateId() !== $state->getStateId()){
				throw new \InvalidArgumentException("A block state does not round-trip through its codecs");
			}
			$states[] = $state;
			$data[] = $stateData;
		}
		if($states === []){
			throw new \InvalidArgumentException("Block has no valid states");
		}
		return [$states, $data, $values];
	}

	private static function valueKey(Tag $tag) : string{
		return match(true){
			$tag instanceof ByteTag && ($tag->getValue() === 0 || $tag->getValue() === 1) => "b" . $tag->getValue(),
			$tag instanceof IntTag => "i" . $tag->getValue(),
			$tag instanceof StringTag && preg_match('/^[a-z0-9_]{1,64}$/D', $tag->getValue()) === 1 => "s" . $tag->getValue(),
			default => throw new \InvalidArgumentException("Block state values must be booleans, ints or lowercase strings of letters, digits and underscores")
		};
	}

	/**
	 * Properties are sorted by name and values ascending, so the order is the same whether or not the client sorts.
	 * @param array<string, array<string, Tag>> $values
	 * @return array<string, list<Tag>>
	 */
	private static function clientProperties(array $values) : array{
		ksort($values, SORT_STRING);
		$result = [];
		foreach(Utils::stringifyKeys($values) as $name => $tags){
			$list = array_values($tags);
			usort($list, static fn(Tag $a, Tag $b) => $a instanceof StringTag && $b instanceof StringTag ? strcmp($a->getValue(), $b->getValue()) : $a->getValue() <=> $b->getValue());
			$result[$name] = $list;
		}
		return $result;
	}

	/**
	 * Every combination the client generates, with the first property changing fastest.
	 * @param array<string, list<Tag>> $properties
	 * @return list<array<string, Tag>>
	 */
	private static function clientStates(array $properties) : array{
		$states = [[]];
		foreach(Utils::stringifyKeys($properties) as $name => $tags){
			$next = [];
			foreach($tags as $tag){
				foreach($states as $state){
					$state[$name] = $tag;
					$next[] = $state;
				}
			}
			$states = $next;
		}
		return $states;
	}

	/**
	 * @param list<Block>              $states
	 * @param list<BlockStateData>     $data
	 * @param array<string, list<Tag>> $clientProperties
	 */
	private function buildNbt(BlockDefinition $definition, Block $block, array $states, array $data, array $clientProperties) : CompoundTag{
		$components = $definition->getComponents();
		$stateNames = [];
		foreach(Utils::stringifyKeys($clientProperties) as $name => $_){
			$stateNames[$name] = true;
		}
		$components->validateStates($stateNames);
		if($definition->needsGeometry() && !$components->hasGeometry()){
			throw new \InvalidArgumentException("Shaped blocks need a geometry() from your resource pack");
		}
		if($components->hasGeometry() !== $components->hasMaterials()){
			throw new \InvalidArgumentException("Blocks need both a geometry and a texture or material");
		}
		$selection = $components->getSelectionBox();

		//per-state values of every component the client must agree with the server on
		$derived = [];
		foreach($states as $i => $state){
			$boxes = $state->getCollisionBoxes();
			if(count($boxes) > 16){
				throw new \InvalidArgumentException("Blocks support at most 16 collision boxes");
			}
			$collision = [];
			foreach($boxes as $box){
				self::validateCollisionBox($box);
				$collision[] = CompoundTag::create()
					->setFloat("maxX", $box->maxX * 16)->setFloat("maxY", $box->maxY * 16)->setFloat("maxZ", $box->maxZ * 16)
					->setFloat("minX", $box->minX * 16)->setFloat("minY", $box->minY * 16)->setFloat("minZ", $box->minZ * 16);
			}
			$derived["minecraft:collision_box"][$i] = CompoundTag::create()->setByte("enabled", $collision === [] ? 0 : 1)->setTag("boxes", new ListTag($collision, TagType::TAG_Compound));
			$derived["minecraft:selection_box"][$i] = BlockComponents::selectionTag($selection ?? self::selectionFor($boxes));
			$derived["minecraft:light_emission"][$i] = CompoundTag::create()->setByte("emission", $state->getLightLevel());
			$derived["minecraft:light_dampening"][$i] = CompoundTag::create()->setByte("lightLevel", min(15, $state->getLightFilter()));
			$derived["minecraft:friction"][$i] = CompoundTag::create()->setFloat("value", max(0.0, min(0.9, 1 - $state->getFrictionFactor())));
			if($state->getFlammability() > 0 || $state->getFlameEncouragement() > 0){
				$derived["minecraft:flammable"][$i] = CompoundTag::create()->setInt("catch_chance_modifier", $state->getFlameEncouragement())->setInt("destroy_chance_modifier", $state->getFlammability());
			}
			if($state->canBeReplaced()){
				$derived["minecraft:replaceable"][$i] = CompoundTag::create();
			}
		}

		$base = $components->toNbt();
		$breakInfo = $block->getBreakInfo();
		$base->setTag("minecraft:destructible_by_mining", CompoundTag::create()->setFloat("value", $breakInfo->getHardness()));
		$base->setTag("minecraft:destructible_by_explosion", CompoundTag::create()->setFloat("explosion_resistance", $breakInfo->getBlastResistance()));
		$base->setTag("minecraft:display_name", CompoundTag::create()->setString("value", $block->getName()));

		$permutations = [];
		if(($facing = $definition->getFacingProperty()) !== null){
			foreach(["west" => 1, "south" => 2, "east" => 3] as $value => $turns){
				$permutations[] = CompoundTag::create()
					->setString("condition", "query.block_state('$facing') == '$value'")
					->setTag("components", CompoundTag::create()->setTag("minecraft:transformation", BlockComponents::transformationTag(0, $turns, 0)));
			}
		}
		foreach($derived as $component => $byState){
			$groups = [];
			foreach($byState as $i => $tag){
				$groups[Nbt::write(new TreeRoot($tag), Nbt::NETWORK)][] = $i;
			}
			if(count($groups) === 1 && count($byState) === count($states)){
				$base->setTag($component, $byState[array_key_first($byState)]);
				continue;
			}
			$condition = new StateConditions($data, $byState);
			foreach($groups as $indexes){
				$permutations[] = CompoundTag::create()
					->setString("condition", $condition->forStates($indexes))
					->setTag("components", CompoundTag::create()->setTag($component, $byState[$indexes[0]]));
			}
		}
		foreach($components->getPermutationTags() as [$condition, $tag]){
			$permutations[] = CompoundTag::create()->setString("condition", $condition)->setTag("components", $tag);
		}
		if(count($permutations) > BlockComponents::MAX_PERMUTATIONS * 4){
			throw new \InvalidArgumentException("Block needs too many permutations");
		}

		$properties = [];
		foreach(Utils::stringifyKeys($clientProperties) as $name => $tags){
			$properties[] = CompoundTag::create()->setString("name", $name)->setTag("enum", new ListTag($tags));
		}
		$tags = [];
		foreach([
			BlockToolType::PICKAXE => "pickaxe", BlockToolType::AXE => "axe", BlockToolType::SHOVEL => "shovel",
			BlockToolType::HOE => "hoe", BlockToolType::SHEARS => "shears", BlockToolType::SWORD => "sword"
		] as $flag => $tool){
			if(($definition->getToolType() & $flag) !== 0){
				$tags[] = new StringTag("minecraft:is_{$tool}_item_destructible");
			}
		}
		$vanillaData = CompoundTag::create()->setInt("block_id", 0);
		if($definition->getToolTier() !== null){
			$vanillaData->setByte("requires_correct_tool_for_drops", 1);
		}
		$category = $definition->getCreativeCategory();
		return CompoundTag::create()
			->setTag("blockTags", new ListTag($tags, TagType::TAG_String))
			->setTag("components", $base)
			->setTag("menu_category", CompoundTag::create()
				->setString("category", $category === null ? "none" : self::categoryName($category))
				->setString("group", "")
				->setByte("is_hidden_in_commands", 0))
			->setInt("molangVersion", 13)
			->setTag("permutations", new ListTag($permutations, TagType::TAG_Compound))
			->setTag("properties", new ListTag($properties, TagType::TAG_Compound))
			->setTag("vanilla_block_data", $vanillaData);
	}

	private static function categoryName(CreativeCategory $category) : string{
		return match($category){
			CreativeCategory::CONSTRUCTION => "construction",
			CreativeCategory::NATURE => "nature",
			CreativeCategory::EQUIPMENT => "equipment",
			CreativeCategory::ITEMS => "items"
		};
	}

	private static function validateCollisionBox(AxisAlignedBB $box) : void{
		if(
			$box->minX < 0 || $box->minY < 0 || $box->minZ < 0 || $box->maxX > 1 || $box->maxY > 1.5 || $box->maxZ > 1 ||
			$box->minX >= $box->maxX || $box->minY >= $box->maxY || $box->minZ >= $box->maxZ
		){
			throw new \InvalidArgumentException("Collision boxes must be non-empty and within 0 - 1 horizontally and 0 - 1.5 vertically");
		}
	}

	/** @param list<AxisAlignedBB> $boxes */
	private static function selectionFor(array $boxes) : AxisAlignedBB{
		if($boxes === []){
			return new AxisAlignedBB(0, 0, 0, 1, 1, 1);
		}
		$min = [1.0, 1.0, 1.0];
		$max = [0.0, 0.0, 0.0];
		foreach($boxes as $box){
			$min = [min($min[0], $box->minX), min($min[1], $box->minY), min($min[2], $box->minZ)];
			$max = [max($max[0], $box->maxX), max($max[1], $box->maxY), max($max[2], $box->maxZ)];
		}
		return new AxisAlignedBB($min[0], min($min[1], 0.9375), $min[2], $max[0], min(1.0, $max[1]), $max[2]);
	}

	/**
	 * Keeps a saved custom state it cannot load, so saving the chunk writes it back unchanged.
	 * @internal
	 */
	public function preserve(BlockStateData $data) : ?int{
		$name = $data->getName();
		if(str_starts_with($name, "minecraft:") || \pmmp\thread\Thread::getCurrentThread() !== null){
			return null;
		}
		try{
			ItemDefinition::validateIdentifier($name);
		}catch(\InvalidArgumentException){
			return null;
		}
		if(isset($this->clientValues[$name]) && self::isClientState($this->clientValues[$name], $data)){
			//client-only combinations of a loaded block are invalid input, not saved data
			return null;
		}
		$key = Nbt::write(new TreeRoot($data->toNbt()), Nbt::BEDROCK);
		if(isset($this->preserved[$key])){
			return $this->preserved[$key];
		}
		if(count($this->preserved) >= self::MAX_PRESERVED_STATES){
			return null;
		}
		$block = new UnknownCustomBlock(new BlockIdentifier(BlockTypeIds::newId()), $data);
		$this->runtime->register($block);
		$this->serializer->map($block, $data);
		return $this->preserved[$key] = $block->getStateId();
	}

	/** @param array<string, array<string, true>> $values */
	private static function isClientState(array $values, BlockStateData $data) : bool{
		$states = $data->getStates();
		if(count($states) !== count($values)){
			return false;
		}
		foreach(Utils::stringifyKeys($states) as $name => $tag){
			try{
				if(!isset($values[$name][self::valueKey($tag)])){
					return false;
				}
			}catch(\InvalidArgumentException){
				return false;
			}
		}
		return true;
	}

	/** @internal Called after STARTUP plugins enable and before any world loads. */
	public function finalize(TypeConverter $converter, CreativeInventory $creative, ?AsyncPool $workers = null) : void{
		if(\pmmp\thread\Thread::getCurrentThread() !== null){ throw new \LogicException("Custom blocks must be finalized on the main thread"); }
		if($this->registering || $this->finalized){ throw new \LogicException("Block registry cannot be finalized in its current state"); }
		$this->finalized = true;
		if($this->pending === []){
			return;
		}
		$names = array_keys($this->pending);
		usort($names, static fn(string $a, string $b) => strcmp(hash("fnv164", $a), hash("fnv164", $b)));
		foreach($names as $i => $name){
			if($i > 0 && hash("fnv164", $name) === hash("fnv164", $names[$i - 1])){
				//the client could order these either way
				throw new \InvalidArgumentException("Blocks '$name' and '" . $names[$i - 1] . "' have the same palette hash");
			}
		}

		$custom = [];
		foreach($names as $i => $name){
			$blockId = self::FIRST_NETWORK_BLOCK_ID + $i;
			[$nbt, $clientStates] = $this->pending[$name];
			$nbt = clone $nbt;
			$nbt->getCompoundTag("vanilla_block_data")?->setInt("block_id", $blockId);
			$this->paletteEntries[] = new BlockPaletteEntry($name, new CacheableNbt($nbt));
			$this->itemEntries[] = new ItemTypeEntry($name, 255 - $blockId, false, 2, new CacheableNbt(CompoundTag::create()));
			foreach($clientStates as $meta => $states){
				$custom[$name][] = new BlockStateDictionaryEntry($name, $states, $meta);
			}
		}
		$converter->finalizeBlockRegistry(self::mergePalette($converter->getBlockTranslator()->getBlockStateDictionary()->getStates(), $custom));
		StaticPacketCache::reset();

		$light = [];
		$filter = [];
		$sky = [];
		foreach(Utils::stringifyKeys($this->pending) as $name => [, , $category, $group]){
			$block = $this->blocks[$name];
			if($category !== null){
				$creative->add($block->asItem(), $category, $group);
			}
			foreach($block->generateStatePermutations() as $state){
				$stateId = $state->getStateId();
				$light[$stateId] = $this->runtime->light[$stateId];
				$filter[$stateId] = $this->runtime->lightFilter[$stateId];
				if(isset($this->runtime->blocksDirectSkyLight[$stateId])){
					$sky[$stateId] = true;
				}
			}
		}
		$this->pending = [];
		if($workers !== null){
			$lightData = igbinary_serialize([$light, $filter, $sky]) ?? throw new \LogicException("Cannot encode custom light data");
			$workers->addWorkerStartHook(static function(int $worker) use ($workers, $lightData) : void{
				$workers->submitTaskToWorker(new CustomBlockLightTask($lightData), $worker);
			});
		}
	}

	/**
	 * Inserts each custom block's states into the vanilla palette, which the client orders by the FNV-1a 64 hash of
	 * block names. Vanilla order and states are kept.
	 *
	 * @param array<int, BlockStateDictionaryEntry>          $vanilla
	 * @param array<string, list<BlockStateDictionaryEntry>> $custom  names in hash order
	 * @return list<BlockStateDictionaryEntry>
	 */
	private static function mergePalette(array $vanilla, array $custom) : array{
		$result = [];
		$pendingNames = array_keys($custom);
		$next = 0;
		$previous = null;
		foreach($vanilla as $entry){
			$name = $entry->getStateName();
			if($name !== $previous){
				$hash = hash("fnv164", $name);
				while(isset($pendingNames[$next]) && ($order = strcmp(hash("fnv164", $pendingNames[$next]), $hash)) <= 0){
					if($order === 0){
						throw new \InvalidArgumentException("Block '" . $pendingNames[$next] . "' has the same palette hash as $name");
					}
					foreach($custom[$pendingNames[$next++]] as $customEntry){
						$result[] = $customEntry;
					}
				}
				$previous = $name;
			}
			$result[] = $entry;
		}
		while(isset($pendingNames[$next])){
			foreach($custom[$pendingNames[$next++]] as $customEntry){
				$result[] = $customEntry;
			}
		}
		return $result;
	}

	/**
	 * Block properties StartGame appends to the vanilla data-driven palette.
	 * @return list<BlockPaletteEntry>
	 */
	public function getPaletteEntries() : array{ return $this->paletteEntries; }

	/**
	 * Network item entries for custom block items, published with the item registry.
	 * @internal
	 * @return list<ItemTypeEntry>
	 */
	public function getItemTypeEntries() : array{ return $this->itemEntries; }
}
