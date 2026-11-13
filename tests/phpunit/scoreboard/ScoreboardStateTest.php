<?php

declare(strict_types=1);

namespace quark\scoreboard;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\player\Player;
use quark\utils\TextFormat;
use Random\Engine\Mt19937;
use Random\Randomizer;
use function array_column;
use function array_unique;
use function array_values;
use function count;
use function dechex;
use function ksort;
use function strlen;
use function substr;

final class ScoreboardStateTest extends TestCase{
	public function testSeededUpdatesConvergeAcrossRejectedPacketsAndCleanup() : void{
		$random = new Randomizer(new Mt19937(0x51deba));
		$cancelled = $exceptions = 0;
		$texts = ["", "Same", "Same", TextFormat::GREEN . "Green", "Unicode \u{2603}"];
		for($cycle = 0; $cycle < 3; ++$cycle){
			$clientTitle = null;
			$clientRows = [];
			$session = new \ReflectionClass(ScoreboardStateTestSession::class)->newInstanceWithoutConstructor();
			$session->send = static function(ClientboundPacket $packet, \Closure $condition) use ($random, &$clientTitle, &$clientRows, &$cancelled, &$exceptions) : bool{
				if(!$condition()){
					return false;
				}
				$failure = $random->getInt(0, 19);
				if($failure === 0){
					++$cancelled;
					return false;
				}
				if($failure === 1){
					++$exceptions;
					throw new \RuntimeException("Injected send failure");
				}
				$bytes = EncodedPacket::encode($packet);
				$decoded = match(true){
					$packet instanceof SetDisplayObjectivePacket => new SetDisplayObjectivePacket(),
					$packet instanceof RemoveObjectivePacket => new RemoveObjectivePacket(),
					$packet instanceof SetScorePacket => new SetScorePacket(),
					default => throw new \LogicException("Unexpected scoreboard packet")
				};
				$decoded->decode(new ByteBufferReader($bytes));
				if($decoded instanceof RemoveObjectivePacket){
					$clientTitle = null;
					$clientRows = [];
				}elseif($decoded instanceof SetDisplayObjectivePacket){
					self::assertNull($clientTitle, "Title replacement must remove the old objective");
					$clientTitle = $decoded->displayName;
				}else{
					self::assertNotNull($clientTitle, "Scores require an objective");
					$adding = false;
					foreach($decoded->getEntries() as $entry){
						self::assertNotSame(-1, $entry->scoreboardId);
						if($entry->action === ScorePacketEntryAction::REMOVE){
							self::assertFalse($adding, "Remove rows before adding replacements");
							unset($clientRows[$entry->scoreboardId]);
						}else{
							$adding = true;
							self::assertSame(ScorePacketEntryAction::CHANGE_FAKE_PLAYER, $entry->action);
							self::assertArrayNotHasKey($entry->scoreboardId, $clientRows, "Renamed rows must be removed first");
							self::assertNotNull($entry->customName);
							$clientRows[$entry->scoreboardId] = [$entry->score, $entry->customName];
						}
					}
					self::assertLessThanOrEqual(15, count($clientRows));
					self::assertCount(count($clientRows), array_unique(array_column($clientRows, 1)), "Blank and duplicate rows need distinct wire names");
				}
				return true;
			};
			$player = new \ReflectionClass(ScoreboardStateTestPlayer::class)->newInstanceWithoutConstructor();
			$player->spawned = true;
			$player->session = $session;
			$board = $player->getScoreboard();
			$title = "Scoreboard";
			$rows = [];
			$visible = true;
			for($step = 0; $step < 256; ++$step){
				/** @var int<0, 7> $operation */
				$operation = $step % 31 === 30 || $step === 255 ? 7 : $random->getInt(0, 7);
				$line = $random->getInt(0, 14);
				$text = $texts[$random->getInt(0, count($texts) - 1)];
				if($operation === 0 || $operation === 1){
					$title = "Title " . $random->getInt(0, 5);
				}
				if($operation === 1 || $operation === 2){
					$rows = [];
					for($count = $random->getInt(0, 15); $count > 0; --$count){
						$rows[] = $texts[$random->getInt(0, count($texts) - 1)];
					}
				}elseif($operation === 3){
					$rows[$line] = $text;
					ksort($rows);
				}elseif($operation === 4){
					unset($rows[$line]);
				}elseif($operation === 5){
					$rows = [];
				}elseif($operation === 6 || $operation === 7){
					$visible = $operation === 7;
				}
				$accepted = false;
				try{
					match($operation){
						0 => $board->setTitle($title),
						1 => $board->update($title, array_values($rows)),
						2 => $board->setLines(array_values($rows)),
						3 => $board->setLine($line, $text),
						4 => $board->removeLine($line),
						5 => $board->clear(),
						6 => $board->hide(),
						7 => $board->show()
					};
					$accepted = true;
				}catch(\RuntimeException){
				}
				self::assertSame($title, $board->getTitle());
				self::assertSame($rows, $board->getLines());
				if($accepted){
					self::assertSame($visible ? $title : null, $clientTitle);
					$actual = [];
					foreach($clientRows as [$score, $wireName]){
						$row = 15 - $score;
						$prefix = TextFormat::ESCAPE . dechex($row) . TextFormat::RESET;
						self::assertStringStartsWith($prefix, $wireName);
						$actual[$row] = substr($wireName, strlen($prefix));
					}
					ksort($actual);
					self::assertSame($visible ? $rows : [], $actual);
				}
			}
			$accepted = false;
			for($attempt = 0; $attempt < 20; ++$attempt){
				try{
					$board->show();
					$accepted = true;
					break;
				}catch(\RuntimeException){}
			}
			self::assertTrue($accepted, "Retries must converge after injected send failures");
			self::assertSame($title, $clientTitle);
			self::assertCount(count($rows), $clientRows);
			$reference = \WeakReference::create($player);
			$player->destroyCyclesForTest();
			self::assertFalse($board->isVisible());
			self::assertSame([], $board->getLines());
			unset($player);
			self::assertNull($reference->get());
		}
		self::assertGreaterThan(0, $cancelled);
		self::assertGreaterThan(0, $exceptions);
	}
}

final class ScoreboardStateTestSession extends NetworkSession{
	/** @var \Closure(ClientboundPacket, \Closure(): bool): bool */
	public \Closure $send;

	public function sendDataPacketIf(ClientboundPacket $packet, \Closure $condition) : bool{
		return ($this->send)($packet, $condition);
	}
}

final class ScoreboardStateTestPlayer extends Player{
	public NetworkSession $session;

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
