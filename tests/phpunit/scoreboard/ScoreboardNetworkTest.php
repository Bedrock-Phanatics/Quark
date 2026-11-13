<?php

declare(strict_types=1);

namespace quark\scoreboard;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;

final class ScoreboardNetworkTest extends TestCase{
	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->clear();
		Pulse::reset();
	}

	private function player() : ScoreboardNetworkTestPlayer{
		$session = new \ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(NetworkSession::class, "loggedIn")->setValue($session, true);
		$player = new \ReflectionClass(ScoreboardNetworkTestPlayer::class)->newInstanceWithoutConstructor();
		$player->session = $session;
		$player->spawned = true;
		return $player;
	}

	/** @param \Closure(DataPacketSendEvent): void $callback */
	private function listen(\Closure $callback) : void{
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->register(new RegisteredListener(
			$callback, EventPriority::NORMAL, $this->createMock(Plugin::class), false, Pulse::zone("test.scoreboard_send")
		));
	}

	/** @return list<string> */
	private function buffer(NetworkSession $session) : array{
		/** @var list<string> $buffer */
		$buffer = new \ReflectionProperty(NetworkSession::class, "sendBuffer")->getValue($session);
		return $buffer;
	}

	public function testCancelledRowsRetryWithoutRepeatingAcceptedObjective() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A"]);
		$this->listen(static function(DataPacketSendEvent $event) : void{
			if($event->getPackets()[0] instanceof SetScorePacket){
				$event->cancel();
			}
		});
		try{
			$board->setLine(0, "B");
			self::fail("Cancelled row change was accepted");
		}catch(\RuntimeException){}
		self::assertCount(2, $this->buffer($player->session));
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->clear();
		$board->show();
		$buffer = $this->buffer($player->session);
		self::assertCount(3, $buffer);
		$packet = EncodedPacket::fromBytes($buffer[2])->getPacket();
		self::assertInstanceOf(SetScorePacket::class, $packet);
		self::assertSame([ScorePacketEntryAction::REMOVE, ScorePacketEntryAction::CHANGE_FAKE_PLAYER], [$packet->getEntries()[0]->action, $packet->getEntries()[1]->action]);
		self::assertSame([-2, -2], [$packet->getEntries()[0]->scoreboardId, $packet->getEntries()[1]->scoreboardId]);
		$board->show();
		self::assertCount(3, $this->buffer($player->session));
	}

	public function testDisconnectDuringSendHookDoesNotQueueStaleRows() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A"]);
		$this->listen(static function(DataPacketSendEvent $event) use ($player, $board) : void{
			new \ReflectionProperty(NetworkSession::class, "disconnectGuard")->setValue($player->session, true);
			$board->close();
		});
		try{
			$board->setLine(0, "B");
			self::fail("Disconnected scoreboard transmission succeeded");
		}catch(\RuntimeException){}
		self::assertCount(2, $this->buffer($player->session));
		self::assertFalse($board->isVisible());
		self::assertSame([], $board->getLines());
		$this->expectException(\LogicException::class);
		$board->show();
	}

	public function testSendHookCannotReenterAndCorruptQueuedContent() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$this->listen(static function(DataPacketSendEvent $event) use ($board) : void{
			try{
				$board->update("Reentered", ["Wrong"]);
				self::fail("Reentered scoreboard mutation succeeded");
			}catch(\LogicException){}
		});
		$board->update("Title", ["A"]);
		$buffer = $this->buffer($player->session);
		self::assertCount(2, $buffer);
		$objective = EncodedPacket::fromBytes($buffer[0])->getPacket();
		self::assertInstanceOf(SetDisplayObjectivePacket::class, $objective);
		self::assertSame("Title", $objective->displayName);
		self::assertSame(["A"], $board->getLines());
		$board->show();
		self::assertCount(2, $this->buffer($player->session));
	}
}

final class ScoreboardNetworkTestPlayer extends Player{
	public NetworkSession $session;

	public function __destruct(){}

	public function isConnected() : bool{
		return $this->session->isConnected();
	}

	public function getNetworkSession() : NetworkSession{
		return $this->session;
	}
}
