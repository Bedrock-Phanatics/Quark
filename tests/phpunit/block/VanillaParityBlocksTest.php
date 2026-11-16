<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\block;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use quark\block\tile\Beehive as TileBeehive;
use quark\block\tile\CommandBlock as TileCommandBlock;
use quark\block\tile\Crafter as TileCrafter;
use quark\block\tile\DecoratedPot as TileDecoratedPot;
use quark\block\tile\Shelf as TileShelf;
use quark\block\tile\StructureBlock as TileStructureBlock;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\block\utils\FrontAndTop;
use quark\data\bedrock\block\BlockStateNames;
use quark\data\bedrock\block\BlockStateSerializeException;
use quark\item\ItemBlock;
use quark\item\StringToItemParser;
use quark\item\VanillaItems;
use quark\math\Facing;
use quark\network\mcpe\convert\TypeConverter;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\world\format\io\GlobalItemDataHandlers;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function count;
use function random_bytes;
use function sys_get_temp_dir;

final class VanillaParityBlocksTest extends TestCase{
	private const BLOCK_IDS = [
		"allow", "deny", "border_block", "honey_block", "dripstone_block", "moss_block", "pale_moss_block", "lodestone",
		"target", "powder_snow", "conduit", "end_portal", "end_gateway", "moving_block", "frog_spawn", "bubble_column",
		"bush", "firefly_bush", "short_dry_grass", "tall_dry_grass", "open_eyeblossom", "closed_eyeblossom",
		"golden_dandelion", "cherry_sapling", "pale_oak_sapling", "mangrove_propagule", "kelp", "seagrass", "moss_carpet",
		"pale_moss_carpet", "pale_hanging_moss", "sculk_vein", "pointed_dripstone", "leaf_litter", "wildflowers",
		"creaking_heart", "grindstone", "composter", "scaffolding", "sniffer_egg", "turtle_egg", "observer",
		"command_block", "chain_command_block", "repeating_command_block", "structure_block", "jigsaw", "dropper",
		"crafter", "decorated_pot", "sculk_sensor", "calibrated_sculk_sensor", "sculk_catalyst", "sculk_shrieker",
		"suspicious_sand", "suspicious_gravel", "trial_spawner", "vault", "bee_nest", "beehive", "oak_shelf",
		"spruce_shelf", "birch_shelf", "jungle_shelf", "acacia_shelf", "dark_oak_shelf", "mangrove_shelf",
		"crimson_shelf", "warped_shelf", "cherry_shelf", "pale_oak_shelf", "bamboo_shelf", "poplar_shelf",
		"copper_chest", "exposed_copper_chest", "weathered_copper_chest", "oxidized_copper_chest", "waxed_copper_chest",
		"waxed_exposed_copper_chest", "waxed_weathered_copper_chest", "waxed_oxidized_copper_chest",
		"sticky_piston_arm_collision",
	];

	public function testEveryVanillaStateRoundTrips() : void{
		$wanted = [];
		foreach(self::BLOCK_IDS as $id){
			$wanted["minecraft:$id"] = 0;
		}
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		foreach(TypeConverter::getInstance()->getBlockTranslator()->getBlockStateDictionary()->getStates() as $entry){
			$data = $entry->generateStateData();
			if(!isset($wanted[$data->getName()])){
				continue;
			}
			$wanted[$data->getName()]++;
			$growth = $data->getState(BlockStateNames::GROWTH);
			if($growth instanceof IntTag && $growth->getValue() > 3){
				//wildflowers and leaf litter only use growth 0-3, the rest collapse into 3 like pink petals
				continue;
			}
			$block = $deserializer->deserializeBlock($data);
			try{
				$roundTrip = $serializer->serializeBlock($block);
			}catch(BlockStateSerializeException $e){
				self::fail("Failed to serialize " . $data->getName() . ": " . $e->getMessage());
			}
			self::assertTrue($data->equals($roundTrip), "Mismatch for " . $data->toNbt() . " vs " . $roundTrip->toNbt());
		}
		foreach($wanted as $id => $count){
			self::assertGreaterThan(0, $count, "$id is not in the vanilla palette");
		}
	}

