<?php

declare(strict_types=1);

namespace quark\scoreboard;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use function array_column;
use function array_fill;
use function str_repeat;

final class ScoreboardNamedRowsTest extends TestCase{
	private function player() : ScoreboardNamedRowsTestPlayer{
		$player = new \ReflectionClass(ScoreboardNamedRowsTestPlayer::class)->newInstanceWithoutConstructor();
		$player->spawned = true;
		$player->session = new \ReflectionClass(ScoreboardNamedRowsTestSession::class)->newInstanceWithoutConstructor();
		return $player;
	}

	/** @param \Closure(): mixed $operation */
	private function invalid(\Closure $operation) : void{
		try{
			$operation();
			self::fail("Accepted invalid named-row operation");
		}catch(\InvalidArgumentException){
		}
	}

	public function testMixedLayoutPatchesStableAliasesAndPartialUpdates() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update(title: "Arena", lines: ["kills" => "Kills: 0", "", "coins" => "Coins: 100"]);
		self::assertSame(["Kills: 0", "", "Coins: 100"], $board->getLines());
		self::assertSame("Kills: 0", $board->getLine("kills"));
		self::assertSame("Coins: 100", $board->getLine(2));
		$player->session->packets = [];
		$board->update(["kills" => "Kills: 1"]);
		self::assertSame(["Kills: 1", "", "Coins: 100"], $board->getLines());
		self::assertCount(1, $player->session->packets);
		$packet = $player->session->packets[0];
		self::assertInstanceOf(SetScorePacket::class, $packet);
		self::assertSame([ScorePacketEntryAction::REMOVE, ScorePacketEntryAction::CHANGE_FAKE_PLAYER], array_column($packet->getEntries(), "action"));
		$board->update(["kills" => "Kills: 1"]);
		$board->update([]);
		self::assertCount(1, $player->session->packets);
		$board->removeLine("kills");
		self::assertNull($board->getLine("kills"));
		self::assertSame([1 => "", 2 => "Coins: 100"], $board->getLines());
		$board->update(["kills" => "Kills: 2"]);
		self::assertSame("Kills: 2", $board->getLine(0));
		$board->setLine("coins", "Coins: 150");
		$board->update(title: "Final");
		self::assertSame("Final", $board->getTitle());
		self::assertSame(["Kills: 2", "", "Coins: 150"], $board->getLines());
		$board->update(lines: ["New list"]);
		self::assertSame("Final", $board->getTitle());
		$this->invalid(fn() => $board->setLine("kills", "Unknown now"));
	}

	public function testLayoutRebindsWithoutPacketsAndClearRemovesAliases() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->setLines(["first" => "A", 90 => "B", "last" => "C", 8 => ""]);
		self::assertSame(["A", "B", "C", ""], $board->getLines());
		$player->session->packets = [];
		$board->setLines(["renamed" => "A", "B", "last" => "C", ""]);
		self::assertSame([], $player->session->packets);
		self::assertSame("A", $board->getLine("renamed"));
		$this->invalid(fn() => $board->getLine("first"));
		$board->removeLine(0);
		$board->setLine("renamed", "Restored");
		self::assertSame("Restored", $board->getLine(0));
		$board->clear();
		$this->invalid(fn() => $board->update(["renamed" => "Gone"]));
		$board->setLines(["temporary" => "A"]);
		$board->setLines(["A"]);
		$this->invalid(fn() => $board->update(["temporary" => "Gone"]));
	}

	public function testInvalidLayoutsAndPatchesLeaveAllContentAndAliasesUntouched() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->update("Arena", ["kills" => "Kills: 0", "coins" => "Coins: 100"]);
		$player->session->packets = [];
		foreach(["", "bad\nname", "bad\rname", "bad\0name", "\xff", str_repeat("x", 65)] as $name){
			$this->invalid(fn() => $board->update("Uncommitted", [$name => "Invalid"]));
		}
		$this->invalid(fn() => $board->setLines([2 => "Sparse"]));
		$this->invalid(fn() => $board->setLines(array_fill(0, 16, "Too many")));
		$this->invalid(fn() => $board->update(["kills" => "Changed", "unknown" => "Invalid"]));
		$this->invalid(fn() => $board->update(["kills" => "Changed", "coins" => "Bad\ntext"]));
		$this->invalid(fn() => $board->update(["kills" => "Changed", 0 => "Invalid numeric patch"]));
		$this->invalid(fn() => $board->update(["kills" => 123])); // @phpstan-ignore argument.type
		$this->invalid(fn() => $board->update(["kills" => "Changed"], lines: ["Ambiguous"]));
		self::assertSame("Arena", $board->getTitle());
		self::assertSame(["Kills: 0", "Coins: 100"], $board->getLines());
		self::assertSame("Kills: 0", $board->getLine("kills"));
		self::assertSame([], $player->session->packets);
		$longName = str_repeat("x", 64);
		$board->setLines([$longName => "Boundary"]);
		self::assertSame("Boundary", $board->getLine($longName));
	}

	public function testAllFifteenNamedRowsCanBePatchedTogether() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$layout = $changes = [];
		for($line = 0; $line < 15; ++$line){
			$layout["row$line"] = "Before";
			$changes["row$line"] = "After $line";
		}
		$board->setLines($layout);
		$player->session->packets = [];
		$board->update($changes);
		self::assertSame("After 14", $board->getLine("row14"));
		self::assertCount(1, $player->session->packets);
		$changes["sixteenth"] = "Too many";
		$this->invalid(fn() => $board->update($changes));
		self::assertSame("After 0", $board->getLine("row0"));
		self::assertCount(1, $player->session->packets);
	}

	public function testNamedInputsAreDetachedAndHiddenPatchesRetryAfterCancellation() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$text = "Original";
		$board->setLines(["key" => &$text]);
		$text = "External mutation";
		self::assertSame("Original", $board->getLine("key"));
		$board->hide();
		$player->session->packets = [];
		$patch = "Hidden";
		$board->update(["key" => &$patch]);
		$patch = "Other external mutation";
		self::assertSame("Hidden", $board->getLine("key"));
		self::assertSame([], $player->session->packets);
		$board->show();
		$player->session->packets = [];
		$player->session->reject = true;
		try{
			$board->update(["key" => "Rejected"]);
			self::fail("Accepted cancelled packet");
		}catch(\RuntimeException){
		}
		self::assertSame("Rejected", $board->getLine("key"));
		self::assertSame([], $player->session->packets);
		$player->session->reject = false;
		$board->update(["key" => "Rejected"]);
		self::assertCount(1, $player->session->packets);
	}

	public function testPatchReentryIsRejectedAndCleanupReleasesNamesAndPlayer() : void{
		$player = $this->player();
		$board = $player->getScoreboard();
		$board->setLines(["key" => "Original"]);
		$called = false;
		$player->session->hook = static function() use ($board, &$called) : void{
			try{
				$board->update(["key" => "Reentered"]);
				self::fail("Allowed patch reentry");
			}catch(\LogicException){
				$called = true;
			}
		};
		$board->update(["key" => "Outer"]);
		self::assertTrue($called);
		self::assertSame("Outer", $board->getLine("key"));
		$reference = \WeakReference::create($player);
		$player->destroyCyclesForTest();
		self::assertSame([], $board->getLines());
		$this->invalid(fn() => $board->getLine("key"));
		unset($player);
		self::assertNull($reference->get());
	}
}

final class ScoreboardNamedRowsTestSession extends NetworkSession{
	/** @var list<ClientboundPacket> */
	public array $packets = [];
	public bool $reject = false;
	/** @var (\Closure(): void)|null */
	public ?\Closure $hook = null;

	public function sendDataPacketIf(ClientboundPacket $packet, \Closure $condition) : bool{
		($this->hook)?->__invoke();
		if($this->reject || !$condition()){
			return false;
		}
		$this->packets[] = $packet;
		return true;
	}
}

final class ScoreboardNamedRowsTestPlayer extends Player{
	public ScoreboardNamedRowsTestSession $session;

	public function __destruct(){
	}

	public function isConnected() : bool{
		return true;
	}

	public function getNetworkSession() : NetworkSession{
		return $this->session;
	}

	public function destroyCyclesForTest() : void{
		parent::destroyCycles();
	}
}
