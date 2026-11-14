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

namespace quark\network\mcpe;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\ServerboundLoadingScreenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\hud\LoadingScreenType;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use quark\entity\Entity;
use quark\math\Vector2;
use quark\math\Vector3;
use quark\network\mcpe\handler\DeathPacketHandler;
use quark\network\mcpe\handler\InGamePacketHandler;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\player\Player;
use quark\Server;
use quark\world\Dimension;
use quark\world\DimensionRegistry;
use quark\world\Position;
use quark\world\World;

final class NetworkSessionDimensionTest extends TestCase{

	/** @var list<ClientboundPacket|EncodedPacket> */
	private array $sent = [];
	private World $world;
	private int $dimensionChanges = 0;
	private int $tick = 0;
	private Player&MockObject $player;
	private NetworkSession $session;

	private function world(string $dimension) : World{
		$world = $this->createMock(World::class);
		$world->method("getDimension")->willReturn(DimensionRegistry::getInstance()->get($dimension) ?? self::fail());
		return $world;
	}

	protected function setUp() : void{
		$this->world = $this->world(Dimension::OVERWORLD);
		$this->player = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($this->player, $this->createMock(\Logger::class));
		$this->player->spawned = true;
		$this->player->method("getWorld")->willReturnCallback(fn() => $this->world);
		$this->player->method("getPosition")->willReturn(new Position(1, 64, 2, null));
		$this->player->method("getOffsetPosition")->willReturnCallback(fn(Vector3 $pos) => $pos->add(0, 1.62, 0));
		$this->player->method("onClientDimensionChange")->willReturnCallback(function() : void{
			++$this->dimensionChanges;
		});

		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["sendDataPacket"])->getMock();
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket|EncodedPacket $packet) : bool{
			$this->sent[] = $packet;
			return true;
		});
		$set = static fn(string $property, mixed $value) => (new \ReflectionProperty(NetworkSession::class, $property))->setValue($session, $value);
		$set("logger", new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$set("player", $this->player);
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$set("server", $server);
		$this->session = $session;
	}

	/**
	 * @return list<ClientboundPacket|EncodedPacket>
	 */
	private function takeSent() : array{
		$sent = $this->sent;
		$this->sent = [];
		return $sent;
	}

	private function enter(string $dimension) : void{
		$this->world = $this->world($dimension);
		(new \ReflectionMethod(NetworkSession::class, "syncDimension"))->invoke($this->session);
	}

	/**
	 * @return list<array{int, ?int}> dimension and loading screen of each change sent since the last call
	 */
	private function changes() : array{
		$changes = [];
		foreach($this->sent as $packet){
			if($packet instanceof ChangeDimensionPacket){
				$changes[] = [$packet->dimension, $packet->getLoadingScreenId()];
			}
		}
		$this->sent = [];
		return $changes;
	}

	public function testSameDimensionSendsNothing() : void{
		$this->enter(Dimension::OVERWORLD);
		self::assertSame([], $this->changes());
		self::assertFalse($this->session->isChangingDimension());
		self::assertSame(0, $this->dimensionChanges);
	}

	public function testChangeAndFinish() : void{
		$this->enter(Dimension::NETHER);
		$packet = $this->sent[0] ?? null;
		self::assertInstanceOf(ChangeDimensionPacket::class, $packet);
		self::assertTrue($packet->position->equals(new Vector3(1, 65.62, 2)));
		self::assertFalse($packet->respawn);
		self::assertSame([[DimensionIds::NETHER, 1]], $this->changes());
		self::assertTrue($this->session->isChangingDimension());
		self::assertSame(1, $this->dimensionChanges);

		$this->session->onLoadingScreenClosed(1);
		self::assertFalse($this->session->isChangingDimension());
		self::assertSame([], $this->changes());

		$this->enter(Dimension::THE_END);
		self::assertSame([[DimensionIds::THE_END, 2]], $this->changes());
	}

	public function testStaleLoadingScreenIsIgnored() : void{
		$this->enter(Dimension::NETHER);
		$this->changes();
		$this->session->onLoadingScreenClosed(7);
		self::assertTrue($this->session->isChangingDimension());
		$this->session->onLoadingScreenClosed(null);
		self::assertFalse($this->session->isChangingDimension(), "Clients that don't echo the ID still finish");
	}

	public function testChangesWhileLoadingAreCaughtUp() : void{
		$this->enter(Dimension::NETHER);
		$this->enter(Dimension::THE_END);
		$this->enter(Dimension::OVERWORLD);
		self::assertSame([[DimensionIds::NETHER, 1]], $this->changes(), "Only one change may be in flight");

		$this->session->onLoadingScreenClosed(1);
		self::assertSame([[DimensionIds::OVERWORLD, 2]], $this->changes());
		self::assertSame(2, $this->dimensionChanges, "Chunks sent for the wrong dimension are resent");
		self::assertTrue($this->session->isChangingDimension());

		$this->session->onLoadingScreenClosed(2);
		self::assertSame([], $this->changes());
		self::assertFalse($this->session->isChangingDimension());
	}

	public function testRoundTripWhileLoadingNeedsNoSecondChange() : void{
		$this->enter(Dimension::NETHER);
		$this->changes();
		$this->enter(Dimension::NETHER);
		$this->session->onLoadingScreenClosed(1);
		self::assertSame([], $this->changes());
		self::assertSame(1, $this->dimensionChanges);
	}

	public function testWaitsForFirstSpawn() : void{
		$this->player->spawned = false;
		$this->enter(Dimension::NETHER);
		self::assertSame([], $this->changes(), "The client is still on the join loading screen");

		$this->player->spawned = true;
		$this->enter(Dimension::NETHER);
		self::assertSame([[DimensionIds::NETHER, 1]], $this->changes());
	}

	public function testTerrainReadyEndsTheLoadingScreen() : void{
		$this->session->notifyTerrainReady();
		self::assertSame([], $this->sent, "Nothing to finish");

		$this->enter(Dimension::NETHER);
		$this->sent = [];
		$this->session->notifyTerrainReady();
		self::assertCount(1, $this->sent);
		self::assertInstanceOf(PlayStatusPacket::class, $this->sent[0]);
		self::assertSame(PlayStatusPacket::PLAYER_SPAWN, $this->sent[0]->status);
	}

	public function testGivesUpOnAClientThatNeverFinishes() : void{
		$expire = new \ReflectionMethod(NetworkSession::class, "expireDimensionChange");
		$this->enter(Dimension::NETHER);
		$this->tick = 1_000_000;
		$expire->invoke($this->session);
		self::assertTrue($this->session->isChangingDimension(), "The terrain is still on its way");

		$this->session->notifyTerrainReady();
		$this->tick += 599;
		$expire->invoke($this->session);
		self::assertTrue($this->session->isChangingDimension());
		$this->tick++;
		$expire->invoke($this->session);
		self::assertFalse($this->session->isChangingDimension());

		$this->enter(Dimension::OVERWORLD);
		$this->changes();
		$this->tick += 10_000;
		$expire->invoke($this->session);
		self::assertTrue($this->session->isChangingDimension(), "A new change gets a fresh deadline");
	}

	public function testDisconnectedSessionSendsNothing() : void{
		(new \ReflectionProperty(NetworkSession::class, "connected"))->setValue($this->session, false);
		$this->enter(Dimension::NETHER);
		self::assertSame([], $this->changes());
		self::assertSame(0, $this->dimensionChanges);
	}

	public function testDeathScreenFinishesTheChange() : void{
		$this->enter(Dimension::NETHER);
		$handler = (new \ReflectionClass(DeathPacketHandler::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(DeathPacketHandler::class, "session"))->setValue($handler, $this->session);
		self::assertTrue($handler->handleServerboundLoadingScreen(ServerboundLoadingScreenPacket::create(LoadingScreenType::STOP_LOADING_SCREEN, 1)));
		self::assertFalse($this->session->isChangingDimension());
	}

	public function testInFlightItemUseIsRolledBack() : void{
		$this->enter(Dimension::NETHER);
		$inventory = $this->createMock(InventoryManager::class);
		$inventory->expects(self::once())->method("addRawPredictedSlotChanges")->with([]);
		$inventory->expects(self::once())->method("syncMismatchedPredictedSlotChanges");
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		$set = static fn(string $property, mixed $value) => (new \ReflectionProperty(InGamePacketHandler::class, $property))->setValue($handler, $value);
		$set("session", $this->session);
		$set("player", $this->player);
		$set("inventoryManager", $inventory);
		$this->player->expects(self::never())->method("useHeldItem");

		$zero2 = new Vector2(0.0, 0.0);
		$zero3 = new Vector3(0.0, 0.0, 0.0);
		$transaction = UseItemTransactionData::new([], UseItemTransactionData::ACTION_CLICK_AIR, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), $zero3, $zero3, 0, PredictedResult::SUCCESS, 0);
		$input = PlayerAuthInputPacket::create($zero3, 0.0, 0.0, 0.0, 0.0, 0.0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, $zero2, 0, $zero3, new ItemInteractionData(0, null, $transaction), null, null, null, 0.0, 0.0, $zero3, $zero2);
		self::assertTrue($handler->handlePlayerAuthInput($input));
	}

	public function testLoadingScreenPacketFinishesTheChange() : void{
		$this->enter(Dimension::NETHER);
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(InGamePacketHandler::class, "session"))->setValue($handler, $this->session);
		(new \ReflectionProperty(InGamePacketHandler::class, "player"))->setValue($handler, $this->player);

		self::assertTrue($handler->handleServerboundLoadingScreen(ServerboundLoadingScreenPacket::create(LoadingScreenType::START_LOADING_SCREEN, 1)));
		self::assertTrue($this->session->isChangingDimension());

		$this->player->expects(self::never())->method("handleMovement");
		$this->player->expects(self::never())->method("setRotation");
		$flags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS);
		$flags->set(PlayerAuthInputFlags::START_JUMPING, true);
		$zero2 = new Vector2(0.0, 0.0);
		$zero3 = new Vector3(0.0, 0.0, 0.0);
		$input = PlayerAuthInputPacket::create(new Vector3(50.0, 80.0, 50.0), 30.0, 0.0, 30.0, 0.0, 0.0, $flags, InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, $zero2, 0, $zero3, null, new ItemStackRequest(42, [], [], 0), null, null, 0.0, 0.0, $zero3, $zero2);
		$this->takeSent();
		self::assertTrue($handler->handlePlayerAuthInput($input), "Loading screen input is dropped");
		$response = $this->takeSent()[0] ?? null;
		self::assertInstanceOf(ItemStackResponsePacket::class, $response, "The client still gets an answer to its request");
		self::assertSame(ItemStackResponse::RESULT_ERROR, $response->getResponses()[0]->getResult());
		self::assertSame(42, $response->getResponses()[0]->getRequestId());

		self::assertTrue($handler->handleServerboundLoadingScreen(ServerboundLoadingScreenPacket::create(LoadingScreenType::STOP_LOADING_SCREEN, 1)));
		self::assertFalse($this->session->isChangingDimension());
	}
}