	public function testBlockItemsRoundTrip() : void{
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();
		foreach(VanillaBlocks::getAll() as $name => $block){
			if($block->getTypeId() < BlockTypeIds::ALLOW && $block->getTypeId() !== BlockTypeIds::CHERRY_SAPLING){
				continue;
			}
			$item = $block->asItem();
			$data = $serializer->serializeType($item);
			$back = $deserializer->deserializeType($data);
			self::assertTrue($item->equals($back, true, false), "Item round trip failed for $name");
		}
		self::assertSame("minecraft:kelp", $serializer->serializeType(VanillaBlocks::KELP()->asItem())->getName());
	}

	public function testStringAliases() : void{
		$parser = StringToItemParser::getInstance();
		foreach(["beehive" => VanillaBlocks::BEEHIVE(), "crafter" => VanillaBlocks::CRAFTER(), "bush" => VanillaBlocks::BUSH(), "frogspawn" => VanillaBlocks::FROG_SPAWN()] as $alias => $block){
			self::assertTrue($parser->parse($alias)?->equals($block->asItem(), true, false) ?? false, $alias);
		}
		$chest = $parser->parse("waxed_oxidized_copper_chest");
		self::assertInstanceOf(ItemBlock::class, $chest);
		$copper = $chest->getBlock();
		self::assertInstanceOf(CopperChest::class, $copper);
		self::assertTrue($copper->isWaxed());
	}

	public function testFrontAndTopRoundTrip() : void{
		foreach(FrontAndTop::cases() as $case){
			self::assertSame($case, FrontAndTop::fromFacing($case->getFront(), $case->getTop()));
		}
		$this->expectException(\InvalidArgumentException::class);
		FrontAndTop::fromFacing(Facing::UP, Facing::DOWN);
	}

	public function testSaplingsWithoutTreesNeverTick() : void{
		self::assertFalse(VanillaBlocks::CHERRY_SAPLING()->ticksRandomly());
		self::assertFalse(VanillaBlocks::PALE_OAK_SAPLING()->ticksRandomly());
		self::assertTrue(VanillaBlocks::OAK_SAPLING()->ticksRandomly());
	}

	private static function reload(Tile $tile) : Tile{
		$copy = TileFactory::getInstance()->createFromData($tile->saveNBT());
		self::assertInstanceOf($tile::class, $copy);
		return $copy;
	}

	public function testRetainedTilesKeepKnownDataOnly() : void{
		$occupants = new ListTag([CompoundTag::create()->setString("ActorIdentifier", "minecraft:bee<>")]);
		$nbt = CompoundTag::create()
			->setString(Tile::TAG_ID, "Beehive")
			->setTag("Occupants", $occupants)
			->setByte("ShouldSpawnBees", 1)
			->setString("SomethingElse", "dropped");
		$tile = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(TileBeehive::class, $tile);

		$saved = self::reload($tile)->saveNBT();
		self::assertTrue($occupants->equals($saved->getListTag("Occupants") ?? new ListTag()));
		self::assertSame(1, $saved->getByte("ShouldSpawnBees"));
		self::assertNull($saved->getTag("SomethingElse"));

		$tile = TileFactory::getInstance()->createFromData(CompoundTag::create()
			->setString(Tile::TAG_ID, "CommandBlock")
			->setString("Command", "say hi")
			->setString("TickDelay", "not an int")
		);
		self::assertInstanceOf(TileCommandBlock::class, $tile);
		$saved = self::reload($tile)->saveNBT();
		self::assertSame("say hi", $saved->getString("Command"));
		self::assertNull($saved->getTag("TickDelay"), "wrongly typed tags must be dropped");

		$tile = TileFactory::getInstance()->createFromData(CompoundTag::create()
			->setString(Tile::TAG_ID, "StructureBlock")
			->setInt("xStructureSize", 7)
			->setString("structureName", "mystructure:a")
		);
		self::assertInstanceOf(TileStructureBlock::class, $tile);
		$saved = self::reload($tile)->saveNBT();
		self::assertSame(7, $saved->getInt("xStructureSize"));
		self::assertSame("mystructure:a", $saved->getString("structureName"));
	}

