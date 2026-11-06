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
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\CraftingTableInventoryWindow;
use quark\block\inventory\window\EnchantingTableInventoryWindow;
use quark\block\tile\Chest;
use quark\block\VanillaBlocks;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\inventory\InventoryCloseEvent;
use quark\event\player\PlayerEnchantingOptionsRequestEvent;
use quark\event\RegisteredListener;
use quark\inventory\ArmorInventory;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Hotbar;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\ContainerClosePacket;
use quark\network\mcpe\protocol\packet\ContainerOpenPacket;
use quark\network\mcpe\protocol\packet\InventorySlotPacket;
use quark\network\mcpe\protocol\packet\PlayerEnchantOptionsPacket;
use quark\network\mcpe\protocol\types\inventory\WindowTypes;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\Position;
use quark\world\World;
use function array_filter;
use function array_values;
use function count;
use function spl_object_id;

final class InventoryWindowLifecycleTest extends TestCase{
	private WindowTestPlayer $player;
	private InventoryManager $manager;
	/** @var Packet[] */
	private array $packets = [];
	/** @phpstan-var (\Closure(Packet) : void)|null */
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
		$session->method("sendDataPacket")->willReturnCallback(function(Packet $packet) : bool{
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
	 * @phpstan-template T of Packet
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function sent(string $class) : array{
		return array_values(array_filter($this->packets, fn(Packet $packet) => $packet instanceof $class));
	}

	private function ackClose() : void{
		$closes = $this->sent(ContainerClosePacket::class);
		$this->manager->onClientRemoveWindow($closes[count($closes) - 1]->containerId);
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
		self::assertNotEmpty(array_filter($this->sent(InventorySlotPacket::class), fn(InventorySlotPacket $pk) => $pk->slot === 3));

		$this->player->removeCurrentWindow();
		$this->ackClose();
		$this->packets = [];
		$chest->setItem(4, VanillaItems::APPLE());
		$this->manager->flushPendingUpdates();
		self::assertSame([], $this->sent(InventorySlotPacket::class));
	}

	public function testClosingDuringTheOpenPacketDoesNotRetainTheWindow() : void{
		$chest = new SimpleInventory(27);
		$this->packetSendCallback = function(Packet $packet) : void{
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
		$this->packetSendCallback = function(Packet $packet) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->player->removeCurrentWindow();
			}
		};
		$this->player->setCurrentWindow(new CraftingTableInventoryWindow($this->player, new Position(0, 64, 0, null)));
		self::assertSame(WindowTypes::WORKBENCH, $this->sent(ContainerClosePacket::class)[0]->containerType);
	}

	public function testOpeningDuringTheClosePacketWaitsForTheAcknowledgement() : void{
		$this->player->setCurrentWindow($this->chestWindow());
		$replacement = $this->chestWindow();
		$this->packets = [];
		$this->packetSendCallback = function(Packet $packet) use ($replacement) : void{
			if($packet instanceof ContainerClosePacket && $packet->serverInitiatedClose){
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
		$this->packetSendCallback = function(Packet $packet) use ($replacement) : void{
			if($packet instanceof ContainerOpenPacket){
				$this->packetSendCallback = null;
				$this->player->setCurrentWindow($replacement);
			}
		};
		$this->ackClose();
		$closes = $this->sent(ContainerClosePacket::class);
		$closeId = $closes[count($closes) - 1]->containerId;
		$this->packets = [];
		$this->manager->onClientRemoveWindow($closeId);
		self::assertCount(1, $this->sent(ContainerOpenPacket::class));
		self::assertSame($replacement, $this->player->getCurrentWindow());
		self::assertTrue($replacement->getInventory()->getListeners()->contains($this->manager));
	}

	public function testReplacementDuringTheOpenPacketTakesPriority() : void{
		$chest = new SimpleInventory(27);
		$replacement = $this->chestWindow();
		$this->packetSendCallback = function(Packet $packet) use ($replacement) : void{
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
			self::assertSame([], $packet->options);
		}
		self::assertNull($window->getOption(0));
	}
}

/**
 * A player with real inventories and windows but no server, world or connection behind it.
 */
final class WindowTestPlayer extends Player{

	public function initialize(NetworkSession $session, \Logger $logger) : void{
		$this->logger = $logger;
		$this->networkSession = $session;
		$this->id = 1;
		$this->inventory = new SimpleInventory(36);
		$this->hotbar = new Hotbar();
		$this->armorInventory = new ArmorInventory();
		$this->offHandInventory = new SimpleInventory(1);
		$this->enderInventory = new SimpleInventory(27);
		$this->addDefaultWindows();
	}

	public function __destruct(){}
}
