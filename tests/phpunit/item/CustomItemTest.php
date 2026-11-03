<?php

declare(strict_types=1);

namespace quark\item;

use Bedwire\Nbt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\color\Color;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use quark\block\BlockToolType;
use quark\block\VanillaBlocks;
use quark\data\bedrock\item\ItemDeserializer;
use quark\data\bedrock\item\ItemSerializer;
use quark\data\bedrock\item\ItemTypeDeserializeException;
use quark\data\bedrock\item\SavedItemData;
use quark\data\bedrock\item\SavedItemStackData;
use quark\inventory\ArmorInventory;
use quark\inventory\CreativeInventory;
use quark\item\custom\CustomSword;
use quark\item\custom\ItemComponents;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\item\custom\UnknownCustomItem;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
use quark\network\mcpe\convert\TypeConversionException;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\ItemRegistryPacket;
use quark\network\mcpe\protocol\PacketDecodeException;
use quark\network\mcpe\protocol\serializer\ItemTypeDictionary;
use quark\network\mcpe\protocol\types\CacheableNbt;
use quark\network\mcpe\protocol\types\inventory\ItemStack;
use quark\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use quark\network\mcpe\protocol\types\ItemTypeEntry;
use quark\plugin\Plugin;
use quark\world\format\io\GlobalBlockStateHandlers;
use function bin2hex;
use function str_repeat;
use const NAN;

final class CustomItemTest extends TestCase{
	private ItemRegistry $registry;
	private ItemSerializer $serializer;
	private ItemDeserializer $deserializer;
	private TypeConverter $converter;
	private StringToItemParser $parser;
	private Plugin $owner;

	protected function setUp() : void{
		$this->serializer = new ItemSerializer(GlobalBlockStateHandlers::getSerializer());
		$this->deserializer = new ItemDeserializer(GlobalBlockStateHandlers::getDeserializer());
		$this->converter = new TypeConverter($this->serializer, $this->deserializer);
		$this->parser = new StringToItemParser();
		$this->registry = new ItemRegistry($this->serializer, $this->deserializer, $this->parser, $this->converter->getItemTypeDictionary());
		$this->owner = $this->createMock(Plugin::class);
		$this->owner->method("getName")->willReturn("TestPlugin");
	}

	private function registerSword() : CustomSword{
		$this->registry->register(ItemDefinition::sword("test:ruby_sword", "Ruby Sword", 9, 100), $this->owner);
		$item = $this->registry->create("test:ruby_sword");
		self::assertInstanceOf(CustomSword::class, $item);
		return $item;
	}

	public function testFactoryRunsOnceAndStacksAreIndependent() : void{
		$calls = 0;
		$definition = ItemDefinition::fromFactory("test:sword", "Sword", static function(ItemIdentifier $id) use (&$calls) : Item{
			++$calls;
			return new CustomSword($id, "Sword", 9, 100);
		});
		$this->registry->register($definition, $this->owner);
		$first = $this->registry->create("test:sword");
		$nextTypeId = ItemTypeIds::newId();
		for($i = 0; $i < 100; ++$i){
			$second = $this->registry->create("test:sword");
			self::assertNotSame($first, $second);
			self::assertSame($first->getTypeId(), $second->getTypeId());
		}
		self::assertSame($nextTypeId + 1, ItemTypeIds::newId());
		$first->setCustomName("Owned")->setLore(["Private"]);
		$first->getNamedTag()->setInt("private", 1);
		self::assertFalse($this->registry->create("test:sword")->hasCustomName());
		self::assertSame([], $this->registry->create("test:sword")->getLore());
		self::assertSame(1, $calls);
		self::assertTrue($this->parser->parse("test:sword")?->equalsExact($this->registry->create("test:sword")));
	}

	public function testFactoryClosureIsNotRetained() : void{
		$captured = new \stdClass();
		$reference = \WeakReference::create($captured);
		$definition = ItemDefinition::fromFactory("test:item", "Item", static function(ItemIdentifier $id) use ($captured) : Item{
			$captured->factoryCalled = true;
			return new Item($id, "Item");
		});
		$this->registry->register($definition, $this->owner);
		unset($definition, $captured);
		self::assertNull($reference->get());
	}

