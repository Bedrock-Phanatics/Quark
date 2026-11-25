<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\npcdialogue;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\NpcDialoguePacket;
use pocketmine\network\mcpe\protocol\NpcRequestPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use quark\entity\Entity;
use quark\entity\Skin;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketSendEvent;
use quark\lang\Language;
use quark\math\Vector3;
use quark\network\mcpe\convert\LegacySkinAdapter;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\handler\InGamePacketHandler;
use quark\network\mcpe\handler\PacketHandlerInspector;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\PacketSender;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\utils\ObjectSet;
use quark\world\Dimension;
use quark\world\DimensionRegistry;
use quark\world\Position;
use quark\world\Weather;
use quark\world\World;
use function array_map;
use function count;
use function json_decode;
use function spl_object_id;
use function str_repeat;
use function substr;
use const JSON_THROW_ON_ERROR;

final class NpcDialogueTest extends TestCase{
	private Plugin $owner;

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		$this->owner = $this->enabledPlugin();
	}

	protected function tearDown() : void{
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->clear();
		Pulse::reset();
	}

	public function testProtocolPacketsReachTheInGameHandlerAndOnlyUseServerSideButtons() : void{
		[$player, $network] = $this->connection();
		$network->handleDataPacket(EncodedPacket::encode(NpcRequestPacket::create(123, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "", 0, "obsolete")));
		self::assertNull($network->getNpcDialogueControllerIfCreated());

		$selections = [];
		$session = NpcDialogue::create($this->owner)
			->setName("Merchant")
			->setText("Choose an item")
			->setNpcTexture(59)
			->addButton("Shop", static function(Player $selectedBy) use (&$selections) : void{ $selections[] = $selectedBy; })
			->addButton("Goodbye")
			->send($player);
		self::assertNotNull($session);
		[$actor, $open] = $this->takePackets($network);
		self::assertInstanceOf(AddActorPacket::class, $actor);
		self::assertSame(EntityIds::NPC, $actor->type);
		self::assertSame($actor->actorRuntimeId, $actor->actorUniqueId);
		$variant = $actor->metadata[EntityMetadataProperties::VARIANT];
		self::assertInstanceOf(IntMetadataProperty::class, $variant);
		self::assertSame(59, $variant->getValue());
		self::assertInstanceOf(NpcDialoguePacket::class, $open);
		self::assertSame(NpcDialoguePacket::ACTION_OPEN, $open->getActionType());
		self::assertSame($actor->actorRuntimeId, $open->getNpcActorUniqueId());
		self::assertSame($session->scene, $open->getSceneName());
		self::assertSame("Merchant", $open->getNpcName());
		self::assertSame("Choose an item", $open->getDialogue());
		self::assertSame([
			["button_name" => "Shop", "text" => "", "data" => null, "mode" => 0, "type" => 1],
			["button_name" => "Goodbye", "text" => "", "data" => null, "mode" => 0, "type" => 1],
		], json_decode($open->getActionJson(), true, flags: JSON_THROW_ON_ERROR));

		foreach([
			NpcRequestPacket::create($actor->actorRuntimeId + 1, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "", 0, $session->scene),
			NpcRequestPacket::create($actor->actorRuntimeId, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "", 0, $session->scene . "0"),
			NpcRequestPacket::create($actor->actorRuntimeId, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "", 255, $session->scene),
			NpcRequestPacket::create($actor->actorRuntimeId, NpcRequestPacket::REQUEST_SET_ACTIONS, "", 0, $session->scene),
			NpcRequestPacket::create($actor->actorRuntimeId, NpcRequestPacket::REQUEST_EXECUTE_OPENING_COMMANDS, "", 0, $session->scene),
		] as $invalid){
			$network->handleDataPacket(EncodedPacket::encode($invalid));
		}
		self::assertTrue($session->isOpen());
		self::assertSame([], $this->takePackets($network));

		$response = EncodedPacket::encode(NpcRequestPacket::create($actor->actorRuntimeId, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "irrelevant client payload", 0, $session->scene));
		$network->handleDataPacket($response);
		$network->handleDataPacket($response);
		self::assertSame([$player], $selections);
		self::assertFalse($session->isOpen());
		[$close, $remove] = $this->takePackets($network);
		self::assertInstanceOf(NpcDialoguePacket::class, $close);
		self::assertSame(NpcDialoguePacket::ACTION_CLOSE, $close->getActionType());
		self::assertSame($session->scene, $close->getSceneName());
		self::assertInstanceOf(RemoveActorPacket::class, $remove);
		self::assertSame($actor->actorUniqueId, $remove->actorUniqueId);
	}

	public function testDefinitionSnapshotsAreIsolatedAndCallbacksCanSendTheNextPage() : void{
		[$firstPlayer, $firstNetwork] = $this->connection();
		[$secondPlayer, $secondNetwork] = $this->connection();
		$next = NpcDialogue::create($this->owner)->setText("Next page");
		$callbackStates = [];
		$definition = NpcDialogue::create($this->owner)->addButton("Next", static function(Player $player) use ($next, &$callbackStates) : void{
			$callbackStates[] = $player->getNpcDialogue();
			$callbackStates[] = $next->send($player);
		});
		$first = $definition->send($firstPlayer);
		self::assertNotNull($first);
		$second = $definition->clearButtons()->addButton("Finish")->send($secondPlayer);
		self::assertNotNull($second);
		$this->takePackets($firstNetwork);
		$this->takePackets($secondNetwork);

		$firstResponse = self::response($first);
		$secondNetwork->handleDataPacket($firstResponse);
		self::assertSame($second, $secondPlayer->getNpcDialogue());
		$firstNetwork->handleDataPacket($firstResponse);
		$firstNetwork->handleDataPacket(self::response($first, NpcRequestPacket::REQUEST_EXECUTE_CLOSING_COMMANDS));
		self::assertCount(2, $callbackStates);
		self::assertNull($callbackStates[0]);
		self::assertInstanceOf(NpcDialogueSession::class, $callbackStates[1]);
		self::assertSame($callbackStates[1], $firstPlayer->getNpcDialogue());
		$this->assertPacketTypes([NpcDialoguePacket::class, RemoveActorPacket::class, AddActorPacket::class, NpcDialoguePacket::class], $firstNetwork);
		$secondNetwork->handleDataPacket(self::response($second));
		self::assertCount(2, $callbackStates);
		self::assertNull($secondPlayer->getNpcDialogue());
	}

	public function testUpdateReusesTheActorAndRotatesTheSceneWhileReplacementCreatesANewActor() : void{
		[$player, $network] = $this->connection();
		$definition = NpcDialogue::create($this->owner)->addButton("Finish");
		$opened = $definition->send($player);
		self::assertNotNull($opened);
		$this->takePackets($network);

		$updated = $opened->update($definition->setText("Updated"));
		self::assertNotNull($updated);
		self::assertSame($opened->actorId, $updated->actorId);
		self::assertNotSame($opened->scene, $updated->scene);
		self::assertFalse($opened->close());
		$network->handleDataPacket(self::response($opened));
		self::assertSame($updated, $player->getNpcDialogue());
		[$close, $open] = $this->takePackets($network);
		self::assertInstanceOf(NpcDialoguePacket::class, $close);
		self::assertSame($opened->scene, $close->getSceneName());
		self::assertInstanceOf(NpcDialoguePacket::class, $open);
		self::assertSame("Updated", $open->getDialogue());

		$replaced = $definition->send($player);
		self::assertNotNull($replaced);
		self::assertFalse($updated->isOpen());
		self::assertNotSame($updated->actorId, $replaced->actorId);
		$this->assertPacketTypes([NpcDialoguePacket::class, RemoveActorPacket::class, AddActorPacket::class, NpcDialoguePacket::class], $network);
	}

	public function testBothClosePathsRunOnceAndCanOpenAnotherDialogue() : void{
		[$player, $network] = $this->connection();
		$next = NpcDialogue::create($this->owner);
		$closed = [];
		$definition = NpcDialogue::create($this->owner)->onClose(static function(Player $player) use (&$closed, $next) : void{
			$closed[] = $next->send($player);
		});
		$clientClosed = $definition->send($player);
		self::assertNotNull($clientClosed);
		$this->takePackets($network);
		$close = self::response($clientClosed, NpcRequestPacket::REQUEST_EXECUTE_CLOSING_COMMANDS);
		$network->handleDataPacket($close);
		$network->handleDataPacket($close);
		self::assertCount(1, $closed);
		self::assertSame($closed[0], $player->getNpcDialogue());
		$this->assertPacketTypes([RemoveActorPacket::class, AddActorPacket::class, NpcDialoguePacket::class], $network);

		$serverClosed = $definition->send($player);
		self::assertNotNull($serverClosed);
		$this->takePackets($network);
		self::assertTrue($serverClosed->close());
		self::assertFalse($serverClosed->close());
		self::assertCount(2, $closed);
		self::assertSame($closed[1], $player->getNpcDialogue());
		$this->assertPacketTypes([NpcDialoguePacket::class, RemoveActorPacket::class, AddActorPacket::class, NpcDialoguePacket::class], $network);
	}

	public function testExistingActorRequiresVisibilityAndIsNeverRemovedByTheDialogue() : void{
		[$player, $network, $world] = $this->connection();
		$actor = $this->createMock(Entity::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($actor, true);
		$actor->method("isAlive")->willReturn(true);
		$actor->method("getId")->willReturn(1000);
		$actor->method("getWorld")->willReturn($world);
		$actor->method("getNetworkTypeId")->willReturn(EntityIds::NPC);
		$viewers = [];
		$actor->method("getViewers")->willReturnCallback(static function() use (&$viewers) : array{ return $viewers; });
		$world->method("getEntity")->with(1000)->willReturn($actor);
		$definition = NpcDialogue::create($this->owner)->addButton("Select");
		self::assertNull($definition->send($player, $actor));

		$viewers = [spl_object_id($player) => $player];
		$session = $definition->send($player, $actor);
		self::assertNotNull($session);
		self::assertSame(1000, $session->actorId);
		$this->assertPacketTypes([NpcDialoguePacket::class], $network);
		$network->getNpcDialogueController()->onActorRemoved(1001);
		self::assertTrue($session->isOpen());
		$network->getNpcDialogueController()->onActorRemoved(1000);
		self::assertFalse($session->isOpen());
		$this->assertPacketTypes([NpcDialoguePacket::class], $network);
	}

	public function testPortraitMetadataAndCustomSkinEncodeIntoTheClientPacketGroup() : void{
		[$player, $network] = $this->connection();
		$converter = $this->createMock(TypeConverter::class);
		$converter->method("getSkinAdapter")->willReturn(new LegacySkinAdapter());
		(new \ReflectionProperty(NetworkSession::class, "typeConverter"))->setValue($network, $converter);
		$definition = NpcDialogue::create($this->owner)
			->setEntityTexture(EntityIds::PIG)
			->setPortraitOffsets(new Vector3(1, -10, 3), new Vector3(2, 2, 2));
		$session = $definition->send($player);
		self::assertNotNull($session);
		[$actor] = $this->takePackets($network);
		self::assertInstanceOf(AddActorPacket::class, $actor);
		self::assertSame(EntityIds::PIG, $actor->type);
		$offsets = $actor->metadata[EntityMetadataProperties::NPC_SKIN_INDEX];
		self::assertInstanceOf(StringMetadataProperty::class, $offsets);
		self::assertSame([
			"picker_offsets" => ["scale" => [0, 0, 0], "translate" => [0, 0, 0]],
			"portrait_offsets" => ["scale" => [2, 2, 2], "translate" => [1, -10, 3]],
		], json_decode($offsets->getValue(), true, flags: JSON_THROW_ON_ERROR));

		$skin = new Skin("merchant", str_repeat("\xff", 64 * 32 * 4));
		$updated = $session->update($definition->setSkin($skin));
		self::assertNotNull($updated);
		self::assertNotSame($session->actorId, $updated->actorId);
		$packets = $this->takePackets($network);
		self::assertSame([NpcDialoguePacket::class, RemoveActorPacket::class, PlayerListPacket::class, AddPlayerPacket::class, PlayerListPacket::class, NpcDialoguePacket::class], array_map(static fn(Packet $packet) => $packet::class, $packets));
		$avatar = $packets[3];
		self::assertInstanceOf(AddPlayerPacket::class, $avatar);
		self::assertSame($updated->actorId, $avatar->actorRuntimeId);
	}

	public function testOwnerWorldAndDisconnectCleanupReleaseSessionState() : void{
		[$player, $network, $world] = $this->connection();
		$captured = new \stdClass();
		$reference = \WeakReference::create($captured);
		$closes = 0;
		$session = NpcDialogue::create($this->owner)->onClose(static function(Player $player) use ($captured, &$closes) : void{
			$captured->closed = ++$closes;
		})->send($player);
		self::assertNotNull($session);
		unset($captured);
		self::assertNotNull($reference->get());
		$network->getNpcDialogueController()->cancelForOwner($this->enabledPlugin());
		self::assertTrue($session->isOpen());
		$network->getNpcDialogueController()->cancelForOwner($this->owner);
		self::assertFalse($session->isOpen());
		self::assertNull($reference->get());
		self::assertSame(0, $closes);
		$this->takePackets($network);

		$definition = NpcDialogue::create($this->owner);
		$worldSession = $definition->send($player);
		self::assertNotNull($worldSession);
		$world->method("getDimension")->willReturn(DimensionRegistry::getInstance()->get(Dimension::OVERWORLD) ?? self::fail());
		$world->method("getSpawnLocation")->willReturn(new Position(1, 64, 2, $world));
		$world->method("getWeather")->willReturn(Weather::CLEAR);
		$network->onEnterWorld();
		self::assertFalse($worldSession->isOpen());
		$this->takePackets($network);

		$disconnected = $definition->send($player);
		self::assertNotNull($disconnected);
		$this->takePackets($network);
		$network->onClientDisconnect("Test disconnect");
		self::assertFalse($disconnected->isOpen());
		self::assertNull($player->getNpcDialogue());
		self::assertSame([], $this->takePackets($network));
	}

	public function testOutboundHooksCannotPartiallyReplaceOrReenterAQueuedDialogue() : void{
		[$player, $network] = $this->connection();
		$definition = NpcDialogue::create($this->owner);
		$opened = $definition->send($player);
		self::assertNotNull($opened);
		$this->takePackets($network);
		$reentered = [];
		$listener = static function(DataPacketSendEvent $event) use ($player, $definition, &$reentered) : void{
			$reentered[] = $definition->send($player);
			if(count($event->getPackets()) === 2){
				$event->cancel();
			}else{
				$event->setPackets([]);
			}
		};
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->register(new RegisteredListener($listener, EventPriority::NORMAL, $this->owner, false, Pulse::zone("test.npcdialogue")));

		self::assertNull($opened->update($definition));
		self::assertNull($definition->send($player));
		self::assertSame([null, null], $reentered);
		self::assertSame($opened, $player->getNpcDialogue());
		self::assertSame([], $this->takePackets($network));

		self::assertTrue($opened->close());
		$this->assertPacketTypes([NpcDialoguePacket::class, RemoveActorPacket::class], $network);
	}

	public function testMalformedAndOversizedWirePacketsLeaveTheDialoguePending() : void{
		[$player, $network] = $this->connection();
		$session = NpcDialogue::create($this->owner)->addButton("Finish")->send($player);
		self::assertNotNull($session);
		$this->takePackets($network);
		foreach([
			substr(self::response($session), 0, -1),
			EncodedPacket::encode(NpcRequestPacket::create($session->actorId, NpcRequestPacket::REQUEST_EXECUTE_ACTION, str_repeat("x", 2048), 0, $session->scene)),
		] as $invalid){
			try{
				$network->handleDataPacket($invalid);
				self::fail("Invalid dialogue response decoded");
			}catch(PacketHandlingException){
				self::assertTrue($session->isOpen());
			}
		}
	}

	private function enabledPlugin() : Plugin{
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		return $owner;
	}

	/** @return array{Player, NetworkSession, World&\PHPUnit\Framework\MockObject\MockObject} */
	private function connection() : array{
		$network = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(["isAlive", "getPosition", "getWorld", "onPostDisconnect"])->getMock();
		$logger = $this->createMock(\Logger::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $logger);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $network);
		$player->spawned = true;
		$player->method("isAlive")->willReturn(true);
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$player->method("getWorld")->willReturn($world);
		$player->method("getPosition")->willReturn(new Position(1, 64, 2, $world));

		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(InGamePacketHandler::class, "session"))->setValue($handler, $network);
		$server = $this->createMock(Server::class);
		$server->method("getLanguage")->willReturn(new Language(Language::FALLBACK_LANGUAGE));
		foreach([
			"loggedIn" => true,
			"player" => $player,
			"logger" => new \PrefixedLogger($logger, "dialogue test"),
			"handler" => $handler,
			"handlerActions" => PacketHandlerInspector::getHandlerActions($handler),
			"protocolVersion" => ProtocolInfo::CURRENT_PROTOCOL,
			"sender" => $this->createMock(PacketSender::class),
			"disposeHooks" => new ObjectSet(),
			"compressedQueue" => new \SplQueue(),
			"server" => $server,
		] as $property => $value){
			(new \ReflectionProperty(NetworkSession::class, $property))->setValue($network, $value);
		}
		return [$player, $network, $world];
	}

	private static function response(NpcDialogueSession $session, int $type = NpcRequestPacket::REQUEST_EXECUTE_ACTION) : string{
		return EncodedPacket::encode(NpcRequestPacket::create($session->actorId, $type, "", 0, $session->scene));
	}

	/** @return list<Packet> */
	private function takePackets(NetworkSession $network) : array{
		$property = new \ReflectionProperty(NetworkSession::class, "sendBuffer");
		/** @var list<string> $buffers */
		$buffers = $property->getValue($network);
		$property->setValue($network, []);
		return array_map(static fn(string $buffer) => EncodedPacket::fromBytes($buffer)->getPacket(), $buffers);
	}

	/** @param list<class-string<Packet>> $types */
	private function assertPacketTypes(array $types, NetworkSession $network) : void{
		self::assertSame($types, array_map(static fn(Packet $packet) => $packet::class, $this->takePackets($network)));
	}
}