	public function testAllNewTileSaveIdsAreRegistered() : void{
		foreach(["Beehive", "BrushableBlock", "CalibratedSculkSensor", "CommandBlock", "Conduit", "Crafter", "DecoratedPot",
			"Dropper", "EndGateway", "EndPortal", "JigsawBlock", "Lodestone", "MovingBlock", "SculkCatalyst", "SculkSensor",
			"SculkShrieker", "Shelf", "StructureBlock", "TrialSpawner", "Vault"] as $id){
			$tile = TileFactory::getInstance()->createFromData(CompoundTag::create()->setString(Tile::TAG_ID, $id));
			self::assertNotNull($tile, $id);
			self::assertSame($id, $tile->saveNBT()->getString(Tile::TAG_ID));
		}
	}

	public function testCrafterKeepsContents() : void{
		$tile = new TileCrafter();
		$tile->getInventory()->setItem(4, VanillaItems::DIAMOND()->setCount(3));
		$copy = self::reload($tile);
		self::assertInstanceOf(TileCrafter::class, $copy);
		self::assertTrue($copy->getInventory()->getItem(4)->equalsExact(VanillaItems::DIAMOND()->setCount(3)));
	}

	public function testDecoratedPotData() : void{
		$sherds = new ListTag([new StringTag("minecraft:angler_pottery_sherd"), new StringTag("minecraft:brick"), new StringTag(""), new StringTag("")]);
		$tile = TileFactory::getInstance()->createFromData(CompoundTag::create()
			->setString(Tile::TAG_ID, "DecoratedPot")
			->setTag(TileDecoratedPot::TAG_SHERDS, $sherds)
			->setTag("item", VanillaItems::EMERALD()->setCount(2)->nbtSerialize())
		);
		self::assertInstanceOf(TileDecoratedPot::class, $tile);
		$copy = self::reload($tile);
		self::assertInstanceOf(TileDecoratedPot::class, $copy);
		self::assertTrue($sherds->equals($copy->getSherds() ?? new ListTag()));
		self::assertTrue($copy->getItem()->equalsExact(VanillaItems::EMERALD()->setCount(2)));

		//a pot item must never bring contents with it, or picking and placing would duplicate them
		$item = VanillaBlocks::DECORATED_POT()->asItem();
		$item->setCustomBlockData(CompoundTag::create()
			->setTag(TileDecoratedPot::TAG_SHERDS, $sherds)
			->setTag("item", VanillaItems::DIAMOND()->nbtSerialize())
		);
		$placed = new TileDecoratedPot();
		$placed->copyDataFromItem($item);
		self::assertTrue($placed->getItem()->isNull());
		self::assertTrue($sherds->equals($placed->getSherds() ?? new ListTag()));

		$malformed = TileFactory::getInstance()->createFromData(CompoundTag::create()
			->setString(Tile::TAG_ID, "DecoratedPot")
			->setTag(TileDecoratedPot::TAG_SHERDS, new ListTag([new IntTag(1)]))
		);
		self::assertInstanceOf(TileDecoratedPot::class, $malformed);
		self::assertNull($malformed->getSherds());
	}

