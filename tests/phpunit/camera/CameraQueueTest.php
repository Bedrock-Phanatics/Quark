<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\world\World;
use function class_exists;

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
		$player = (new \ReflectionClass(CameraQueuePlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		$player->spawned = true;
		$player->testWorld = $this->getMockBuilder(World::class)->disableOriginalConstructor()->getMock();
		$camera = new CameraController($player, new CameraPresetRegistry(), new CameraAimAssistRegistry());
		$camera->synchronize();
		(new \ReflectionProperty(NetworkSession::class, "sendBuffer"))->setValue($session, []);
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
	public function __destruct(){}
	public function isSpectator() : bool{ return false; }
	public function getId() : int{ return 1; }
	public function getWorld() : World{ return $this->testWorld; }
}