	public function testDuplicatesAndNamespaceOwnershipDoNotReplaceMappings() : void{
		$original = $this->registerSword();
		try{
			$this->registry->register(ItemDefinition::item("test:ruby_sword", "Other"), $this->owner);
			self::fail("Duplicate accepted");
		}catch(\InvalidArgumentException){ }
		self::assertTrue($original->equalsExact($this->registry->create("test:ruby_sword")));
		$other = $this->createMock(Plugin::class);
		$other->method("getName")->willReturn("OtherPlugin");
		$this->expectException(\InvalidArgumentException::class);
		$this->registry->register(ItemDefinition::item("test:other", "Other"), $other);
	}

	public function testConflictingAliasesAreNotInvokedDuringRegistration() : void{
		$calls = 0;
		$this->parser->register("test:item", static function() use (&$calls) : Item{
			if(++$calls > 1){ throw new \RuntimeException("Alias must not be evaluated when checking for conflicts"); }
			return VanillaItems::STICK();
		});
		$this->expectException(\InvalidArgumentException::class);
		$this->registry->register(ItemDefinition::item("test:item", "Item"), $this->owner);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidIdentifiers() : iterable{
		foreach(["sword", "Test:sword", ":sword", "test:", "test:a b", "test:../x", "test:a\0b", "test:" . str_repeat("x", 128)] as $id){ yield bin2hex($id) => [$id]; }
	}

	#[DataProvider('invalidIdentifiers')]
	public function testInvalidIdentifier(string $identifier) : void{
		$this->expectException(\InvalidArgumentException::class);
		ItemDefinition::item($identifier, "Item");
	}

	public function testVanillaNamespaceIsReserved() : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->registry->register(ItemDefinition::item("minecraft:diamond_sword", "Sword"), $this->owner);
	}

	public function testInvalidFactoryLeavesNoRegistration() : void{
		try{
			$this->registry->register(ItemDefinition::fromFactory("test:item", "Item", static fn() => VanillaItems::DIAMOND_SWORD()), $this->owner);
			self::fail("Invalid factory accepted");
		}catch(\InvalidArgumentException){ }
		self::assertFalse($this->registry->isRegistered("test:item"));
		self::assertNull($this->parser->parse("test:item"));
		self::assertNull($this->deserializer->getDeserializerForId("test:item"));
		$this->registry->register(ItemDefinition::item("test:item", "Item"), $this->owner);
		self::assertSame("Item", $this->registry->create("test:item")->getName());
	}

	public function testReentrantFactoryCannotPartiallyRegister() : void{
		$definition = ItemDefinition::fromFactory("test:outer", "Outer", function(ItemIdentifier $id) : Item{
			$this->registry->register(ItemDefinition::item("test:inner", "Inner"), $this->owner);
			return new Item($id, "Outer");
		});
		try{
			$this->registry->register($definition, $this->owner);
			self::fail("Reentrant factory accepted");
		}catch(\InvalidArgumentException){ }
		self::assertSame([], $this->registry->getIdentifiers());
		$this->registry->register(ItemDefinition::item("test:inner", "Inner"), $this->owner);
		self::assertTrue($this->registry->isRegistered("test:inner"));
	}

	public function testCustomIdentifierCannotBeDecodedAsBlock() : void{
		$this->registerSword();
		$blockData = $this->serializer->serializeType(VanillaBlocks::STONE()->asItem())->getBlock();
		self::assertNotNull($blockData);
		$this->expectException(ItemTypeDeserializeException::class);
		$this->deserializer->deserializeType(new SavedItemData("test:ruby_sword", 0, $blockData));
	}