	public function testShelfItems() : void{
		$diamond = VanillaItems::DIAMOND()->nbtSerialize();
		$empty = CompoundTag::create()->setByte("Count", 0)->setShort("Damage", 0)->setString("Name", "");
		//positional list without slot tags, an out of range slot and a valid explicit slot
		$tile = TileFactory::getInstance()->createFromData(CompoundTag::create()
			->setString(Tile::TAG_ID, "Shelf")
			->setTag("Items", new ListTag([$empty, $diamond, VanillaItems::EMERALD()->nbtSerialize()->setByte("Slot", 9)], NBT::TAG_Compound))
		);
		self::assertInstanceOf(TileShelf::class, $tile);
		self::assertTrue($tile->getInventory()->getItem(1)->equalsExact(VanillaItems::DIAMOND()));
		self::assertSame(1, count($tile->getInventory()->getContents()));

		$copy = self::reload($tile);
		self::assertInstanceOf(TileShelf::class, $copy);
		self::assertTrue($copy->getInventory()->getItem(1)->equalsExact(VanillaItems::DIAMOND()));
		$items = $copy->saveNBT()->getListTag("Items");
		self::assertNotNull($items);
		self::assertSame(TileShelf::SLOTS, $items->count());
	}

	public function testWorldSaveAndReload() : void{
		$states = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state){
			if($state->getTypeId() >= BlockTypeIds::ALLOW){
				$states[] = $state->getStateId();
			}
		}
		$chunk = new Chunk([], true);
		foreach($states as $i => $stateId){
			$chunk->setBlockStateId($i & 0xf, ($i >> 8) - 64, ($i >> 4) & 0xf, $stateId);
		}
		$pot = new TileDecoratedPot();
		$pot->setItem(VanillaItems::APPLE());
		$tileNbt = $pot->saveNBT()->setInt(Tile::TAG_X, 1)->setInt(Tile::TAG_Y, -64)->setInt(Tile::TAG_Z, 0);

		$path = Path::join(sys_get_temp_dir(), "quark-vanilla-parity-" . bin2hex(random_bytes(6)));
		LevelDB::generate($path, "test", WorldCreationOptions::create());
		try{
			$provider = new LevelDB($path, new \PrefixedLogger(\GlobalLogger::get(), "VanillaParityBlocksTest"));
			$provider->saveChunk(0, 0, new ChunkData($chunk->getSubChunks(), true, [], [$tileNbt]), Chunk::DIRTY_FLAGS_ALL);
			$provider->close();

			$provider = new LevelDB($path, new \PrefixedLogger(\GlobalLogger::get(), "VanillaParityBlocksTest"));
			$loaded = $provider->loadChunk(0, 0);
			$provider->close();
		}finally{
			Filesystem::recursiveUnlink($path);
		}
		self::assertNotNull($loaded);
		$data = $loaded->getData();
		$subChunks = $data->getSubChunks();
		foreach($states as $i => $stateId){
			$y = ($i >> 8) - 64;
			self::assertSame($stateId, $subChunks[$y >> 4]->getBlockStateId($i & 0xf, $y & 0xf, ($i >> 4) & 0xf));
		}
		self::assertCount(1, $data->getTileNBT());
		$reloaded = TileFactory::getInstance()->createFromData($data->getTileNBT()[0]);
		self::assertInstanceOf(TileDecoratedPot::class, $reloaded);
		self::assertTrue($reloaded->getItem()->equalsExact(VanillaItems::APPLE()));
	}

	public function testPlacementStateDefaults() : void{
		self::assertTrue(VanillaBlocks::SUSPICIOUS_SAND()->isHanging());
		self::assertSame(FrontAndTop::DOWN_EAST, VanillaBlocks::CRAFTER()->getOrientation());
		self::assertSame(Facing::SOUTH, VanillaBlocks::BEEHIVE()->getFacing());
		self::assertInstanceOf(ByteTag::class, GlobalBlockStateHandlers::getSerializer()->serializeBlock(VanillaBlocks::OBSERVER())->getState(BlockStateNames::POWERED_BIT));
	}
}
