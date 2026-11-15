<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\StandardEntityEventBroadcaster;
use quark\network\mcpe\StandardPacketBroadcaster;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\world\World;
use function class_exists;
use function spl_object_id;

final class CameraQueueTest extends TestCase{
	protected function setUp() : void{
		if(!class_exists(ByteBufferWriter::class)){
			self::markTestSkipped("Requires ext-encoding");
		}
		PulseZones::init();
	}

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll();
	}

	public function testConditionRunsAfterSendHooksAndRejectedPacketIsNeverQueued() : void{
		$session = $this->session();
		$valid = true;
		$this->onSend(static function(DataPacketSendEvent $event) use (&$valid) : void{ $valid = false; });
		self::assertFalse($session->sendDataPacketIf(SetTimePacket::create(10), static function() use (&$valid) : bool{ return $valid; }));
		self::assertSame([], $this->buffer($session));
	}

	public function testAcceptedConditionalPacketIsSerializedNormally() : void{
		$session = $this->session();
		self::assertTrue($session->sendDataPacketIf(SetTimePacket::create(10), static fn() => true));
		self::assertCount(1, $this->buffer($session));
	}

	public function testDisconnectInSendHookRejectsOrdinaryPacketsToo() : void{
		$session = $this->session();
		$this->onSend(static function(DataPacketSendEvent $event) use ($session) : void{
			(new \ReflectionProperty(NetworkSession::class, "connected"))->setValue($session, false);
		});
		self::assertFalse($session->sendDataPacket(SetTimePacket::create(10)));
		self::assertSame([], $this->buffer($session));
	}

	public function testTargetRemovalInSendHookRejectsCameraPacketBeforeEncoding() : void{
		$session = $this->session();
		[$camera, $player] = $this->camera($session);
		$this->onSend(static function(DataPacketSendEvent $event) use ($camera, $player) : void{
			foreach($event->getPackets() as $packet){
				if($packet instanceof CameraInstructionPacket && $packet->getTarget() !== null){
					$camera->onEntityRemoved($player->getId());
				}
			}
		});
		try{
			$camera->track($player);
			self::fail("A stale target instruction was queued");
		}catch(\RuntimeException){
			self::assertSame([], $this->buffer($session));
		}
	}

	public function testCancelWithoutResetInSendHookRejectsPendingTimelineInstruction() : void{
		$this->cancelDuringSend(false);
	}

	public function testCancelWithResetInSendHookRestoresPreviousEffects() : void{
		$this->cancelDuringSend(true);
	}

	private function cancelDuringSend(bool $reset) : void{
		$session = $this->session();
		[$camera, $player] = $this->camera($session);
		$camera->set();
		$camera->fov(80);
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$playback = $camera->play((new CameraTimeline())->awaitSignal("send")->instruction(CameraInstruction::fov(90))->awaitSignal("done"), $owner);
		(new \ReflectionProperty(NetworkSession::class, "sendBuffer"))->setValue($session, []);
		$this->onSend(static function(DataPacketSendEvent $event) use ($playback, $reset) : void{
			foreach($event->getPackets() as $packet){
				if($packet instanceof CameraInstructionPacket && $packet->getFieldOfView()?->getFieldOfView() === 90.0){
					$playback->cancel($reset);
				}
			}
		});
		try{
			$playback->signal("send");
			self::fail("A cancelled timeline instruction was queued");
		}catch(\RuntimeException){
			self::assertFalse($playback->isRunning());
		}
		$buffer = $this->buffer($session);
		self::assertCount($reset ? 2 : 0, $buffer);
		self::assertSame($reset ? null : CameraPreset::FREE, $camera->getPreset());
		foreach($buffer as $bytes){
			$packet = new CameraInstructionPacket();
			$packet->decode(new ByteBufferReader($bytes));
			self::assertTrue($packet->getClear() === true || $packet->getFieldOfView()?->getClear() === true);
		}
	}

	public function testDespawnFromRejectsRetargetingAndDuplicateRemovalInsidePacketHook() : void{
		$this->despawnDuringSend(false);
	}

	public function testDespawnFromAllRejectsRetargetingAndDuplicateRemovalInsidePacketHook() : void{
		$this->despawnDuringSend(true);
	}

	public function testDespawnDuringMovementFlushDoesNotDuplicateRemoval() : void{
		$this->despawnDuringSend(false, true);
	}

	private function despawnDuringSend(bool $all, bool $duringMovement = false) : void{
		$session = $this->session();
		[$camera, $player] = $this->camera($session);
		$entity = (new \ReflectionClass(CameraQueuePlayer::class))->newInstanceWithoutConstructor();
		$entity->testWorld = $player->testWorld;
		$entity->testId = 2;
		(new \ReflectionProperty(\quark\entity\Entity::class, "hasSpawned"))->setValue($entity, [spl_object_id($player) => $player]);
		(new \ReflectionProperty(NetworkSession::class, "player"))->setValue($session, $player);
		$broadcaster = new StandardPacketBroadcaster($this->createMock(Server::class));
		(new \ReflectionProperty(NetworkSession::class, "broadcaster"))->setValue($session, $broadcaster);
		(new \ReflectionProperty(NetworkSession::class, "entityEventBroadcaster"))->setValue($session, new StandardEntityEventBroadcaster(
			$broadcaster,
			(new \ReflectionClass(TypeConverter::class))->newInstanceWithoutConstructor()
		));
		if($duringMovement){
			(new \ReflectionProperty(\quark\entity\Entity::class, "pendingMovement"))->setValue($entity, [SetTimePacket::create(10)]);
		}
		$state = new \stdClass();
		$state->removals = 0;
		$this->onSend(static function(DataPacketSendEvent $event) use ($camera, $entity, $player, $all, $state) : void{
			foreach($event->getPackets() as $packet){
				if($packet instanceof SetTimePacket){
					$entity->despawnFrom($player);
				}
				if($packet instanceof RemoveActorPacket){
					++$state->removals;
					if($state->removals === 1){
						if($all){
							$entity->despawnFromAll();
						}else{
							$entity->despawnFrom($player);
						}
					}
					try{
						$camera->track($entity);
						self::fail("A despawning actor was accepted as a target");
					}catch(\InvalidArgumentException){
						self::assertSame([], $entity->getViewers());
					}
				}
			}
		});
		if($all){
			$entity->despawnFromAll();
		}else{
			$entity->despawnFrom($player);
		}
		self::assertSame(1, $state->removals);
		self::assertCount($duringMovement ? 2 : 1, $this->buffer($session));
		self::assertSame([], $entity->getViewers());
	}

	/** @return array{CameraController, CameraQueuePlayer} */
	private function camera(NetworkSession $session) : array{
		$player = (new \ReflectionClass(CameraQueuePlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		$player->spawned = true;
		$player->testWorld = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$camera = new CameraController($player, new CameraPresetRegistry(), new CameraAimAssistRegistry());
		(new \ReflectionProperty(Player::class, "camera"))->setValue($player, $camera);
		$camera->synchronize();
		(new \ReflectionProperty(NetworkSession::class, "sendBuffer"))->setValue($session, []);
		return [$camera, $player];
	}

	private function session() : NetworkSession{
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(NetworkSession::class, "loggedIn"))->setValue($session, true);
		return $session;
	}

	/** @return list<string> */
	private function buffer(NetworkSession $session) : array{
		/** @var list<string> $buffer */
		$buffer = (new \ReflectionProperty(NetworkSession::class, "sendBuffer"))->getValue($session);
		return $buffer;
	}

	/** @param \Closure(DataPacketSendEvent): void $handler */
	private function onSend(\Closure $handler) : void{
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->register(new RegisteredListener(
			$handler, EventPriority::NORMAL, $this->createMock(Plugin::class), false, Pulse::zone("test.camera.send")
		));
	}
}

final class CameraQueuePlayer extends Player{
	public World $testWorld;
	public int $testId = 1;
	public function __destruct(){}
	public function isSpectator() : bool{ return false; }
	public function getId() : int{ return $this->testId; }
	public function getWorld() : World{ return $this->testWorld; }
}