	/** @return iterable<string, array{ItemDefinition}> */
	public static function invalidDefinitions() : iterable{
		yield "zero stack" => [ItemDefinition::item("test:item", "Item", 0)];
		yield "oversized stack" => [ItemDefinition::item("test:item", "Item", 65)];
		yield "negative damage" => [ItemDefinition::sword("test:item", "Item", -1, 100)];
		yield "zero durability" => [ItemDefinition::sword("test:item", "Item", 5, 0)];
		yield "oversized durability" => [ItemDefinition::sword("test:item", "Item", 5, 32768)];
		yield "excess protection" => [ItemDefinition::armor("test:item", "Item", new ArmorTypeInfo(21, 100, 0))];
		yield "nonfinite saturation" => [ItemDefinition::food("test:item", "Item", 4, NAN)];
		yield "projectile without server behavior" => [ItemDefinition::item("test:item", "Item")->withComponents((new ItemComponents())->projectile("minecraft:arrow"))];
		yield "dye without armor" => [ItemDefinition::item("test:item", "Item")->withComponents((new ItemComponents())->dyeable())];
		yield "repair without durability" => [ItemDefinition::item("test:item", "Item")->withComponents((new ItemComponents())->repairable(["minecraft:diamond"]))];
		yield "use without behavior" => [ItemDefinition::item("test:item", "Item")->withComponents((new ItemComponents())->useAnimation(custom\UseAnimation::EAT))];
	}

