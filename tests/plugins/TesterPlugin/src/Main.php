<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
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

namespace quarktests\TesterPlugin;

use pocketmine\nbt\tag\CompoundTag;
use quark\block\custom\BlockDefinition;
use quark\block\custom\BlockRegistry;
use quark\block\custom\CustomBlock;
use quark\block\Door;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\Slab;
use quark\block\utils\SlabType;
use quark\block\VanillaBlocks;
use quark\event\Listener;
use quark\event\server\CommandEvent;
use quark\inventory\CreativeInventory;
use quark\item\ArmorTypeInfo;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\item\Item;
use quark\math\AxisAlignedBB;
use quark\network\mcpe\cache\StaticPacketCache;
use quark\network\mcpe\convert\TypeConverter;
use quark\plugin\PluginBase;
use quark\plugin\ScriptPluginLoader;
use quark\scheduler\CancelTaskException;
use quark\scheduler\ClosureTask;
use quark\world\Explosion;
use quark\world\Position;
use function array_shift;
use function count;

class Main extends PluginBase implements Listener{

	/** @var Test[] */
	protected $waitingTests = [];
	/** @var Test|null */
	protected $currentTest = null;
	/** @var Test[] */
	protected $completedTests = [];
	/** @var int */
	protected $currentTestNumber = 0;

