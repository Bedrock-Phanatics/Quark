<?php

declare(strict_types=1);

namespace quark\scoreboard;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use quark\utils\TextFormat;
use function array_column;
use function array_fill;
use function array_map;
use function array_unique;
use function str_repeat;

final class ScoreboardTest extends TestCase{
	/** @var list<ClientboundPacket> */
	private array $packets = [];
	/** @var (\Closure(ClientboundPacket): bool)|null */
	private ?\Closure $sendHook = null;

	/** @return list<ClientboundPacket> */
	private function getPackets() : array{
		return $this->packets;
	}

	private function player() : ScoreboardTestPlayer{
		$player = new \ReflectionClass(ScoreboardTestPlayer::class)->newInstanceWithoutConstructor();
		$player->spawned = true;
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["sendDataPacketIf"])->getMock();
		$session->method("sendDataPacketIf")->willReturnCallback(function(ClientboundPacket $packet, \Closure $condition) : bool{
			if($this->sendHook !== null && !($this->sendHook)($packet)){
				return false;
			}
			if(!$condition()){
				return false;
			}
			$this->packets[] = $packet;
			return true;
		});
		$player->testSession = $session;
		return $player;
	}

	/** @param \Closure(): void $operation */
	private function rejected(\Closure $operation) : void{
		try{
			$operation();
			self::fail("Expected rejected scoreboard operation");
		}catch(\RuntimeException){
		}
	}

	public function testInitialRowsNoOpAndWireRoundTrip() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		self::assertSame($board, $player->getScoreboard());
		self::assertFalse($board->isVisible());
		$board->update("Title", ["First", "", ""]);
		self::assertCount(2, $this->packets);
		$objective = $this->packets[0];
		self::assertInstanceOf(SetDisplayObjectivePacket::class, $objective);
		self::assertSame("sidebar", $objective->displaySlot);
		self::assertSame("dummy", $objective->criteriaName);
		self::assertSame(SetDisplayObjectivePacket::SORT_ORDER_DESCENDING, $objective->sortOrder);
		$packet = $this->packets[1];
		self::assertInstanceOf(SetScorePacket::class, $packet);
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new SetScorePacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		$entries = $decoded->getEntries();
		self::assertSame([-1, -2, -3], array_column($entries, "scoreboardId"));
		self::assertSame([15, 14, 13], array_column($entries, "score"));
		self::assertSame(["First", "", ""], array_map(static function(ScorePacketEntry $entry) : string{
			self::assertNotNull($entry->customName);
			return TextFormat::clean($entry->customName);
		}, $entries));
		self::assertNotSame($entries[1]->customName, $entries[2]->customName);
		foreach($entries as $entry){
			self::assertSame(ScorePacketEntryAction::CHANGE_FAKE_PLAYER, $entry->action);
			self::assertSame($objective->objectiveName, $entry->objectiveName);
		}
		$board->update("Title", ["First", "", ""]);
		$board->setLine(0, "First");
		$board->show();
		self::assertCount(2, $this->packets);
		self::assertTrue($board->isVisible());
	}

	public function testChangesAndSparseRemovals() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A", "B", "C"]);
		$this->packets = [];
		$board->setLine(1, "Changed");
		self::assertCount(1, $this->packets);
		$packet = $this->packets[0];
		self::assertInstanceOf(SetScorePacket::class, $packet);
		self::assertSame([ScorePacketEntryAction::REMOVE, ScorePacketEntryAction::CHANGE_FAKE_PLAYER], array_column($packet->getEntries(), "action"));
		self::assertSame([-2, -2], array_column($packet->getEntries(), "scoreboardId"));
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new SetScorePacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		self::assertSame($packet->getEntries()[0]->objectiveName, $decoded->getEntries()[0]->objectiveName);
		$this->packets = [];
		$board->removeLine(1);
		self::assertSame([0 => "A", 2 => "C"], $board->getLines());
		self::assertNull($board->getLine(1));
		$board->setLines(["A"]);
		$board->clear();
		$removals = $this->getPackets();
		self::assertCount(3, $removals);
		foreach($removals as $removal){
			self::assertInstanceOf(SetScorePacket::class, $removal);
			self::assertCount(1, $removal->getEntries());
			self::assertSame(ScorePacketEntryAction::REMOVE, $removal->getEntries()[0]->action);
		}
		self::assertSame([], $board->getLines());
	}

	public function testHideShowAndTitleRecreation() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A"]);
		$this->packets = [];
		$board->hide();
		$board->hide();
		$board->update("Hidden", ["New"]);
		self::assertCount(1, $this->packets);
		self::assertInstanceOf(RemoveObjectivePacket::class, $this->packets[0]);
		self::assertFalse($board->isVisible());
		$board->show();
		self::assertCount(3, $this->packets);
		self::assertTrue($board->isVisible());
		$this->packets = [];
		$board->setTitle("Updated");
		self::assertCount(3, $this->packets);
		self::assertInstanceOf(RemoveObjectivePacket::class, $this->packets[0]);
		self::assertInstanceOf(SetDisplayObjectivePacket::class, $this->packets[1]);
		self::assertSame("Updated", $this->packets[1]->displayName);
		self::assertInstanceOf(SetScorePacket::class, $this->packets[2]);
		$name = $this->packets[2]->getEntries()[0]->customName;
		self::assertNotNull($name);
		self::assertSame("New", TextFormat::clean($name));
	}

	public function testCancellationRetriesOnlyUncommittedStages() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$this->sendHook = static fn(ClientboundPacket $packet) : bool => false;
		$this->rejected(fn() => $board->update("Title", ["A"]));
		self::assertSame([], $this->packets);
		self::assertFalse($board->isVisible());
		$this->sendHook = static fn(ClientboundPacket $packet) : bool => !$packet instanceof SetScorePacket;
		$this->rejected(fn() => $board->update("Title", ["A"]));
		self::assertCount(1, $this->packets);
		self::assertTrue($board->isVisible());
		$this->sendHook = null;
		$board->update("Title", ["A"]);
		self::assertCount(2, $this->packets);
		$this->packets = [];
		$this->sendHook = static fn(ClientboundPacket $packet) : bool => !$packet instanceof RemoveObjectivePacket;
		$this->rejected(fn() => $board->setTitle("New"));
		self::assertCount(0, $this->packets);
		$this->sendHook = static fn(ClientboundPacket $packet) : bool => !$packet instanceof SetDisplayObjectivePacket;
		$this->rejected(fn() => $board->setTitle("New"));
		self::assertCount(1, $this->packets);
		self::assertFalse($board->isVisible());
		$this->sendHook = null;
		$board->setTitle("New");
		self::assertCount(3, $this->packets);
		self::assertInstanceOf(SetDisplayObjectivePacket::class, $this->packets[1]);
		self::assertInstanceOf(SetScorePacket::class, $this->packets[2]);
	}

	public function testInvalidUpdatesAreAtomic() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A"]);
		$this->packets = [];
		foreach([[-1, "x"], [15, "x"], [0, "a\nb"], [0, "\xff"], [0, "a\0b"], [0, str_repeat("x", Scoreboard::MAX_LINE_BYTES + 1)]] as [$line, $text]){
			try{
				$board->setLine($line, $text);
				self::fail("Accepted invalid row");
			}catch(\InvalidArgumentException){
			}
		}
		foreach([[1 => "gap"], array_fill(0, 16, "x"), [123], ["bad\rline"]] as $lines){
			try{
				$board->update("Uncommitted", $lines); // @phpstan-ignore argument.type
				self::fail("Accepted invalid lines");
			}catch(\InvalidArgumentException){
			}
		}
		try{
			$board->update(str_repeat("x", Scoreboard::MAX_TITLE_BYTES + 1), ["B"]);
			self::fail("Accepted oversized title");
		}catch(\InvalidArgumentException){
		}
		self::assertSame("Title", $board->getTitle());
		self::assertSame(["A"], $board->getLines());
		self::assertSame([], $this->packets);
		$board->update(str_repeat("x", Scoreboard::MAX_TITLE_BYTES), [str_repeat("x", Scoreboard::MAX_LINE_BYTES)]);
		self::assertCount(3, $this->packets);
	}

	public function testRejectedRowChangesCanBeRetriedOrReverted() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Title", ["A"]);
		$this->packets = [];
		$this->sendHook = static fn(ClientboundPacket $packet) : bool => false;
		$this->rejected(fn() => $board->setLine(0, "B"));
		self::assertSame("B", $board->getLine(0));
		$board->setLine(0, "A");
		self::assertSame([], $this->packets);
		$this->rejected(fn() => $board->setLine(0, "B"));
		$this->sendHook = null;
		$board->show();
		self::assertCount(1, $this->packets);
		self::assertInstanceOf(SetScorePacket::class, $this->packets[0]);
		self::assertSame([ScorePacketEntryAction::REMOVE, ScorePacketEntryAction::CHANGE_FAKE_PLAYER], array_column($this->packets[0]->getEntries(), "action"));
		$board->show();
		self::assertCount(1, $this->packets);
	}

	public function testMaximumRowsHaveDistinctNamesAndTopToBottomScores() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->setLines(array_fill(0, Scoreboard::MAX_LINES, "Same"));
		self::assertCount(15, $board->getLines());
		self::assertInstanceOf(SetScorePacket::class, $this->packets[1]);
		$entries = $this->packets[1]->getEntries();
		self::assertSame(-15, $entries[14]->scoreboardId);
		self::assertSame(1, $entries[14]->score);
		self::assertCount(15, array_unique(array_column($entries, "customName")));
		$this->packets = [];
		$board->setLine(14, "Last");
		self::assertSame("Last", $board->getLine(14));
		self::assertCount(1, $this->packets);
	}

	public function testReentryAndDisconnectDuringTransmission() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$reentered = false;
		$this->sendHook = static function(ClientboundPacket $packet) use ($board, &$reentered) : bool{
			try{
				$board->setTitle("Reentered");
				self::fail("Allowed reentry");
			}catch(\LogicException){
				$reentered = true;
			}
			return false;
		};
		$this->rejected(fn() => $board->update("Title", ["A"]));
		self::assertTrue($reentered);
		self::assertSame("Title", $board->getTitle());
		$this->sendHook = null;
		$board->update("Title", ["A"]);
		$this->packets = [];
		$this->sendHook = static function(ClientboundPacket $packet) use ($player) : bool{
			$player->connected = false;
			$player->destroyCyclesForTest();
			return true;
		};
		$this->rejected(fn() => $board->setLine(0, "B"));
		self::assertSame([], $this->packets);
		self::assertFalse($board->isVisible());
		self::assertSame([], $board->getLines());
		$this->expectException(\LogicException::class);
		$board->show();
	}

	public function testControllerDoesNotRetainPlayerAndRejectsUnspawnedPlayer() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$player->spawned = false;
		try{
			$board->show();
			self::fail("Accepted unspawned player");
		}catch(\LogicException){
		}
		$reference = \WeakReference::create($player);
		unset($player);
		self::assertNull($reference->get());
		self::assertFalse($board->isVisible());
		$this->expectException(\LogicException::class);
		$board->show();
	}

	public function testInputReferencesAndReturnedArraysCannotMutateState() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$text = "Original";
		$board->update("Title", [&$text]);
		$text = "invalid\nexternal mutation";
		self::assertSame(["Original"], $board->getLines());
		$copy = $board->getLines();
		$copy[0] = "Modified copy";
		self::assertSame("Original", $board->getLine(0));
		$same = "Original";
		$board->update("Title", [&$same]);
		$board->setLines([&$same]);
		$same = "Changed after a no-op";
		self::assertSame("Original", $board->getLine(0));
		$this->packets = [];
		$board->setLine(0, "Original");
		self::assertSame([], $this->packets);
		$replacement = "Replacement";
		$board->setLines([&$replacement]);
		$replacement = "Other mutation";
		self::assertSame(["Replacement"], $board->getLines());
		$board->setLines(["Other mutation"]);
		$changes = $this->getPackets();
		self::assertCount(2, $changes);
		foreach($changes as $packet){
			self::assertInstanceOf(SetScorePacket::class, $packet);
			self::assertSame([ScorePacketEntryAction::REMOVE, ScorePacketEntryAction::CHANGE_FAKE_PLAYER], array_column($packet->getEntries(), "action"));
		}
	}

	public function testEqualSparseInputStillRequiresAList() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->setLine(2, "Sparse");
		$this->packets = [];
		$sparse = $board->getLines();
		$setLines = fn() => $board->setLines($sparse); // @phpstan-ignore argument.type
		$update = fn() => $board->update("Uncommitted", $sparse); // @phpstan-ignore argument.type
		foreach([$setLines, $update] as $operation){
			try{
				$operation();
				self::fail("Accepted sparse list");
			}catch(\InvalidArgumentException){
			}
		}
		self::assertSame("Scoreboard", $board->getTitle());
		self::assertSame([2 => "Sparse"], $board->getLines());
		self::assertSame([], $this->packets);
	}
}

final class ScoreboardTestPlayer extends Player{
	public NetworkSession $testSession;
	public bool $connected = true;

	public function __destruct(){
	}

	public function isConnected() : bool{
		return $this->connected;
	}

	public function getNetworkSession() : NetworkSession{
		return $this->testSession;
	}

	public function destroyCyclesForTest() : void{
		parent::destroyCycles();
	}
}
