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
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\CraftingTableInventoryWindow;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\inventory\InventoryCloseEvent;
use quark\event\RegisteredListener;
use quark\inventory\ArmorInventory;
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
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\Position;
use function array_filter;
use function array_values;
use function count;
use function spl_object_id;

final class InventoryWindowLifecycleTest extends TestCase{
	private WindowTestPlayer $player;
	private InventoryManager $manager;
	/** @var Packet[] */
	private array $packets = [];
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