	#[DataProvider('invalidDefinitions')]
	public function testInvalidDefinition(ItemDefinition $definition) : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->registry->register($definition, $this->owner);
	}

	public function testPersistentAndNetworkRoundTripsPreserveStackData() : void{
		$item = $this->registerSword();
		$item->setDamage(42);
		$item->setCustomName("Named")->setLore(["One", "Two"])->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
		$item->getNamedTag()->setString("plugin_data", "value");
		$saved = $this->serializer->serializeStack($item);
		self::assertSame("test:ruby_sword", $saved->getTypeData()->getName());
		self::assertTrue($item->equalsExact($this->deserializer->deserializeStack($saved)));
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		$network = $this->converter->coreItemStackToNet($item);
		self::assertNotSame($item->getTypeId(), $network->getId());
		self::assertTrue($item->equalsExact($this->converter->netItemStackToCore($network)));
		$vanilla = VanillaItems::DIAMOND_SWORD()->setDamage(19);
		self::assertTrue($vanilla->equalsExact($this->converter->netItemStackToCore($this->converter->coreItemStackToNet($vanilla))));
	}

	public function testSnapshotComponentsAreDerivedAndImmutable() : void{
		$this->registerSword();
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		$packet = $this->converter->getItemRegistryPacket();
		self::assertSame($packet, $this->converter->getItemRegistryPacket());
		$decoded = $packet->getPacket();
		self::assertInstanceOf(ItemRegistryPacket::class, $decoded);
		$found = false;
		foreach($decoded->itemData as $entry){
			if($entry->itemName !== "test:ruby_sword"){ continue; }
			$found = true;
			$root = Nbt::read($entry->itemComponentData, Nbt::NETWORK)->mustGetCompoundTag();
			self::assertSame($entry->itemId, $root->getInt("id"));
			self::assertSame(8, $root->getCompoundTag("components")?->getCompoundTag("item_properties")?->getInt("damage"));
			self::assertSame(100, $root->getCompoundTag("components")?->getCompoundTag("minecraft:durability")?->getInt("max_durability"));
		}
		self::assertTrue($found);
		$decoded->itemData = [];
		$anotherCopy = $packet->getPacket();
		self::assertInstanceOf(ItemRegistryPacket::class, $anotherCopy);
		self::assertNotEmpty($anotherCopy->itemData);
		$this->expectException(\LogicException::class);
		$this->registry->register(ItemDefinition::item("test:late", "Late"), $this->owner);
	}

	public function testSnapshotOrderAndPersistenceAreIndependentOfRegistrationOrder() : void{
		$this->registry->register(ItemDefinition::item("test:z", "Z"), $this->owner);
		$this->registry->register(ItemDefinition::item("test:a", "A"), $this->owner);
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		self::assertLessThan($this->converter->getItemTypeDictionary()->fromStringId("test:z"), $this->converter->getItemTypeDictionary()->fromStringId("test:a"));
		$saved = $this->serializer->serializeStack($this->registry->create("test:z"));
		$this->setUp();
		$this->registry->register(ItemDefinition::item("test:a", "A"), $this->owner);
		$this->registry->register(ItemDefinition::item("test:z", "Z"), $this->owner);
		self::assertTrue($this->registry->create("test:z")->equalsExact($this->deserializer->deserializeStack($saved)));
	}

	public function testMissingPluginPreservesOpaqueDataWithoutAbilities() : void{
		$data = new SavedItemStackData(new SavedItemData("removed:helmet", 0, null, CompoundTag::create()->setInt("Damage", 80)->setString("custom", "keep")), 1, null, null, [], []);
		$item = $this->deserializer->deserializeStack($data);
		self::assertInstanceOf(UnknownCustomItem::class, $item);
		self::assertSame(0, $item->getDefensePoints());
		$saved = $this->serializer->serializeStack($item);
		self::assertSame("removed:helmet", $saved->getTypeData()->getName());
		$savedTag = $saved->getTypeData()->getTag();
		self::assertNotNull($savedTag);
		self::assertTrue($data->getTypeData()->getTag()?->equals($savedTag));
		self::assertNotSame(0, $this->converter->coreItemStackToNet($item)->getId());
		$this->registry->register(ItemDefinition::armor("removed:helmet", "Helmet", new ArmorTypeInfo(3, 100, 0)), $this->owner);
		$restored = $this->deserializer->deserializeStack($saved);
		self::assertInstanceOf(Armor::class, $restored);
		self::assertSame(80, $restored->getDamage());
	}

	public function testMissingPluginPreservesUnknownEnchantmentsWithoutActivatingThem() : void{
		$tag = CompoundTag::create()->setTag("ench", new ListTag([CompoundTag::create()->setShort("id", 30000)->setShort("lvl", 7)]));
		$item = $this->deserializer->deserializeStack(new SavedItemStackData(new SavedItemData("removed:enchanted", 0, null, $tag), 1, null, null, [], []));
		self::assertFalse($item->hasEnchantments());
		$savedTag = $this->serializer->serializeStack($item)->getTypeData()->getTag();
		self::assertNotNull($savedTag);
		self::assertTrue($tag->equals($savedTag));
	}

	public function testFoodToolAndComponentsUseServerProperties() : void{
		$components = (new ItemComponents())->icon("test:apple")->rarity("epic")->glint()->tags("test:fruit");
		$this->registry->register(ItemDefinition::food("test:apple", "Apple", 4, 2.4)->withComponents($components)->withCreativeCategory(), $this->owner);
		$this->registry->register(ItemDefinition::tool("test:pickaxe", "Pickaxe", BlockToolType::PICKAXE, ToolTier::IRON)->withComponents((new ItemComponents())->digger("minecraft:stone")), $this->owner);
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		$food = $this->registry->create("test:apple", 64);
		self::assertInstanceOf(Food::class, $food);
		self::assertSame(4, $food->getFoodRestore());
		self::assertSame(2.4, $food->getSaturationRestore());
		self::assertInstanceOf(Pickaxe::class, $this->registry->create("test:pickaxe"));
		self::assertTrue(CreativeInventory::getInstance()->contains($food->setCount(1)));
		foreach($this->converter->getItemTypeDictionary()->getEntries() as $entry){
			if($entry->getStringId() !== "test:apple"){ continue; }
			$root = $entry->getComponentNbt()->getRoot();
			self::assertEqualsWithDelta(0.3, $root->getCompoundTag("components")?->getCompoundTag("minecraft:food")?->getFloat("saturation_modifier"), 0.00001);
		}
	}

	public function testInvalidDurabilityTagIsRejectedFromNetwork() : void{
		$this->registerSword();
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		$writer = new ByteBufferWriter();
		(new ItemStackExtraData(CompoundTag::create()->setString("Damage", "invalid"), [], []))->write($writer);
		$this->expectException(TypeConversionException::class);
		$this->converter->netItemStackToCore(new ItemStack($this->converter->getItemTypeDictionary()->fromStringId("test:ruby_sword"), 0, 1, 0, $writer->getData()));
	}

	public function testMalformedNetworkNbtIsRejected() : void{
		$this->registerSword();
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		$this->expectException(PacketDecodeException::class);
		$this->converter->netItemStackToCore(new ItemStack($this->converter->getItemTypeDictionary()->fromStringId("test:ruby_sword"), 0, 1, 0, "\xff\xff\x02"));
	}

	/** @return iterable<string, array{int, int}> */
	public static function invalidStacks() : iterable{
		yield "negative count" => [-1, 0]; yield "zero count" => [0, 0]; yield "stacked weapon" => [2, 0];
		yield "negative durability" => [1, -1]; yield "broken durability" => [1, 100]; yield "overflow durability" => [1, 2147483647];
	}

	#[DataProvider('invalidStacks')]
	public function testInvalidStackData(int $count, int $damage) : void{
		$this->registerSword();
		$this->expectException(ItemTypeDeserializeException::class);
		$this->deserializer->deserializeStack(new SavedItemStackData(new SavedItemData("test:ruby_sword", 0, null, CompoundTag::create()->setInt("Damage", $damage)), $count, null, null, [], []));
	}

	public function testInvalidNetworkIdAndMetadata() : void{
		$this->registerSword();
		$this->registry->finalize($this->converter, CreativeInventory::getInstance());
		foreach([[-30000, 0], [$this->converter->getItemTypeDictionary()->fromStringId("test:ruby_sword"), 1]] as [$id, $meta]){
			try{
				$this->converter->getItemTranslator()->fromNetworkId($id, $meta, 0);
				self::fail("Invalid network ID/meta accepted");
			}catch(TypeConversionException){ }
		}
		$valid = $this->converter->coreItemStackToNet($this->registry->create("test:ruby_sword"));
		$this->expectException(TypeConversionException::class);
		$this->converter->netItemStackToCore(new ItemStack($valid->getId(), 0, 2, 0, $valid->getRawExtraData()));
	}

	public function testArmorSlotsDyeEnchantmentsAndBreakage() : void{
		foreach([ArmorInventory::SLOT_HEAD, ArmorInventory::SLOT_CHEST, ArmorInventory::SLOT_LEGS, ArmorInventory::SLOT_FEET] as $slot){
			$id = "test:armor_$slot";
			$this->registry->register(ItemDefinition::armor($id, "Armor", new ArmorTypeInfo(3, 100, $slot, material: VanillaArmorMaterials::DIAMOND())), $this->owner);
			$item = $this->registry->create($id);
			self::assertInstanceOf(Armor::class, $item);
			self::assertSame($slot, $item->getArmorSlot());
			self::assertInstanceOf(\quark\block\tile\dispenser\ArmorDispensableItem::class, \quark\block\tile\dispenser\DispensableItemManager::get($item));
			$item->setCustomColor(new Color(255, 0, 0));
			$item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 2));
			self::assertTrue($item->equalsExact($this->deserializer->deserializeStack($this->serializer->serializeStack($item))));
			$item->setDamage(99);
			$item->applyDamage(1);
			self::assertTrue($item->isNull());
		}
	}

	public function testComponentSnapshotsCannotBeMutatedThroughNbt() : void{
		$nbt = CompoundTag::create()->setInt("value", 1);
		$cache = new CacheableNbt($nbt);
		$nbt->setInt("value", 2);
		$cache->getRoot()->setInt("value", 3);
		self::assertSame(1, $cache->getRoot()->getInt("value"));
		self::assertSame(1, Nbt::read($cache->getEncodedNbt(), Nbt::NETWORK)->mustGetCompoundTag()->getInt("value"));
	}

	public function testDictionaryRejectsRuntimeCollisions() : void{
		$nbt = new CacheableNbt(new CompoundTag());
		$this->expectException(\InvalidArgumentException::class);
		new ItemTypeDictionary([new ItemTypeEntry("test:a", 10000, true, 1, $nbt), new ItemTypeEntry("test:b", 10000, true, 1, $nbt)]);
	}
}
