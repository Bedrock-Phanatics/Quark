<?php

declare(strict_types=1);

namespace quark\block\custom;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use quark\block\Block;
use quark\block\BlockIdentifier;
use quark\block\BlockToolType;
use quark\block\BlockTypeInfo;
use quark\block\Door;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\Slab;
use quark\block\Stair;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\block\utils\SlabType;
use quark\block\VanillaBlocks;
use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\convert\BlockObjectToStateSerializer;
use quark\data\bedrock\block\convert\BlockSerializerDeserializerRegistrar;
use quark\data\bedrock\block\convert\BlockStateToObjectDeserializer;
use quark\data\bedrock\block\convert\property\EnumFromRawStateMap;
use quark\data\bedrock\block\convert\property\ValueFromStringProperty;
use quark\data\bedrock\block\convert\VanillaBlockMappings;
use quark\data\bedrock\item\BlockItemIdMap;
use quark\data\bedrock\item\ItemDeserializer;
use quark\data\bedrock\item\ItemSerializer;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeInventory;
use quark\item\custom\ItemRegistry;
use quark\item\ItemBlock;
use quark\item\StringToItemParser;
use quark\item\ToolTier;
use quark\item\VanillaItems;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\network\mcpe\convert\TypeConverter;
use quark\plugin\Plugin;
use quark\utils\Utils;
use function array_keys;
use function array_search;
use function count;
use function explode;
use function hash;
use function preg_match;
use function sort;
use function str_replace;
use function strcmp;
use function strtoupper;
use function trim;
use function usort;

final class CustomBlockTest extends TestCase{
	private static int $namespace = 0;

	private BlockObjectToStateSerializer $serializer;
	private BlockStateToObjectDeserializer $deserializer;
	private StringToItemParser $parser;
	private BlockRegistry $registry;
	private Plugin $owner;
	private string $ns;

	private static ?RuntimeBlockStateRegistry $globalRuntime = null;
	private static ?BlockItemIdMap $globalBlockItems = null;

	protected function setUp() : void{
		//custom states must not leak into tests that walk every registered state
		self::$globalRuntime ??= RuntimeBlockStateRegistry::getInstance();
		self::$globalBlockItems ??= BlockItemIdMap::getInstance();
		RuntimeBlockStateRegistry::setInstance(new RuntimeBlockStateRegistry());
		BlockItemIdMap::setInstance(clone self::$globalBlockItems);
		$this->serializer = new BlockObjectToStateSerializer();
		$this->deserializer = new BlockStateToObjectDeserializer();
		VanillaBlockMappings::init(new BlockSerializerDeserializerRegistrar($this->deserializer, $this->serializer));
		$this->parser = new StringToItemParser();
		$this->registry = $this->newRegistry();
		$this->owner = $this->plugin("TestPlugin");
		$this->ns = "cb" . ++self::$namespace;
	}

	protected function tearDown() : void{
		if(self::$globalRuntime !== null && self::$globalBlockItems !== null){
			RuntimeBlockStateRegistry::setInstance(self::$globalRuntime);
			BlockItemIdMap::setInstance(self::$globalBlockItems);
		}
	}

	private function newRegistry(?BlockItemIdMap $blockItems = null) : BlockRegistry{
		return new BlockRegistry(RuntimeBlockStateRegistry::getInstance(), $this->serializer, $this->deserializer, $this->parser, $blockItems ?? BlockItemIdMap::getInstance());
	}

	private function plugin(string $name) : Plugin{
		$plugin = $this->createMock(Plugin::class);
		$plugin->method("getName")->willReturn($name);
		return $plugin;
	}

	private function id(string $path) : string{ return $this->ns . ":" . $path; }

	private function converter() : TypeConverter{
		return new TypeConverter(new ItemSerializer($this->serializer), new ItemDeserializer($this->deserializer), $this->serializer);
	}

	private function assertRoundTrips(Block $block) : void{
		foreach($block->generateStatePermutations() as $state){
			$data = $this->serializer->serialize($state->getStateId());
			self::assertSame($state->getStateId(), $this->deserializer->deserialize($data), "state of " . $data->getName());
		}
	}