	public function onEnable() : void{
		$registry = ItemRegistry::getInstance();
		$registry->register(ItemDefinition::armor("tester:helmet", "Test Helmet", new ArmorTypeInfo(3, 100, 0))->withCreativeCategory(), $this);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			if($this->currentTest === null){
				if(!$this->startNextTest()){
					$this->onAllTestsCompleted();
					throw new CancelTaskException();
				}
			}elseif($this->currentTest->isFinished() || $this->currentTest->isTimedOut()){
				$this->onTestCompleted($this->currentTest);
			}else{
				$this->currentTest->tick();
			}
		}), 10);

		$this->waitingTests = [
			new ClosureTest($this->getLogger(), "Native custom items", "STARTUP and POSTWORLD registration, frozen network dictionary, persistence and creative inventory", function() : void{
				$registry = ItemRegistry::getInstance();
				if(!$registry->isFinalized()){ throw new TestFailedException("Item registry was not finalized"); }
				foreach(["script_test:gem", "tester:helmet"] as $id){
					$item = $registry->create($id);
					$item->setCustomName("Persistent custom item")->setLore(["Round trip"]);
					$loaded = Item::nbtDeserialize($item->nbtSerialize());
					$converter = TypeConverter::getInstance();
					if(!$loaded->equalsExact($item) || !$converter->netItemStackToCore($converter->coreItemStackToNet($item))->equalsExact($item)){
						throw new TestFailedException("Custom item round trip failed: $id");
					}
					if(!CreativeInventory::getInstance()->contains($registry->create($id))){ throw new TestFailedException("Custom creative item missing: $id"); }
				}
				try{
					$registry->register(ItemDefinition::item("tester:late", "Late"), $this);
				}catch(\LogicException){ return; }
				throw new TestFailedException("Runtime item registration was accepted");
			}),
			new ClosureTest($this->getLogger(), "Native custom blocks", "STARTUP registration, world placement, collision, persistence, explosions, palette and block items", function() : void{
				$blocks = BlockRegistry::getInstance();
				if(!$blocks->isFinalized()){ throw new TestFailedException("Block registry was not finalized before POSTWORLD"); }
				try{
					$blocks->register(BlockDefinition::cube("tester:late", "Late", "late"), $this);
					throw new TestFailedException("POSTWORLD block registration was accepted");
				}catch(\LogicException){}

				$world = $this->getServer()->getWorldManager()->getDefaultWorld() ?? throw new TestFailedException("No default world");
				$spawn = $world->getSpawnLocation();
				$x = $spawn->getFloorX() + 3;
				$z = $spawn->getFloorZ() + 3;
				$y = 250;
				$world->loadChunk($x >> 4, $z >> 4);
				$lamp = $blocks->create("script_test:lamp");
				if(!$lamp instanceof CustomBlock){ throw new TestFailedException("Lamp is not a CustomBlock"); }
				$lamp->setState("script_test:lit", true);
				$slab = $blocks->create("script_test:ruby_slab");
				if(!$slab instanceof Slab){ throw new TestFailedException("Slab family did not use Quark's Slab"); }
				$world->setBlockAt($x, $y, $z, $lamp);
				$world->setBlockAt($x + 1, $y, $z, $slab->setSlabType(SlabType::BOTTOM));
				if(!$world->getBlockAt($x, $y, $z)->isSameState($lamp)){ throw new TestFailedException("Placed state was not kept"); }

				$above = new AxisAlignedBB($x + 1.1, $y + 0.6, $z + 0.1, $x + 1.9, $y + 0.9, $z + 0.9);
				if($world->getCollisionBlocks($above) !== []){ throw new TestFailedException("Bottom slab collides above its top"); }
				if(count($world->getCollisionBlocks($above->offsetCopy(0, -0.3, 0))) !== 1){ throw new TestFailedException("Bottom slab has no collision"); }
				if(count($world->getCollisionBlocks(new AxisAlignedBB($x + 0.2, $y + 0.2, $z + 0.2, $x + 0.8, $y + 0.8, $z + 0.8))) !== 1){ throw new TestFailedException("Cube has no collision"); }

				$world->setBlockAt($x, $y + 2, $z, $blocks->create("script_test:ruby_block"));
				$world->setBlockAt($x + 1, $y + 2, $z, $blocks->create("script_test:vault"));
				(function(Explosion $explosion) : void{
					$explosion->explodeA();
					$explosion->explodeB();
				})(new Explosion(new Position($x + 0.5, $y + 3.5, $z + 0.5, $world), 2));
				if($world->getBlockAt($x, $y + 2, $z)->getTypeId() !== VanillaBlocks::AIR()->getTypeId()){ throw new TestFailedException("Weak custom block survived an explosion"); }
				if($world->getBlockAt($x + 1, $y + 2, $z)->getTypeId() !== $blocks->create("script_test:vault")->getTypeId()){ throw new TestFailedException("Blast resistant custom block was destroyed"); }

				if(!$world->unloadChunk($x >> 4, $z >> 4, false)){ throw new TestFailedException("Chunk did not unload"); }
				$world->loadChunk($x >> 4, $z >> 4);
				if(!$world->getBlockAt($x, $y, $z)->isSameState($lamp) || !$world->getBlockAt($x + 1, $y, $z)->isSameState($slab)){
					throw new TestFailedException("Custom blocks did not survive a chunk save and reload");
				}

				$converter = TypeConverter::getInstance();
				$translator = $converter->getBlockTranslator();
				if(!$translator->internalIdToNetworkStateData($lamp->getStateId())->equals(\quark\world\format\io\GlobalBlockStateHandlers::getSerializer()->serialize($lamp->getStateId()))){
					throw new TestFailedException("Network palette does not contain the lamp state");
				}
				$names = [];
				foreach(StaticPacketCache::getInstance()->getBlockPaletteEntries() as $entry){
					$names[$entry->getName()] = true;
				}
				foreach(["script_test:ruby_block", "script_test:vault", "script_test:lamp", "script_test:ruby_slab"] as $id){
					if(!isset($names[$id])){ throw new TestFailedException("StartGame palette is missing $id"); }
				}
				$item = $lamp->asItem()->setCount(5);
				if(!Item::nbtDeserialize($item->nbtSerialize())->equalsExact($item) || !$converter->netItemStackToCore($converter->coreItemStackToNet($item))->equalsExact($item)){
					throw new TestFailedException("Custom block item round trip failed");
				}
				if(!CreativeInventory::getInstance()->contains($blocks->create("script_test:ruby_block")->asItem())){ throw new TestFailedException("Custom block missing from creative"); }
				//redstone opens one door half, and custom clients need both halves saved open
				$door = $blocks->create("script_test:door");
				if(!$door instanceof Door){ throw new TestFailedException("Door family did not use Quark's Door"); }
				$world->setBlockAt($x + 3, $y, $z, $door);
				$world->setBlockAt($x + 3, $y + 1, $z, (clone $door)->setTop(true));
				$bottom = $world->getBlockAt($x + 3, $y, $z);
				if(!$bottom instanceof Door){ throw new TestFailedException("Door was not placed"); }
				(fn() => $this->onReceivePower(15))->call($bottom);
				$top = RuntimeBlockStateRegistry::getInstance()->fromStateId($world->getChunk(($x + 3) >> 4, $z >> 4)?->getBlockStateId(($x + 3) & 15, $y + 1, $z & 15) ?? 0);
				if(!$top instanceof Door || !$top->isOpen() || !$world->getBlockAt($x + 3, $y, $z) instanceof Door){ throw new TestFailedException("Powered custom door left its other half closed"); }
				$world->setBlockAt($x + 3, $y + 1, $z, VanillaBlocks::AIR());
				$world->setBlockAt($x + 3, $y, $z, VanillaBlocks::AIR());

				$world->setBlockAt($x, $y + 4, $z, $blocks->create("script_test:safe"));
				$tile = $world->getTileAt($x, $y + 4, $z) ?? throw new TestFailedException("Custom block tile was not created");
				$tile->readSaveData(CompoundTag::create()->setString("owner", "tester"));
				$world->unloadChunk($x >> 4, $z >> 4, false);
				$world->loadChunk($x >> 4, $z >> 4);
				$tile = $world->getTileAt($x, $y + 4, $z) ?? throw new TestFailedException("Custom block tile did not survive a reload");
				if($tile->saveNBT()->getString("owner", "") !== "tester"){ throw new TestFailedException("Custom block tile data was not saved"); }
				$world->setBlockAt($x, $y + 4, $z, VanillaBlocks::AIR());
				if($world->getTileAt($x, $y + 4, $z) !== null || !$tile->isClosed()){ throw new TestFailedException("Custom block tile outlived its block"); }
				$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
				$world->setBlockAt($x + 1, $y, $z, VanillaBlocks::AIR());
				$world->setBlockAt($x + 1, $y + 2, $z, VanillaBlocks::AIR());
			}),
			//Add test objects here
			new ClosureTest(
				$this->getLogger(),
				"Minimal script plugin load test",
				"Tests that a script plugin with the bare minimum metadata loads correctly",
				function() : void{
					$plugin = $this
						->getServer()
						->getPluginManager()
						->getPlugin(ScriptPluginLoader::AUTOGENERATED_NAME_PREFIX . "TestScriptPlugin");
					if($plugin === null || !$plugin->isEnabled()){
						throw new TestFailedException();
					}
				}
			)
		];
	}

	public function onServerCommand(CommandEvent $event) : void{
		//The CI will send this command as a failsafe to prevent the build from hanging if the tester plugin failed to
		//run. However, if the plugin loaded successfully we don't want to allow this to stop the server as there may
		//be asynchronous tests running. Instead we cancel this and stop the server of our own accord once all tests
		//have completed.
		if($event->getCommand() === "stop"){
			$event->cancel();
		}
	}

	private function startNextTest() : bool{
		$this->currentTest = array_shift($this->waitingTests);
		if($this->currentTest !== null){
			$this->getLogger()->notice("Running test #" . (++$this->currentTestNumber) . " (" . $this->currentTest->getName() . ")");
			$this->currentTest->start();
			return true;
		}

		return false;
	}

	private function onTestCompleted(Test $test) : void{
		$message = "Finished test #" . $this->currentTestNumber . " (" . $test->getName() . "): ";
		switch($test->getResult()){
			case Test::RESULT_OK:
				$message .= "PASS";
				break;
			case Test::RESULT_FAILED:
				$message .= "FAIL";
				break;
			case Test::RESULT_ERROR:
				$message .= "ERROR";
				break;
			case Test::RESULT_WAITING:
				$message .= "TIMEOUT";
				break;
			default:
				$message .= "UNKNOWN";
				break;
		}

		$this->getLogger()->notice($message);

		$this->completedTests[$this->currentTestNumber] = $test;
		$this->currentTest = null;
	}

	private function onAllTestsCompleted() : void{
		$this->getLogger()->notice("All tests finished, stopping the server");
		$this->getServer()->shutdown();
	}
}
