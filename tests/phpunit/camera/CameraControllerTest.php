<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\CameraAimAssistPresetsPacket;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetHudPacket;
use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\scheduler\TaskScheduler;
use quark\world\World;
use function spl_object_id;

final class CameraControllerTest extends TestCase{
	public function testSyncPrecedesInstructionsAndIsNotRepeated() : void{
		[$camera, $player, $session] = $this->camera();
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use (&$packets) : bool{
			$packets[] = $packet;
			return true;
		});
		self::assertFalse($camera->isReady());
		$camera->synchronize();
		$camera->synchronize();
		$camera->set();
		self::assertCount(3, $packets);
		self::assertInstanceOf(CameraPresetsPacket::class, $packets[0]);
		self::assertInstanceOf(CameraAimAssistPresetsPacket::class, $packets[1]);
		self::assertInstanceOf(CameraInstructionPacket::class, $packets[2]);
		self::assertSame(CameraPreset::FREE, $camera->getPreset());
		$camera->clear();
		self::assertNull($camera->getPreset());
		self::assertCount(4, $packets);
		self::assertInstanceOf(CameraInstructionPacket::class, $packets[3]);
		self::assertTrue($packets[3]->getClear());
		$camera->clear();
		self::assertCount(4, $packets);
	}

	public function testUnreadyOperationsFailInsteadOfQueueing() : void{
		[$camera, $player, $session] = $this->camera();
		$session->expects(self::never())->method("sendDataPacket");
		$this->expectException(\LogicException::class);
		$camera->set();
	}

	public function testFailedSyncCanRetryWithoutRepeatingAcceptedPresets() : void{
		[$camera, $player, $session] = $this->camera();
		$session->expects(self::exactly(3))->method("sendDataPacket")->willReturn(true, false, true);
		try{
			$camera->synchronize();
			self::fail("Rejected synchronization must fail");
		}catch(\RuntimeException){
			self::assertFalse($camera->isReady());
		}
		$camera->synchronize();
		self::assertTrue($camera->isReady());
	}

	public function testFailedInstructionPreservesQueuedState() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true, true, true, false, true);
		$camera->synchronize();
		$camera->set();
		try{
			$camera->send(CameraInstruction::clear());
			self::fail("Rejected instruction must fail");
		}catch(\RuntimeException){
			self::assertSame(CameraPreset::FREE, $camera->getPreset());
		}
		$camera->clear();
		self::assertNull($camera->getPreset());
	}

	public function testResetRestoresOnlyChangedEffects() : void{
		[$camera, $player, $session] = $this->camera();
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use (&$packets) : bool{ $packets[] = $packet; return true; });
		$camera->synchronize();
		$camera->fov(90, 1);
		$camera->send(CameraInstruction::hud(HudVisibility::HIDE, HudElement::CROSSHAIR));
		$camera->clear();
		self::assertCount(6, $packets);
		self::assertInstanceOf(CameraInstructionPacket::class, $packets[4]);
		$fov = $packets[4]->getFieldOfView();
		self::assertNotNull($fov);
		self::assertTrue($fov->getClear());
		self::assertInstanceOf(SetHudPacket::class, $packets[5]);
		self::assertSame([HudElement::CROSSHAIR], $packets[5]->getHudElements());
		self::assertSame(HudVisibility::RESET, $packets[5]->getVisibility());
	}

	public function testContextChangeDuringTransmissionResetsAfterInstruction() : void{
		[$camera, $player, $session] = $this->camera();
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use ($camera, &$packets) : bool{
			$packets[] = $packet;
			if($packet instanceof CameraInstructionPacket && $packet->getSet() !== null){
				$camera->resetForContext();
			}
			return true;
		});
		$camera->synchronize();
		$camera->set();
		self::assertNull($camera->getPreset());
		self::assertCount(4, $packets);
		self::assertInstanceOf(CameraInstructionPacket::class, $packets[3]);
		self::assertTrue($packets[3]->getClear());
	}

	public function testDisconnectCancelsSignalWaitAndReleasesCallbacks() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$owner->method("getScheduler")->willReturn(new TaskScheduler());
		$captured = new \stdClass();
		$weak = \WeakReference::create($captured);
		$timeline = (new CameraTimeline())->awaitSignal("done")->callback(static function(CameraPlayback $playback) use ($captured) : void{ $captured->used = true; });
		$playback = $camera->play($timeline, $owner);
		unset($timeline, $captured);
		$camera->resetForContext(disconnect: true);
		self::assertFalse($playback->isRunning());
		self::assertFalse($playback->signal("done"));
		self::assertNull($weak->get());
		self::assertFalse($camera->isReady());
	}

	public function testRepeatedSessionsDoNotRetainPlayers() : void{
		for($i = 0; $i < 20; ++$i){
			[$camera, $player, $session] = $this->camera();
			$session->method("sendDataPacket")->willReturn(true);
			$camera->synchronize();
			$camera->set();
			$weak = \WeakReference::create($player);
			$camera->resetForContext(disconnect: true);
			unset($player);
			self::assertNull($weak->get());
			self::assertFalse($camera->isReady());
		}
	}

	public function testEntityRemovalResetsBeforeItCanBeReused() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$world = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$player->testWorld = $world;
		$entity = CameraTestPlayer::create($session);
		$entity->testWorld = $world;
		$entity->viewers = [spl_object_id($player) => $player];
		$camera->set();
		$camera->track($entity);
		$camera->onEntityRemoved($entity->getId());
		self::assertNull($camera->getPreset());
	}

	public function testWorldContextCancelsDelayedPlaybackAndRestoresFov() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$scheduler = new TaskScheduler();
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$owner->method("getScheduler")->willReturn($scheduler);
		$playback = $camera->play((new CameraTimeline())->instruction(CameraInstruction::fov(100))->wait(1)->instruction(CameraInstruction::set()), $owner);
		$camera->resetForContext();
		$scheduler->mainThreadHeartbeat(20);
		self::assertFalse($playback->isRunning());
		self::assertNull($camera->getPreset());
	}

	public function testInvalidReplacementDoesNotCancelExistingTimeline() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$playback = $camera->play((new CameraTimeline())->awaitSignal("continue"), $owner);
		try{
			$camera->set("test:missing");
			self::fail("Unknown preset was accepted");
		}catch(\InvalidArgumentException){
			self::assertTrue($playback->isRunning());
		}
		$camera->cancelForOwner($this->createMock(Plugin::class));
		self::assertTrue($playback->isRunning());
		$camera->cancelForOwner($owner);
		self::assertFalse($playback->isRunning());
	}

	public function testDeadAndSpectatorPlayersRejectInstructions() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$player->spectator = true;
		self::assertFalse($camera->isReady());
		$player->spectator = false;
		$player->alive = false;
		self::assertFalse($camera->isReady());
		$this->expectException(\LogicException::class);
		$camera->set();
	}

	public function testTrackingRejectsInvisibleAndCrossWorldEntities() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$world = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$player->testWorld = $world;
		$entity = CameraTestPlayer::create($session);
		$entity->testWorld = $world;
		try{
			$camera->track($entity);
			self::fail("An unspawned target was accepted");
		}catch(\InvalidArgumentException){
			self::assertNull($camera->getPreset());
		}
		$entity->viewers = [spl_object_id($player) => $player];
		$entity->testWorld = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$this->expectException(\InvalidArgumentException::class);
		$camera->attach($entity);
	}

	public function testPacketHooksCannotReenterCameraInstructions() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use ($camera) : bool{
			if($packet instanceof CameraInstructionPacket){
				$camera->set();
			}
			return true;
		});
		$camera->synchronize();
		$this->expectException(\LogicException::class);
		$camera->set();
	}

	public function testActorRemovedDuringFirstTrackSendDoesNotLeaveStaleTracking() : void{
		[$camera, $player, $session] = $this->camera();
		$world = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$player->testWorld = $world;
		$entity = CameraTestPlayer::create($session);
		$entity->testWorld = $world;
		$entity->viewers = [spl_object_id($player) => $player];
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use ($camera, $entity, &$packets) : bool{
			$packets[] = $packet;
			if($packet instanceof CameraInstructionPacket && $packet->getTarget() !== null){
				$entity->connected = false;
				$camera->onEntityRemoved($entity->getId());
			}
			return true;
		});
		$camera->synchronize();
		$camera->track($entity);
		self::assertCount(4, $packets);
		self::assertInstanceOf(CameraInstructionPacket::class, $packets[3]);
		self::assertTrue($packets[3]->getRemoveTarget());
		$camera->clear();
		self::assertCount(4, $packets);
	}

	public function testContextChangeDuringSyncDoesNotClearTheNextInstruction() : void{
		[$camera, $player, $session] = $this->camera();
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use ($camera, &$packets) : bool{
			$packets[] = $packet;
			if($packet instanceof CameraPresetsPacket){
				$camera->resetForContext();
			}
			return true;
		});
		$camera->synchronize();
		$camera->set();
		self::assertSame(CameraPreset::FREE, $camera->getPreset());
		self::assertCount(3, $packets);
	}

	public function testContextChangeDuringResetDoesNotClearTheNextInstruction() : void{
		[$camera, $player, $session] = $this->camera();
		$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use ($camera, &$packets) : bool{
			$packets[] = $packet;
			if($packet instanceof CameraInstructionPacket && $packet->getClear() === true){
				$camera->resetForContext();
			}
			return true;
		});
		$camera->synchronize();
		$camera->set();
		$camera->clear();
		$camera->set();
		self::assertSame(CameraPreset::FREE, $camera->getPreset());
		self::assertCount(5, $packets);
	}

	public function testClientOptOutAlsoRejectsInheritedPresetAimAssist() : void{
		[$unused, $player, $session] = $this->camera();
		$presets = new CameraPresetRegistry();
		$presets->register(new CameraPreset("test:parent", aimAssist: new \pocketmine\network\mcpe\protocol\types\camera\CameraPresetAimAssist(null, null, null, 2)));
		$presets->register(new CameraPreset("test:child", parent: "test:parent"));
		$camera = new CameraController($player, $presets, new CameraAimAssistRegistry());
		$session->expects(self::exactly(2))->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$camera->onClientAimAssist(false);
		self::assertFalse($camera->clientAllowsAimAssist());
		$this->expectException(\LogicException::class);
		$camera->set("test:child");
	}

	public function testOwnerDisableRestoresStateKeptAfterTimelineCompletion() : void{
		[$camera, $player, $session] = $this->camera();
		$session->method("sendDataPacket")->willReturn(true);
		$camera->synchronize();
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$playback = $camera->play((new CameraTimeline())->instruction(CameraInstruction::set())->resetAfter(false), $owner);
		self::assertFalse($playback->isRunning());
		self::assertSame(CameraPreset::FREE, $camera->getPreset());
		$camera->cancelForOwner($owner);
		self::assertNull($camera->getPreset());
	}

	/** @return array{CameraController, CameraTestPlayer, NetworkSession&\PHPUnit\Framework\MockObject\MockObject} */
	private function camera() : array{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["sendDataPacket", "sendDataPacketIf"])->getMock();
		$session->method("sendDataPacketIf")->willReturnCallback(static fn(ClientboundPacket $packet, \Closure $condition) => $condition() && $session->sendDataPacket($packet));
		$player = CameraTestPlayer::create($session);
		$player->spawned = true;
		return [new CameraController($player, new CameraPresetRegistry(), new CameraAimAssistRegistry()), $player, $session];
	}
}

final class CameraTestPlayer extends Player{
	public bool $connected = true;
	public bool $alive = true;
	public bool $spectator = false;
	public ?World $testWorld = null;
	/** @var array<int, Player> */
	public array $viewers = [];

	private NetworkSession $session;
	public static function create(NetworkSession $session) : self{
		$player = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$player->session = $session;
		return $player;
	}
	public function __destruct(){}
	public function isConnected() : bool{ return $this->connected; }
	public function isAlive() : bool{ return $this->alive; }
	public function isClosed() : bool{ return !$this->connected; }
	public function isSpectator() : bool{ return $this->spectator; }
	public function getNetworkSession() : NetworkSession{ return $this->session; }
	public function getWorld() : World{ return $this->testWorld ?? throw new \LogicException("No test world"); }
	public function getId() : int{ return 123; }
	public function getViewers() : array{ return $this->viewers; }
}
