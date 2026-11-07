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

namespace quark\player;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\PlayerEnchantOptionsPacket;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\CraftingTableInventoryWindow;
use quark\block\inventory\window\EnchantingTableInventoryWindow;
use quark\block\tile\Chest;
use quark\block\VanillaBlocks;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\inventory\InventoryCloseEvent;
use quark\event\inventory\InventoryOpenEvent;
use quark\event\inventory\InventoryTransactionEvent;
use quark\event\player\PlayerDropItemEvent;
use quark\event\player\PlayerEnchantingOptionsRequestEvent;
use quark\event\RegisteredListener;
use quark\inventory\ArmorInventory;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Hotbar;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\inventory\transaction\InventoryTransaction;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\Position;
use quark\world\World;
use function array_fill;
use function array_filter;
use function array_values;
use function count;
use function spl_object_id;

final class InventoryWindowLifecycleTest extends TestCase{
	private WindowTestPlayer $player;
	private InventoryManager $manager;
	/** @var ClientboundPacket[] */
	private array $packets = [];
	/** @phpstan-var (\Closure(ClientboundPacket) : void)|null */
	private ?\Closure $packetSendCallback = null;
	private Plugin $plugin;

	protected function setUp() : void{
		$this->plugin = $this->createMock(Plugin::class);
		[$this->player, $this->manager] = $this->newPlayer();
	}

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll($this->plugin);
	}

	/**
	 * @phpstan-return array{WindowTestPlayer, InventoryManager}
	 */
	private function newPlayer() : array{
		$session = $this->createMock(NetworkSession::class);
		$player = new \ReflectionClass(WindowTestPlayer::class)->newInstanceWithoutConstructor();
		$player->initialize($session, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$manager = new InventoryManager($player, $session);
		$session->method("isConnected")->willReturn(true);
		$session->method("getInvManager")->willReturn($manager);
		$session->method("getTypeConverter")->willReturn(TypeConverter::getInstance());
		$session->method("getLogger")->willReturn(new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) : bool{
			$this->packets[] = $packet;
			($this->packetSendCallback)?->__invoke($packet);
			return true;
		});
		return [$player, $manager];
	}

	private function chestWindow(?Inventory $inventory = null, ?Player $viewer = null) : BlockInventoryWindow{
		return new BlockInventoryWindow($viewer ?? $this->player, $inventory ?? new SimpleInventory(27), new Position(0, 64, 0, null));
	}

	/**
	 * @phpstan-template T of ClientboundPacket
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function sent(string $class) : array{
		return array_values(array_filter($this->packets, fn(ClientboundPacket $packet) => $packet instanceof $class));
	}

	private function ackClose() : void{
		$closes = $this->sent(ContainerClosePacket::class);
		$this->manager->onClientRemoveWindow($closes[count($closes) - 1]->windowId);
	}

	public function testOpeningAndClosingTracksTheWindow() : void{
		$chest = new SimpleInventory(27);
		$window = $this->chestWindow($chest);

		self::assertTrue($this->player->setCurrentWindow($window));
		self::assertSame($window, $this->player->getCurrentWindow());
		self::assertSame([spl_object_id($this->player) => $this->player], $chest->getViewers());
		self::assertTrue($chest->getListeners()->contains($this->manager));
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));

		$this->player->removeCurrentWindow();
		self::assertNull($this->player->getCurrentWindow());
		self::assertSame([], $chest->getViewers());
		self::assertFalse($chest->getListeners()->contains($this->manager));
		self::assertCount(1, $this->sent(ContainerClosePacket::class));
	}

	public function testChangesAreOnlySentWhileTheWindowIsOpen() : void{
		$chest = new SimpleInventory(27);
		$this->player->setCurrentWindow($this->chestWindow($chest));
		$this->packets = [];
		$chest->setItem(3, VanillaItems::APPLE());
		$this->manager->flushPendingUpdates();
		self::assertNotEmpty(array_filter($this->sent(InventorySlotPacket::class), fn(InventorySlotPacket $pk) => $pk->inventorySlot === 3));

		$this->player->removeCurrentWindow();
		$this->ackClose();
		$this->packets = [];
		$chest->setItem(4, VanillaItems::APPLE());
		$this->manager->flushPendingUpdates();
		self::assertSame([], $this->sent(InventorySlotPacket::class));
	}

	public function testPredictedChangesDiscardOlderQueuedUpdates() : void{
		$inventory = $this->player->getInventory();
		$this->manager->syncAll();
		$inventory->setItem(0, VanillaItems::APPLE());
		$this->manager->setCurrentItemStackRequestId(-1);
		$this->manager->addPredictedSlotChange($this->player->getPermanentWindow(PlayerInventoryType::INVENTORY), 0, VanillaItems::DIAMOND());
		$inventory->setItem(0, VanillaItems::DIAMOND());
		$this->manager->syncMismatchedPredictedSlotChanges();
		$this->packets = [];
		$this->manager->flushPendingUpdates();
		self::assertSame([], $this->sent(InventorySlotPacket::class));
		self::assertSame(-1, $this->manager->getItemStackInfo($inventory, 0)?->getRequestId());

		$this->manager->setCurrentItemStackRequestId(null);
		$inventory->setItem(0, VanillaItems::STICK());
		$this->manager->flushPendingUpdates();
		$slots = $this->sent(InventorySlotPacket::class);
		self::assertCount(2, $slots);
		self::assertSame(TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::STICK())->getId(), $slots[1]->item->getItemStack()->getId());
	}

	public function testOpenEventReplacementTakesPriority() : void{
		$requested = $this->chestWindow();
		$replacement = $this->chestWindow();
		HandlerListManager::global()->getListFor(InventoryOpenEvent::class)->register(new RegisteredListener(
			function(InventoryOpenEvent $event) use ($requested, $replacement) : void{
				if($event->getWindow() === $requested){
					$this->player->setCurrentWindow($replacement);
				}
			}, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
		));
		self::assertFalse($this->player->setCurrentWindow($requested));
		self::assertSame($replacement, $this->player->getCurrentWindow());
		self::assertSame([], $requested->getInventory()->getViewers());
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));
		self::assertSame([], $this->sent(ContainerClosePacket::class));
	}

	public function testClosingDuringTheOpenPacketDoesNotRetainTheWindow() : void{
		$chest = new SimpleInventory(27);
		$this->packetSendCallback = function(ClientboundPacket $packet) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->player->removeCurrentWindow();
			}
		};

		self::assertFalse($this->player->setCurrentWindow($this->chestWindow($chest)));
		self::assertNull($this->player->getCurrentWindow());
		self::assertSame([], $chest->getViewers());
		self::assertFalse($chest->getListeners()->contains($this->manager));
	}

	public function testClosingDuringTheOpenPacketUsesTheOpenedWindowType() : void{
		$this->packetSendCallback = function(ClientboundPacket $packet) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->player->removeCurrentWindow();
			}
		};
		$this->player->setCurrentWindow(new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null)));
		self::assertSame(WindowTypes::WORKBENCH, $this->sent(ContainerClosePacket::class)[0]->windowType);
	}

	public function testOpeningDuringTheClosePacketWaitsForTheAcknowledgement() : void{
		$this->player->setCurrentWindow($this->chestWindow());
		$replacement = $this->chestWindow();
		$this->packets = [];
		$this->packetSendCallback = function(ClientboundPacket $packet) use ($replacement) : void{
			if($packet instanceof ContainerClosePacket && $packet->server){
				$this->packetSendCallback = null;
				$this->player->setCurrentWindow($replacement);
			}
		};
		$this->player->removeCurrentWindow();
		self::assertSame([], $this->sent(ContainerOpenPacket::class));
		$this->ackClose();
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));
		self::assertSame($replacement, $this->player->getCurrentWindow());
	}

	public function testReplacementWhileOpeningADeferredWindowIsStillSent() : void{
		$this->player->setCurrentWindow($this->chestWindow());
		$this->player->setCurrentWindow($this->chestWindow());
		$replacement = $this->chestWindow();
		$this->packetSendCallback = function(ClientboundPacket $packet) use ($replacement) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->packetSendCallback = null;
				$this->player->setCurrentWindow($replacement);
			}
		};
		$this->ackClose();
		$closes = $this->sent(ContainerClosePacket::class);
		$closeId = $closes[count($closes) - 1]->windowId;
		$this->packets = [];
		$this->manager->onClientRemoveWindow($closeId);
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));
		self::assertSame($replacement, $this->player->getCurrentWindow());
		self::assertTrue($replacement->getInventory()->getListeners()->contains($this->manager));
	}

	public function testReplacementDuringTheOpenPacketTakesPriority() : void{
		$chest = new SimpleInventory(27);
		$replacement = $this->chestWindow();
		$this->packetSendCallback = function(ClientboundPacket $packet) use ($replacement) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->packetSendCallback = null;
				$this->player->setCurrentWindow($replacement);
			}
		};

		self::assertFalse($this->player->setCurrentWindow($this->chestWindow($chest)));
		self::assertSame($replacement, $this->player->getCurrentWindow());
		self::assertSame([], $chest->getViewers());
		self::assertFalse($chest->getListeners()->contains($this->manager));
		self::assertCount(1, $replacement->getInventory()->getViewers());
		$this->ackClose();
		self::assertTrue($replacement->getInventory()->getListeners()->contains($this->manager));
	}

	public function testWindowsReplacedBeforeTheCloseAckAreNeverSent() : void{
		$this->player->setCurrentWindow($this->chestWindow());
		$skipped = new SimpleInventory(27);
		$last = new SimpleInventory(27);
		$this->packets = [];

		//the client hasn't acked the first close yet, so both of these wait
		$this->player->setCurrentWindow($this->chestWindow($skipped));
		$this->player->setCurrentWindow($this->chestWindow($last));
		self::assertSame([], $this->sent(ContainerOpenPacket::class));

		$this->ackClose();
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));
		self::assertSame([], $skipped->getViewers());
		self::assertFalse($skipped->getListeners()->contains($this->manager));
		self::assertTrue($last->getListeners()->contains($this->manager));
	}

	public function testCloseHandlerOpeningAWindowTakesPriority() : void{
		$first = new SimpleInventory(27);
		$replacement = new SimpleInventory(27);
		$ignored = new SimpleInventory(27);
		$this->player->setCurrentWindow($this->chestWindow($first));
		$opened = false;
		HandlerListManager::global()->getListFor(InventoryCloseEvent::class)->register(new RegisteredListener(function(InventoryCloseEvent $event) use (&$opened, $replacement) : void{
			if(!$opened){
				$opened = true;
				$event->getPlayer()->setCurrentWindow($this->chestWindow($replacement));
			}
		}, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")));

		self::assertFalse($this->player->setCurrentWindow($this->chestWindow($ignored)));
		self::assertSame($replacement, $this->player->getCurrentWindow()?->getInventory());
		self::assertSame([], $first->getViewers());
		self::assertSame([], $ignored->getViewers());
		self::assertCount(1, $replacement->getViewers());
	}

	public function testWindowsCannotBeSharedOrReopened() : void{
		[$other] = $this->newPlayer();
		foreach([
			$this->chestWindow(viewer: $other),
			$this->player->getPermanentWindow(PlayerInventoryType::INVENTORY),
			$this->chestWindow($this->player->getInventory()),
			$this->chestWindow(new CombinedInventoryProxy([$this->player->getInventory(), new SimpleInventory(1)])),
		] as $window){
			try{
				$this->player->setCurrentWindow($window);
				self::fail("Window " . $window::class . " was accepted");
			}catch(\InvalidArgumentException){ }
		}
		self::assertNull($this->player->getCurrentWindow());
	}

	public function testDestroyedContainersCloseTheirWindows() : void{
		$chest = new SimpleInventory(27);
		$this->player->setCurrentWindow($this->chestWindow($chest));
		$chest->removeAllWindows();
		self::assertNull($this->player->getCurrentWindow());
		self::assertSame([], $chest->getViewers());
		self::assertFalse($chest->getListeners()->contains($this->manager));
		//the player's own inventories stay open
		self::assertCount(1, $this->player->getInventory()->getViewers());
	}

	public function testForcedClosureKeepsTheViewerRegisteredUntilOnClose() : void{
		$chest = new SimpleInventory(27);
		$window = new class($this->player, $chest, new Position(0, 64, 0, null)) extends BlockInventoryWindow{
			public int $viewersAtClose = -1;

			public function onClose() : void{
				$this->viewersAtClose = count($this->inventory->getViewers());
				parent::onClose();
			}
		};
		$this->player->setCurrentWindow($window);
		$chest->removeAllWindows();

		self::assertSame(1, $window->viewersAtClose);
		self::assertSame([], $chest->getViewers());
	}

	public function testBreakingADoubleChestClosesTheWindowBeforeUnpairing() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$left = new Chest($world, new Vector3(0, 64, 0));
		$right = new Chest($world, new Vector3(1, 64, 0));
		$world->method("isInLoadedTerrain")->willReturn(true);
		$world->method("getTileAt")->willReturnCallback(static fn(int $x) => $x === 0 ? $left : $right);
		self::assertTrue($left->pairWith($right));
		$window = new class($this->player, new CombinedInventoryProxy([$left->getInventory(), $right->getInventory()]), new Position(0, 64, 0, null), $left) extends BlockInventoryWindow{
			public bool $pairedAtClose = false;

			public function __construct(Player $player, Inventory $inventory, Position $holder, private Chest $chest){
				parent::__construct($player, $inventory, $holder);
			}

			public function onClose() : void{
				$this->pairedAtClose = $this->chest->isPaired();
				parent::onClose();
			}
		};
		$this->player->setCurrentWindow($window);
		$left->onBlockDestroyed();
		self::assertTrue($window->pairedAtClose);
		self::assertFalse($right->isPaired());
		self::assertNull($this->player->getCurrentWindow());
		$right->close();
	}

	public function testTemporaryWindowContentsReturnToTheInventory() : void{
		$window = new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null));
		$this->player->setCurrentWindow($window);
		$window->getInventory()->setItem(4, VanillaItems::DIAMOND()->setCount(3));
		$this->player->getCursorInventory()->setItem(0, VanillaItems::STICK());

		$this->player->removeCurrentWindow();
		self::assertTrue($window->getInventory()->isSlotEmpty(4));
		self::assertTrue($this->player->getCursorInventory()->isSlotEmpty(0));
		self::assertTrue($this->player->getInventory()->contains(VanillaItems::DIAMOND()->setCount(3)));
		self::assertTrue($this->player->getInventory()->contains(VanillaItems::STICK()));
	}

	public function testClosingDuringCleanupDoesNotReturnItemsTwice() : void{
		$window = new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null));
		$this->player->setCurrentWindow($window);
		$window->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$this->player->getInventory()->getListeners()->add(CallbackInventoryListener::onAnyChange(function() : void{
			$this->player->removeCurrentWindow();
		}));
		$this->player->removeCurrentWindow();
		self::assertNull($this->player->getCurrentWindow());
		self::assertSame([], $window->getInventory()->getContents());
		self::assertSame(1, $this->player->getInventory()->getItem(0)->getCount());
		self::assertTrue($this->player->getInventory()->getItem(0)->equals(VanillaItems::DIAMOND()));
		self::assertCount(1, $this->sent(ContainerClosePacket::class));
	}

	public function testTransfersCommitBeforeCallbacksCanCloseTheirWindow() : void{
		foreach([false, true] as $combined){
			[$player] = $this->newPlayer();
			$window = $combined ?
				$this->chestWindow(new CombinedInventoryProxy([new SimpleInventory(1), new SimpleInventory(1)]), $player) :
				new CraftingTableInventoryWindow($player, new Position(0, 64, 0, null));
			$player->setCurrentWindow($window);
			$item = VanillaItems::DIAMOND();
			$window->getInventory()->setItem(0, $item);
			$player->getInventory()->getListeners()->add(CallbackInventoryListener::onAnyChange(static function() use ($player, $window) : void{
				self::assertTrue($window->getInventory()->isSlotEmpty(0));
				$player->removeCurrentWindow();
			}));
			$transaction = new InventoryTransaction($player, [
				new SlotChangeAction($player->getPermanentWindow(PlayerInventoryType::INVENTORY), 0, VanillaItems::AIR(), $item),
				new SlotChangeAction($window, 0, $item, VanillaItems::AIR())
			]);
			$transaction->execute();
			self::assertTrue($transaction->hasExecuted());
			self::assertNull($player->getCurrentWindow());
			self::assertTrue($player->getInventory()->getItem(0)->equalsExact($item));
			self::assertSame([], $window->getInventory()->getContents());
		}
	}

	public function testCleanupPreservesChangesMadeByTransactionHandlers() : void{
		$window = new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null));
		$this->player->setCurrentWindow($window);
		$window->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$calls = 0;
		HandlerListManager::global()->getListFor(InventoryTransactionEvent::class)->register(new RegisteredListener(
			function(InventoryTransactionEvent $event) use ($window, &$calls) : void{
				++$calls;
				$this->player->getInventory()->setItem(0, VanillaItems::APPLE());
				$window->getInventory()->setItem(0, VanillaItems::STICK()->setCount(2));
				$this->player->removeCurrentWindow();
			}, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
		));
		$this->player->removeCurrentWindow();
		self::assertSame(1, $calls);
		self::assertNull($this->player->getCurrentWindow());
		self::assertSame([], $window->getInventory()->getContents());
		self::assertTrue($this->player->getInventory()->getItem(0)->equalsExact(VanillaItems::APPLE()));
		self::assertTrue($this->player->getInventory()->getItem(1)->equalsExact(VanillaItems::STICK()->setCount(2)));
		self::assertCount(2, $this->player->getInventory()->getContents());
	}

	/** @return array<string, array{bool}> */
	public static function overflowCancellation() : array{
		return ["drop" => [false], "cancel" => [true]];
	}

	/** @dataProvider overflowCancellation */
	public function testCleanupDropsOverflowAfterAnInventoryConflict(bool $cancel) : void{
		$window = new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null));
		$this->player->setCurrentWindow($window);
		$window->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(2));
		HandlerListManager::global()->getListFor(InventoryTransactionEvent::class)->register(new RegisteredListener(
			function(InventoryTransactionEvent $event) : void{
				$this->player->getInventory()->setContents(array_fill(0, 36, VanillaItems::APPLE()->setCount(64)));
			}, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
		));
		if($cancel){
			HandlerListManager::global()->getListFor(PlayerDropItemEvent::class)->register(new RegisteredListener(
				static function(PlayerDropItemEvent $event) : void{ $event->cancel(); },
				EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
			));
		}
		$this->player->removeCurrentWindow();
		self::assertSame([], $window->getInventory()->getContents());
		self::assertCount($cancel ? 0 : 1, $this->player->droppedItems);
		if(!$cancel){
			self::assertTrue($this->player->droppedItems[0]->equalsExact(VanillaItems::DIAMOND()->setCount(2)));
		}
		self::assertCount(36, $this->player->getInventory()->getContents());
	}

	public function testDisposingTheManagerDetachesItFromOpenInventories() : void{
		$chest = new SimpleInventory(27);
		$this->player->setCurrentWindow($this->chestWindow($chest));
		$this->manager->dispose();
		self::assertFalse($chest->getListeners()->contains($this->manager));
		self::assertFalse($this->player->getInventory()->getListeners()->contains($this->manager));
	}

	private function enchantingWindow() : EnchantingTableInventoryWindow{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getBlock")->willReturn(VanillaBlocks::AIR());
		$this->player->setEnchantmentSeed(123);
		return new EnchantingTableInventoryWindow($this->player, new Position(0, 64, 0, $world));
	}

	public function testRetainedClosedEnchantingWindowsCannotReplaceCurrentOffers() : void{
		$old = $this->enchantingWindow();
		$this->player->setCurrentWindow($old);
		$old->getInventory()->setItem(0, VanillaItems::DIAMOND_SWORD());
		self::assertNotNull($old->getOption(0));
		$this->player->removeCurrentWindow();
		$this->ackClose();
		$current = $this->enchantingWindow();
		$this->player->setCurrentWindow($current);
		$current->getInventory()->setItem(0, VanillaItems::DIAMOND_PICKAXE());
		$option = $current->getOption(0);
		$events = 0;
		HandlerListManager::global()->getListFor(PlayerEnchantingOptionsRequestEvent::class)->register(new RegisteredListener(
			static function(PlayerEnchantingOptionsRequestEvent $event) use (&$events) : void{ ++$events; },
			EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
		));
		$this->packets = [];
		$old->getInventory()->setItem(0, VanillaItems::DIAMOND_SWORD());
		self::assertSame(0, $events);
		self::assertSame([], $this->sent(PlayerEnchantOptionsPacket::class));
		self::assertNull($old->getOption(0));
		self::assertSame($option, $current->getOption(0));
	}

	public function testClosingDuringTheEnchantingEventDoesNotSendStaleOffers() : void{
		$window = $this->enchantingWindow();
		$this->player->setCurrentWindow($window);
		$closed = false;
		HandlerListManager::global()->getListFor(PlayerEnchantingOptionsRequestEvent::class)->register(new RegisteredListener(
			function(PlayerEnchantingOptionsRequestEvent $event) use (&$closed) : void{
				if(!$closed){
					$closed = true;
					$this->player->removeCurrentWindow();
				}
			}, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("inventory.test")
		));
		$this->packets = [];
		$window->getInventory()->setItem(0, VanillaItems::DIAMOND_SWORD());
		self::assertNull($this->player->getCurrentWindow());
		foreach($this->sent(PlayerEnchantOptionsPacket::class) as $packet){
			self::assertSame([], $packet->getOptions());
		}
		self::assertNull($window->getOption(0));
	}
}

/**
 * A player with real inventories and windows but no server, world or connection behind it.
 */
final class WindowTestPlayer extends Player{
	/** @var Item[] */
	public array $droppedItems = [];

	public function initialize(NetworkSession $session, \Logger $logger) : void{
		$this->logger = $logger;
		$this->networkSession = $session;
		$this->id = 1;
		$this->gamemode = GameMode::SURVIVAL;
		$this->inventory = new SimpleInventory(36);
		$this->hotbar = new Hotbar();
		$this->armorInventory = new ArmorInventory();
		$this->offHandInventory = new SimpleInventory(1);
		$this->enderInventory = new SimpleInventory(27);
		$this->addDefaultWindows();
	}

	public function dropItem(Item $item) : void{
		$this->droppedItems[] = clone $item;
	}

	public function __destruct(){}
}
