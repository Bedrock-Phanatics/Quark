<?php

declare(strict_types=1);

namespace quark\tools\benchmark_scoreboard;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\player\Player;
use quark\utils\TextFormat;
use function array_map;
use function dechex;
use function dirname;
use function hrtime;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function range;
use function round;
use function sort;
use function strlen;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

require dirname(__DIR__) . '/vendor/autoload.php';

final class BenchmarkSession extends NetworkSession{
	public int $packets = 0;
	public int $bytes = 0;

	public function isConnected() : bool{
		return true;
	}

	public function sendDataPacketIf(ClientboundPacket $packet, \Closure $condition) : bool{
		if(!$condition()){
			return false;
		}
		++$this->packets;
		$this->bytes += strlen(EncodedPacket::encode($packet));
		return true;
	}
}

final class BenchmarkPlayer extends Player{
	public BenchmarkSession $session;

	public function __destruct(){}

	public function isConnected() : bool{
		return true;
	}

	public function getNetworkSession() : NetworkSession{
		return $this->session;
	}
}

/**
 * @param list<string> $lines
 */
function fullResend(BenchmarkSession $session, array $lines) : void{
	$removed = [];
	$changed = [];
	foreach($lines as $line => $text){
		$entry = new ScorePacketEntry();
		$entry->action = ScorePacketEntryAction::REMOVE;
		$entry->scoreboardId = -($line + 2);
		$entry->objectiveName = "quark:sidebar";
		$removed[] = $entry;
		$entry = new ScorePacketEntry();
		$entry->action = ScorePacketEntryAction::CHANGE_FAKE_PLAYER;
		$entry->scoreboardId = -($line + 2);
		$entry->objectiveName = "quark:sidebar";
		$entry->score = 15 - $line;
		$entry->customName = TextFormat::ESCAPE . dechex($line) . TextFormat::RESET . $text;
		$changed[] = $entry;
	}
	$session->sendDataPacketIf(SetScorePacket::create($removed), static fn() : bool => true);
	$session->sendDataPacketIf(SetScorePacket::create($changed), static fn() : bool => true);
}

/**
 * @param \Closure() : void $operation
 * @return array{median_us: float, packets: int, bytes: int, transient_bytes: int}
 */
function measure(BenchmarkSession $session, \Closure $operation) : array{
	for($i = 0; $i < 1000; ++$i){
		$operation();
	}
	$samples = [];
	for($run = 0; $run < 7; ++$run){
		$start = hrtime(true);
		for($i = 0; $i < 10000; ++$i){
			$operation();
		}
		$samples[] = (hrtime(true) - $start) / 10000 / 1000;
	}
	sort($samples);
	$session->packets = $session->bytes = 0;
	$base = memory_get_usage();
	memory_reset_peak_usage();
	$operation();
	return [
		"median_us" => round($samples[3], 3),
		"packets" => $session->packets,
		"bytes" => $session->bytes,
		"transient_bytes" => memory_get_peak_usage() - $base
	];
}

$session = new \ReflectionClass(BenchmarkSession::class)->newInstanceWithoutConstructor();
$player = new \ReflectionClass(BenchmarkPlayer::class)->newInstanceWithoutConstructor();
$player->session = $session;
$player->spawned = true;
$board = $player->getScoreboard();
$original = array_map(static fn(int $line) : string => "Row $line: 100", range(0, 14));
$results = [];
foreach(["unchanged", "one_row", "all_rows"] as $workload){
	foreach(["full_resend", "diff"] as $strategy){
		$board->update("Benchmark", $original);
		$counter = 0;
		$results[$workload][$strategy] = measure($session, static function() use ($board, $session, $original, $workload, $strategy, &$counter) : void{
			$lines = $original;
			++$counter;
			if($workload === "one_row"){
				$lines[7] = "Row 7: " . $counter;
			}elseif($workload === "all_rows"){
				foreach($lines as $line => $text){
					$lines[$line] = "Row $line: " . $counter;
				}
			}
			if($strategy === "full_resend"){
				fullResend($session, $lines);
			}else{
				$board->update("Benchmark", $lines);
			}
		});
	}
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