	public function testCubeRegistersWithFastCollisionPathAndIndependentInstances() : void{
		$this->registry->register(BlockDefinition::cube($this->id("ruby"), "Ruby Block", "ruby")->hardness(5)->blastResistance(30)->tool(BlockToolType::PICKAXE, ToolTier::IRON), $this->owner);
		$a = $this->registry->create($this->id("ruby"));
		$b = $this->registry->create($this->id("ruby"));
		self::assertNotSame($a, $b);
		self::assertTrue($a->isSameState($b));
		$runtime = RuntimeBlockStateRegistry::getInstance();
		self::assertTrue($runtime->hasStateId($a->getStateId()));
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_CUBE, $runtime->collisionInfo[$a->getStateId()]);
		self::assertSame(15, $runtime->lightFilter[$a->getStateId()]);
		self::assertSame(30.0, $runtime->blastResistance[$a->getStateId()]);
		self::assertSame("Ruby Block", $a->getName());
		$this->assertRoundTrips($a);
		self::assertSame([], $a->getDrops(VanillaItems::AIR()));
		self::assertSame([], $a->getDrops(VanillaItems::STONE_PICKAXE()));
		self::assertCount(1, $a->getDrops(VanillaItems::IRON_PICKAXE()));
		self::assertTrue($a->asItem()->equalsExact($this->parser->parse($this->id("ruby")) ?? VanillaItems::AIR()));
		self::assertSame($this->id("ruby"), $this->registry->getIdentifier($a));
		self::assertNull($this->registry->getIdentifier(VanillaBlocks::STONE()));
	}

	public function testInvalidAndConflictingRegistrationsAreRejected() : void{
		foreach(["Bad:id", "noNamespace", "a:b..c", "a:"] as $bad){
			try{
				BlockDefinition::cube($bad, "Bad");
				self::fail("Accepted '$bad'");
			}catch(\InvalidArgumentException){}
		}
		foreach(["minecraft:stone", "quark:thing"] as $reserved){
			try{
				$this->registry->register(BlockDefinition::cube($reserved, "Reserved"), $this->owner);
				self::fail("Accepted reserved '$reserved'");
			}catch(\InvalidArgumentException){}
		}
		$this->registry->register(BlockDefinition::cube($this->id("one"), "One", "one"), $this->owner);
		$this->expectExceptionMessage("already registered");
		$this->registry->register(BlockDefinition::cube($this->id("one"), "One", "one"), $this->owner);
	}

	public function testNamespaceBelongsToItsFirstPlugin() : void{
		$this->registry->register(BlockDefinition::cube($this->id("one"), "One", "one"), $this->owner);
		$this->expectExceptionMessage("belongs to plugin TestPlugin");
		$this->registry->register(BlockDefinition::cube($this->id("two"), "Two", "two"), $this->plugin("Other"));
	}

	public function testFailedRegistrationLeavesNoMappings() : void{
		try{
			$this->registry->register(BlockDefinition::stairs($this->id("stairs"), "Stairs"), $this->owner);
			self::fail("Stairs without geometry were accepted");
		}catch(\InvalidArgumentException $e){
			self::assertStringContainsString("geometry", $e->getMessage());
		}
		self::assertFalse($this->registry->isRegistered($this->id("stairs")));
		self::assertNull($this->deserializer->getDeserializerForId($this->id("stairs")));
		self::assertFalse($this->parser->hasAlias($this->id("stairs")));
		self::assertNull(BlockItemIdMap::getInstance()->lookupBlockId($this->id("stairs")));
		$this->registry->register(BlockDefinition::stairs($this->id("stairs"), "Stairs")->withComponents((new BlockComponents())->geometry("geometry.stairs")->texture("stairs")), $this->owner);
		self::assertTrue($this->registry->isRegistered($this->id("stairs")));

		$id = $this->id("sneaky");
		$parser = $this->parser;
		try{
			$this->registry->register(BlockDefinition::cube($id, "Sneaky")->withFactory(static function(BlockIdentifier $bid, string $name, BlockTypeInfo $info, CustomBlockSettings $settings) use ($parser, $id) : CustomBlock{
				$parser->register($id, static fn() => VanillaItems::STICK());
				return new CustomBlock($bid, $name, $info, $settings);
			}), $this->owner);
			self::fail("Factory side effects were accepted");
		}catch(\InvalidArgumentException $e){
			self::assertStringContainsString("Factory changed existing registrations", $e->getMessage());
		}
		self::assertNull($this->deserializer->getDeserializerForId($id));
		self::assertFalse($this->registry->isRegistered($id));
	}

	public function testStatesRoundTripAndItemStatesSurvivePicking() : void{
		$this->registry->register(BlockDefinition::cube($this->id("lamp"), "Lamp", "lamp")->states(
			BlockState::bool($this->id("lit")),
			BlockState::int($this->id("level"), 0, 5, 2),
			BlockState::enum($this->id("color"), ["red", "green", "blue"], "green")->persistsInItem()
		), $this->owner);
		$block = $this->registry->create($this->id("lamp"));
		self::assertInstanceOf(CustomBlock::class, $block);
		self::assertFalse($block->getState($this->id("lit")));
		self::assertSame(2, $block->getState($this->id("level")));
		self::assertSame("green", $block->getState($this->id("color")));
		self::assertCount(2 * 6 * 3, [...$block->generateStatePermutations()]);
		$this->assertRoundTrips($block);

		$block->setState($this->id("lit"), true)->setState($this->id("level"), 5)->setState($this->id("color"), "blue");
		$data = $this->serializer->serialize($block->getStateId());
		self::assertSame(1, $data->getState($this->id("lit"))?->getValue());
		self::assertSame(5, $data->getState($this->id("level"))?->getValue());
		self::assertSame("blue", $data->getState($this->id("color"))?->getValue());

		$item = $block->asItem();
		self::assertInstanceOf(ItemBlock::class, $item);
		$placed = $item->getBlock();
		self::assertInstanceOf(CustomBlock::class, $placed);
		self::assertSame("blue", $placed->getState($this->id("color")), "item states are kept");
		self::assertFalse($placed->getState($this->id("lit")), "block-only states reset");

		try{
			$block->setState($this->id("level"), 6);
			self::fail("Out-of-range value accepted");
		}catch(\InvalidArgumentException){}
		try{
			$block->setState($this->id("lit"), 1);
			self::fail("Wrongly typed value accepted");
		}catch(\InvalidArgumentException){}
	}

	public function testStateCapacityIsExactlyElevenBits() : void{
		$this->registry->register(BlockDefinition::cube($this->id("max"), "Max", "max")->states(
			BlockState::int($this->id("a"), 0, 255),
			BlockState::int($this->id("b"), 0, 7)
		), $this->owner);
		self::assertCount(2048, [...$this->registry->create($this->id("max"))->generateStatePermutations()]);

		$this->expectExceptionMessage("11 bits");
		$this->registry->register(BlockDefinition::cube($this->id("over"), "Over", "over")->states(
			BlockState::int($this->id("a"), 0, 255),
			BlockState::int($this->id("b"), 0, 15)
		), $this->owner);
	}

	public function testInvalidStatesAreRejected() : void{
		foreach([
			fn() => BlockState::bool("nonamespace"),
			fn() => BlockState::bool("minecraft:open"),
			fn() => BlockState::int("a:b", 5, 4),
			fn() => BlockState::int("a:b", 0, 300),
			fn() => BlockState::enum("a:b", ["one"]),
			fn() => BlockState::enum("a:b", ["one", "one"]),
			fn() => BlockState::enum("a:b", ["one", "Two"]),
			fn() => BlockState::enum("a:b", ["one", "two"], "three"),
		] as $i => $factory){
			try{
				$factory();
				self::fail("Invalid state $i accepted");
			}catch(\InvalidArgumentException){}
		}
		$this->expectExceptionMessage("Duplicate block state");
		$this->registry->register(BlockDefinition::cube($this->id("dupe"), "Dupe", "dupe")->states(BlockState::bool($this->id("x")), BlockState::bool($this->id("x"))), $this->owner);
	}

	public function testFamiliesReuseQuarkBehaviorAndPersistEveryState() : void{
		$visual = (new BlockComponents())->geometry("geometry.test")->texture("test");
		foreach([
			"slab" => BlockDefinition::slab($this->id("slab"), "Ruby"),
			"stairs" => BlockDefinition::stairs($this->id("stairs"), "Ruby Stairs"),
			"fence" => BlockDefinition::fence($this->id("fence"), "Ruby Fence"),
			"wall" => BlockDefinition::wall($this->id("wall"), "Ruby Wall"),
			"door" => BlockDefinition::door($this->id("door"), "Ruby Door"),
			"trapdoor" => BlockDefinition::trapdoor($this->id("trapdoor"), "Ruby Trapdoor"),
			"gate" => BlockDefinition::fenceGate($this->id("gate"), "Ruby Gate"),
			"crop" => BlockDefinition::crop($this->id("crop"), "Ruby Crop", VanillaItems::DIAMOND()),
			"plant" => BlockDefinition::plant($this->id("plant"), "Ruby Flower", "flower"),
			"decor" => BlockDefinition::decorative($this->id("decor"), "Ruby Decor", "decor"),
			"dir" => BlockDefinition::directional($this->id("dir"), "Ruby Furnace", "furnace"),
		] as $path => $definition){
			$this->registry->register($path === "plant" || $path === "decor" || $path === "dir" ? $definition : $definition->withComponents($visual), $this->owner);
			$this->assertRoundTrips($this->registry->create($this->id($path)));
		}
		$runtime = RuntimeBlockStateRegistry::getInstance();
		$slab = $this->registry->create($this->id("slab"));
		self::assertInstanceOf(Slab::class, $slab);
		self::assertSame("Ruby Slab", $slab->getName());
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_CUSTOM, $runtime->collisionInfo[$slab->setSlabType(SlabType::BOTTOM)->getStateId()]);
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_CUBE, $runtime->collisionInfo[$slab->setSlabType(SlabType::DOUBLE)->getStateId()]);
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW, $runtime->collisionInfo[$this->registry->create($this->id("fence"))->getStateId()]);
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_NONE, $runtime->collisionInfo[$this->registry->create($this->id("plant"))->getStateId()]);
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_NONE, $runtime->collisionInfo[$this->registry->create($this->id("decor"))->getStateId()]);
		self::assertInstanceOf(Stair::class, $this->registry->create($this->id("stairs")));
		self::assertInstanceOf(Door::class, $this->registry->create($this->id("door")));

		$crop = $this->registry->create($this->id("crop"));
		self::assertInstanceOf(CustomCrop::class, $crop);
		self::assertCount(1, $crop->getDrops(VanillaItems::AIR()));
		self::assertCount(2, $crop->setAge(CustomCrop::MAX_AGE)->getDrops(VanillaItems::AIR()));

		$data = $this->serializer->serialize($this->registry->create($this->id("door"))->getStateId());
		self::assertSame([$this->id("facing"), $this->id("hinge_right"), $this->id("open"), $this->id("upper")], self::sortedKeys($data->getStates()));
	}

	/**
	 * @param array<string, Tag> $states
	 * @return list<string>
	 */
	private static function sortedKeys(array $states) : array{
		$keys = array_keys($states);
		sort($keys);
		return $keys;
	}

	public function testFactoriesAndSubclassesAreValidated() : void{
		$this->registry->register(BlockDefinition::cube($this->id("sub"), "Sub", "sub")->states(BlockState::bool($this->id("on")))->withFactory(
			static fn(BlockIdentifier $id, string $name, BlockTypeInfo $info, CustomBlockSettings $settings) => new TestToggleBlock($id, $name, $info, $settings)
		), $this->owner);
		self::assertInstanceOf(TestToggleBlock::class, $this->registry->create($this->id("sub")));

		try{
			$this->registry->register(BlockDefinition::cube($this->id("bad"), "Bad", "bad")->withFactory(
				static fn(BlockIdentifier $id, string $name, BlockTypeInfo $info, CustomBlockSettings $settings) => new TestToggleBlock($id, $name, $info, new CustomBlockSettings())
			), $this->owner);
			self::fail("Factory with foreign settings accepted");
		}catch(\InvalidArgumentException){}

		//an unpersisted state would collide on disk
		$this->expectExceptionMessage("serialize identically");
		$this->registry->register(BlockDefinition::fromFactory($this->id("lossy"), "Lossy", static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Door($id, $n, $info)), $this->owner);
	}

	public function testCodecStringValuesMustBeSafeInConditions() : void{
		$this->expectExceptionMessage("lowercase strings");
		$this->registry->register(BlockDefinition::fromFactory($this->id("quote"), "Quote", static fn(BlockIdentifier $id, string $n, BlockTypeInfo $info) => new Slab($id, $n, $info), [
			new ValueFromStringProperty($this->id("type"), EnumFromRawStateMap::string(SlabType::class, fn(SlabType $t) => match($t){
				SlabType::BOTTOM => "bottom') || (true",
				SlabType::TOP => "top",
				SlabType::DOUBLE => "double"
			}), fn(Slab $b) => $b->getSlabType(), fn(Slab $b, SlabType $v) => $b->setSlabType($v))
		])->withComponents((new BlockComponents())->geometry("geometry.x")->texture("x")), $this->owner);
	}

	public function testCachedStateIdsFollowEveryChange() : void{
		$this->registry->register(BlockDefinition::cube($this->id("cache"), "Cache", "cache")->states(BlockState::int($this->id("n"), 0, 9), BlockState::bool($this->id("b"))->persistsInItem()), $this->owner);
		$block = $this->registry->create($this->id("cache"));
		self::assertInstanceOf(CustomBlock::class, $block);
		$runtime = RuntimeBlockStateRegistry::getInstance();
		foreach([[3, true], [9, false], [0, true]] as [$n, $b]){
			$id = $block->setState($this->id("n"), $n)->setState($this->id("b"), $b)->getStateId();
			$fresh = $runtime->fromStateId($id);
			self::assertInstanceOf(CustomBlock::class, $fresh);
			self::assertSame([$n, $b], [$fresh->getState($this->id("n")), $fresh->getState($this->id("b"))]);
			$item = $block->asItem()->getBlock();
			self::assertSame($runtime->fromStateId($item->getStateId())->getStateId(), $item->getStateId());
		}
	}

	public function testTilesMustBeRegisteredFirst() : void{
		try{
			$this->registry->register(BlockDefinition::cube($this->id("safe"), "Safe", "safe")->tile(TestSafeTile::class), $this->owner);
			self::fail("Unregistered tile accepted");
		}catch(\InvalidArgumentException $e){
			self::assertStringContainsString("TileFactory", $e->getMessage());
		}
		TileFactory::getInstance()->register(TestSafeTile::class, ["cbtest:safe"]);
		$this->registry->register(BlockDefinition::cube($this->id("safe"), "Safe", "safe")->tile(TestSafeTile::class), $this->owner);
		self::assertSame(TestSafeTile::class, $this->registry->create($this->id("safe"))->getIdInfo()->getTileClass());
	}

	public function testCollisionMustFitTheClientLimits() : void{
		$this->registry->register(BlockDefinition::cube($this->id("tall"), "Tall", "tall")->collision(new AxisAlignedBB(0, 0, 0, 1, 1.5, 1)), $this->owner);
		self::assertSame(RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW, RuntimeBlockStateRegistry::getInstance()->collisionInfo[$this->registry->create($this->id("tall"))->getStateId()]);
		foreach([new AxisAlignedBB(0, 0, 0, 1, 1.6, 1), new AxisAlignedBB(-0.1, 0, 0, 1, 1, 1), new AxisAlignedBB(0, 0, 0, 0, 1, 1)] as $i => $box){
			try{
				$this->registry->register(BlockDefinition::cube($this->id("bad$i"), "Bad", "bad")->collision($box), $this->owner);
				self::fail("Collision box $i accepted");
			}catch(\InvalidArgumentException $e){
				self::assertStringContainsString("Collision boxes", $e->getMessage());
			}
		}
	}

	public function testPermutationConditionsAreValidated() : void{
		$this->expectExceptionMessage("unknown state");
		$this->registry->register(BlockDefinition::cube($this->id("perm"), "Perm", "perm")->states(BlockState::bool($this->id("lit")))->withComponents(
			(new BlockComponents())->geometry(BlockComponents::FULL_BLOCK)->texture("perm")->permutation("query.block_state('" . $this->id("missing") . "') == true", (new BlockComponents())->texture("lit"))
		), $this->owner);
	}

	public function testMalformedConditionsAreRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		(new BlockComponents())->permutation("query.block_state('a:b') == true; drop table", new BlockComponents());
	}

	private function finalized(?CreativeInventory $creative = null) : TypeConverter{
		$converter = $this->converter();
		$this->registry->finalize($converter, $creative ?? CreativeInventory::getInstance());
		return $converter;
	}

	public function testNetworkPaletteIsSortedAndTranslatesBothWays() : void{
		$stone = $this->converter()->getBlockTranslator()->internalIdToNetworkStateData(VanillaBlocks::STONE()->getStateId());
		$this->registry->register(BlockDefinition::cube($this->id("a"), "A", "a")->states(BlockState::enum($this->id("color"), ["red", "blue"]), BlockState::bool($this->id("lit"))), $this->owner);
		$this->registry->register(BlockDefinition::slab($this->id("b"), "B")->withComponents((new BlockComponents())->geometry("geometry.slab")->texture("b")), $this->owner);
		$converter = $this->finalized();
		$translator = $converter->getBlockTranslator();
		$dictionary = $translator->getBlockStateDictionary();

		$previous = "";
		$names = [];
		foreach($dictionary->getStates() as $entry){
			if($entry->getStateName() !== $previous){
				self::assertArrayNotHasKey($entry->getStateName(), $names, "states of one block are contiguous");
				$names[$entry->getStateName()] = true;
				if($previous !== ""){
					self::assertLessThan(0, strcmp(hash("fnv164", $previous), hash("fnv164", $entry->getStateName())), "palette is sorted by name hash");
				}
				$previous = $entry->getStateName();
			}
		}
		self::assertArrayHasKey($this->id("a"), $names);
		self::assertTrue($stone->equals($translator->internalIdToNetworkStateData(VanillaBlocks::STONE()->getStateId())), "vanilla mappings are unchanged");

		foreach(["a", "b"] as $path){
			foreach($this->registry->create($this->id($path))->generateStatePermutations() as $state){
				$networkId = $translator->internalIdToNetworkId($state->getStateId());
				$data = $dictionary->generateDataFromStateId($networkId);
				self::assertNotNull($data);
				self::assertTrue($data->equals($this->serializer->serialize($state->getStateId())));
				self::assertSame($state->getStateId(), $this->deserializer->deserialize($data));
			}
		}

		$entries = $this->registry->getPaletteEntries();
		self::assertCount(2, $entries);
		$expectedOrder = [$this->id("a"), $this->id("b")];
		usort($expectedOrder, static fn(string $x, string $y) => strcmp(hash("fnv164", $x), hash("fnv164", $y)));
		foreach($entries as $i => $entry){
			self::assertSame($expectedOrder[$i], $entry->getName());
			$root = self::states($entry);
			self::assertSame(BlockRegistry::FIRST_NETWORK_BLOCK_ID + $i, $root->getCompoundTag("vanilla_block_data")?->getInt("block_id"));
			self::assertSame(13, $root->getInt("molangVersion"));
		}
		$root = self::states($entries[array_search($this->id("a"), $expectedOrder, true)]);
		$properties = $root->getListTag("properties");
		self::assertNotNull($properties);
		$names = [];
		$colors = [];
		foreach($properties as $property){
			self::assertInstanceOf(CompoundTag::class, $property);
			$names[] = $property->getString("name");
			if($property->getString("name") === $this->id("color")){
				foreach($property->getListTag("enum") ?? [] as $value){
					self::assertInstanceOf(StringTag::class, $value);
					$colors[] = $value->getValue();
				}
			}
		}
		self::assertSame([$this->id("color"), $this->id("lit")], $names);
		self::assertSame(["blue", "red"], $colors);

		//the first property changes fastest in client order
		$aStates = [];
		foreach($dictionary->getStates() as $entry){
			if($entry->getStateName() === $this->id("a")){
				$data = $entry->generateStateData();
				$color = $data->getState($this->id("color"));
				$lit = $data->getState($this->id("lit"));
				self::assertInstanceOf(StringTag::class, $color);
				self::assertInstanceOf(ByteTag::class, $lit);
				$aStates[] = $color->getValue() . "/" . $lit->getValue();
			}
		}
		self::assertSame(["blue/0", "red/0", "blue/1", "red/1"], $aStates);

		$this->expectExceptionMessage("closed");
		$this->registry->register(BlockDefinition::cube($this->id("late"), "Late", "late"), $this->owner);
	}

	public function testRegistrationOrderDoesNotChangeNetworkOrPersistentData() : void{
		$snapshots = [];
		foreach([[0, 1, 2], [2, 0, 1]] as $run => $order){
			$this->tearDown();
			$this->setUp();
			$this->registry = $this->newRegistry(new BlockItemIdMap([]));
			$this->ns = "order";
			$ids = ["x", "y", "z"];
			foreach($order as $i){
				$this->registry->register(BlockDefinition::cube($this->id($ids[$i]), strtoupper($ids[$i]), $ids[$i])->states(...match($i){
					0 => [BlockState::bool($this->id("on"))],
					2 => [BlockState::int($this->id("n"), 0, 3)],
					default => []
				}), $this->owner);
			}
			$converter = $this->finalized();
			$snapshot = [];
			foreach($this->registry->getPaletteEntries() as $entry){
				$snapshot[] = str_replace($this->ns, "ns", $entry->getName() . $entry->getStates()->getEncodedNbt());
			}
			$networkIds = [];
			foreach($ids as $path){
				foreach($this->registry->create($this->id($path))->generateStatePermutations() as $state){
					$networkIds[] = $converter->getBlockTranslator()->internalIdToNetworkId($state->getStateId());
				}
			}
			$snapshots[$run] = [$snapshot, count($networkIds)];
			self::assertCount(3, $this->registry->getPaletteEntries());
		}
		self::assertSame($snapshots[0], $snapshots[1]);
	}

	public function testClientCollisionAndSelectionMatchEveryServerState() : void{
		$visual = (new BlockComponents())->geometry("geometry.test")->texture("test");
		$this->registry->register(BlockDefinition::stairs($this->id("stairs"), "Stairs")->withComponents($visual), $this->owner);
		$this->registry->register(BlockDefinition::fence($this->id("fence"), "Fence")->withComponents($visual), $this->owner);
		$this->registry->register(BlockDefinition::cube($this->id("glow"), "Glow", "glow")->states(BlockState::bool($this->id("lit")))->lightLevel(12), $this->owner);
		$this->finalized();
		foreach($this->registry->getPaletteEntries() as $entry){
			$root = self::states($entry);
			$block = $this->registry->create($entry->getName());
			foreach($block->generateStatePermutations() as $state){
				$data = $this->serializer->serialize($state->getStateId());
				$components = self::componentsFor($root, $data);
				$collision = $components["minecraft:collision_box"] ?? null;
				self::assertInstanceOf(CompoundTag::class, $collision);
				$boxes = $collision->getListTag("boxes")?->getValue() ?? [];
				$server = $state->getCollisionBoxes();
				self::assertCount(count($server), $boxes, $entry->getName());
				foreach($server as $k => $box){
					$client = $boxes[$k];
					self::assertInstanceOf(CompoundTag::class, $client);
					self::assertEqualsWithDelta([$box->minX, $box->minY, $box->minZ, $box->maxX, $box->maxY, $box->maxZ], [
						$client->getFloat("minX") / 16, $client->getFloat("minY") / 16, $client->getFloat("minZ") / 16,
						$client->getFloat("maxX") / 16, $client->getFloat("maxY") / 16, $client->getFloat("maxZ") / 16
					], 0.0001);
				}
				self::assertArrayHasKey("minecraft:selection_box", $components);
				$emission = $components["minecraft:light_emission"] ?? null;
				self::assertInstanceOf(CompoundTag::class, $emission);
				self::assertSame($state->getLightLevel(), $emission->getByte("emission"));
			}
		}
	}

	private static function states(BlockPaletteEntry $entry) : CompoundTag{
		$root = $entry->getStates()->getRoot();
		self::assertInstanceOf(CompoundTag::class, $root);
		return $root;
	}

	/**
	 * Applies base components and every permutation whose condition holds, the way the client does.
	 * @return array<string, Tag>
	 */
	private static function componentsFor(CompoundTag $root, BlockStateData $state) : array{
		$components = $root->getCompoundTag("components")?->getValue() ?? [];
		foreach($root->getListTag("permutations")?->getValue() ?? [] as $permutation){
			self::assertInstanceOf(CompoundTag::class, $permutation);
			if(self::evaluate($permutation->getString("condition"), $state)){
				foreach(Utils::stringifyKeys($permutation->getCompoundTag("components")?->getValue() ?? []) as $name => $tag){
					$components[$name] = $tag;
				}
			}
		}
		return $components;
	}

	private static function evaluate(string $condition, BlockStateData $state) : bool{
		if($condition === "true"){
			return true;
		}
		foreach(explode(" || ", $condition, 4096) as $term){
			$all = true;
			foreach(explode(" && ", trim($term, "()"), 64) as $clause){
				self::assertSame(1, preg_match("/^query\\.block_state\\('([^']+)'\\) == (.+)$/", $clause, $m), $clause);
				$tag = $state->getState($m[1]);
				self::assertNotNull($tag);
				$expected = match(true){
					$tag instanceof ByteTag => $tag->getValue() !== 0 ? "true" : "false",
					$tag instanceof IntTag => (string) $tag->getValue(),
					$tag instanceof StringTag => "'" . $tag->getValue() . "'",
					default => self::fail("Unexpected state tag")
				};
				$all = $all && $expected === $m[2];
			}
			if($all){
				return true;
			}
		}
		return false;
	}

	public function testBlockItemsWorkOnDiskNetworkAndInCreative() : void{
		$this->registry->register(BlockDefinition::cube($this->id("item"), "Item Block", "item")->states(BlockState::enum($this->id("tone"), ["dark", "light"])->persistsInItem())->creative(CreativeCategory::CONSTRUCTION), $this->owner);
		$this->registry->register(BlockDefinition::cube($this->id("hidden"), "Hidden", "hidden"), $this->owner);
		$creative = CreativeInventory::getInstance();
		$converter = $this->finalized($creative);
		$itemSerializer = new ItemSerializer($this->serializer);
		$itemDeserializer = new ItemDeserializer($this->deserializer);
		(new ItemRegistry($itemSerializer, $itemDeserializer, $this->parser, $converter->getItemTypeDictionary()))->finalize($converter, $creative, $this->registry->getItemTypeEntries());

		$block = $this->registry->create($this->id("item"));
		self::assertInstanceOf(CustomBlock::class, $block);
		$item = $block->setState($this->id("tone"), "light")->asItem()->setCount(12)->setCustomName("Named");
		self::assertTrue($creative->contains($this->registry->create($this->id("item"))->asItem()));
		self::assertFalse($creative->contains($this->registry->create($this->id("hidden"))->asItem()));

		$saved = $itemSerializer->serializeStack($item);
		self::assertSame($this->id("item"), $saved->getTypeData()->getName());
		self::assertTrue($item->equalsExact($itemDeserializer->deserializeStack($saved)));

		$network = $converter->coreItemStackToNet($item);
		self::assertLessThan(0, $network->getId(), "block items use negative legacy IDs");
		self::assertNotSame(0, $network->getBlockRuntimeId());
		self::assertTrue($item->equalsExact($converter->netItemStackToCore($network)));
		self::assertSame($this->id("item"), $converter->getItemTypeDictionary()->fromIntId($network->getId()));
	}

	public function testMissingAndIncompatibleStatesArePreservedLosslessly() : void{
		$gone = BlockStateData::current($this->id("gone"), [$this->id("level") => new IntTag(3)]);
		$id = $this->deserializer->deserialize($gone);
		$placeholder = RuntimeBlockStateRegistry::getInstance()->fromStateId($id);
		self::assertInstanceOf(UnknownCustomBlock::class, $placeholder);
		self::assertSame($id, $this->deserializer->deserialize($gone), "the same saved state reuses its placeholder");
		self::assertTrue($gone->equals($this->serializer->serialize($id)), "placeholders save their original data");
		self::assertFalse($placeholder->getBreakInfo()->isBreakable());
		self::assertTrue($placeholder->getPickedItem()->isNull());
		self::assertFalse($placeholder->canBePlaced(), "placeholder items must not create unbreakable blocks");
		$converter = $this->converter();
		self::assertTrue($converter->getBlockTranslator()->getFallbackStateData()->equals($converter->getBlockTranslator()->internalIdToNetworkStateData($id)));

		try{
			$this->deserializer->deserialize(BlockStateData::current("minecraft:not_a_block", []));
			self::fail("Unknown vanilla state was preserved");
		}catch(BlockStateDeserializeException){}

		$this->registry->register(BlockDefinition::cube($this->id("gone"), "Gone", "gone")->states(BlockState::int($this->id("level"), 0, 5)), $this->owner);
		$restored = RuntimeBlockStateRegistry::getInstance()->fromStateId($this->deserializer->deserialize($gone));
		self::assertInstanceOf(CustomBlock::class, $restored);
		self::assertSame(3, $restored->getState($this->id("level")));

		//saved with a value the current definition no longer has
		$old = BlockStateData::current($this->id("gone"), [$this->id("level") => new IntTag(9)]);
		$kept = $this->deserializer->deserialize($old);
		self::assertInstanceOf(UnknownCustomBlock::class, RuntimeBlockStateRegistry::getInstance()->fromStateId($kept));
		self::assertTrue($old->equals($this->serializer->serialize($kept)));

		//a renamed property is incompatible too, and kept as-is
		$renamed = BlockStateData::current($this->id("gone"), [$this->id("lvl") => new IntTag(1)]);
		self::assertTrue($renamed->equals($this->serializer->serialize($this->deserializer->deserialize($renamed))));
	}

	public function testDerivedConditionsOnlyTestStatesThatMatter() : void{
		$this->registry->register(BlockDefinition::cube($this->id("glow"), "Glow", "glow")->states(BlockState::bool($this->id("lit")), BlockState::int($this->id("noise"), 0, 7))->withFactory(
			static fn(BlockIdentifier $id, string $name, BlockTypeInfo $info, CustomBlockSettings $settings) => new TestToggleBlock($id, $name, $info, $settings)
		), $this->owner);
		$this->finalized();
		$root = self::states($this->registry->getPaletteEntries()[0]);
		$found = 0;
		foreach($root->getListTag("permutations")?->getValue() ?? [] as $permutation){
			self::assertInstanceOf(CompoundTag::class, $permutation);
			if($permutation->getCompoundTag("components")?->getTag("minecraft:light_emission") !== null){
				++$found;
				self::assertStringNotContainsString("noise", $permutation->getString("condition"));
			}
		}
		self::assertSame(2, $found);
	}

	public function testWorkerLightDataCannotOverrideKnownStates() : void{
		$registry = RuntimeBlockStateRegistry::getInstance();
		$this->registry->register(BlockDefinition::cube($this->id("w"), "W", "w")->lightLevel(9), $this->owner);
		$state = $this->registry->create($this->id("w"))->getStateId();
		$this->expectException(\InvalidArgumentException::class);
		$registry->registerLightData([$state => 1], [$state => 1], []);
	}

	public function testWrongStateTagTypesAreRejected() : void{
		$this->registry->register(BlockDefinition::cube($this->id("bools"), "Bools", "bools")->states(BlockState::bool($this->id("on"))), $this->owner);
		$this->expectException(BlockStateDeserializeException::class);
		$bad = BlockStateData::current($this->id("bools"), [$this->id("on") => new StringTag("yes")]);
		$this->deserializer->deserializeBlock($bad);
	}

	public function testFacingRotatesDirectionalModels() : void{
		$this->registry->register(BlockDefinition::directional($this->id("dir"), "Dir", "dir"), $this->owner);
		$block = $this->registry->create($this->id("dir"));
		self::assertInstanceOf(CustomBlock::class, $block);
		self::assertSame("north", $block->getState($this->id("facing")));
		self::assertSame("south", CustomBlock::facingName(Facing::SOUTH));
		$this->finalized();
		$root = self::states($this->registry->getPaletteEntries()[0]);
		$turns = [];
		foreach($root->getListTag("permutations")?->getValue() ?? [] as $permutation){
			self::assertInstanceOf(CompoundTag::class, $permutation);
			$transform = $permutation->getCompoundTag("components")?->getCompoundTag("minecraft:transformation");
			if($transform !== null){
				self::assertSame(1, preg_match("/== '([a-z]+)'$/", $permutation->getString("condition"), $m));
				$turns[$m[1]] = $transform->getInt("RY");
			}
		}
		self::assertSame(["west" => 1, "south" => 2, "east" => 3], $turns);
	}

	public function testVanillaPaletteIsUntouchedWithoutCustomBlocks() : void{
		$converter = $this->converter();
		$before = $converter->getBlockTranslator();
		$this->registry->finalize($converter, CreativeInventory::getInstance());
		self::assertSame($before, $converter->getBlockTranslator());
		self::assertSame([], $this->registry->getPaletteEntries());
	}
}

class TestSafeTile extends Tile{
	public function readSaveData(CompoundTag $nbt) : void{}

	protected function writeSaveData(CompoundTag $nbt) : void{}
}

class TestToggleBlock extends CustomBlock{
	public function getLightLevel() : int{
		$lit = BlockDefinition::stateName($this->getSettings()->states[0]->getName(), "lit");
		return isset($this->getSettings()->stateIndexes[$lit]) && $this->getState($lit) === true ? 15 : 0;
	}
}
