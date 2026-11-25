<?php

declare(strict_types=1);

namespace quark\tools\benchmark_npc_dialogue;

use pocketmine\network\mcpe\protocol\NpcRequestPacket;
use quark\network\mcpe\NetworkSession;
use quark\npcdialogue\NpcDialogue;
use quark\player\Player;
use quark\plugin\PluginBase;
use quark\pulse\internal\PulseZones;
use quark\world\Position;
use function count;
use function dirname;
use function hrtime;
use function printf;
use function sort;
use function strlen;

require dirname(__DIR__) . '/vendor/autoload.php';

PulseZones::init();

final class BenchmarkPlugin extends PluginBase{}

final class BenchmarkPlayer extends Player{
	public function __destruct(){}

	public function getPosition() : Position{
		return new Position(0, 80, 0, null);
	}

	public function isAlive() : bool{
		return true;
	}
}

/**
 * @param \Closure(): void $operation
 */
function measure(string $name, NetworkSession $network, \Closure $operation) : void{
	$buffer = new \ReflectionProperty(NetworkSession::class, "sendBuffer");
	$buffer->setValue($network, []);
	for($i = 0; $i < 1000; ++$i){
		$operation();
		$buffer->setValue($network, []);
	}
	$samples = [];
	for($run = 0; $run < 7; ++$run){
		$start = hrtime(true);
		for($i = 0; $i < 10000; ++$i){
			$operation();
			$buffer->setValue($network, []);
		}
		$samples[] = (hrtime(true) - $start) / 10000 / 1000;
	}
	sort($samples);
	$operation();
	/** @var list<string> $packets */
	$packets = $buffer->getValue($network);
	$bytes = 0;
	foreach($packets as $packet){
		$bytes += strlen($packet);
	}
	printf("%-30s %7.3f us/op, %d packets, %d bytes\n", $name, $samples[3], count($packets), $bytes);
}

$plugin = (new \ReflectionClass(BenchmarkPlugin::class))->newInstanceWithoutConstructor();
$plugin->onEnableStateChange(true);
$network = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
$player = (new \ReflectionClass(BenchmarkPlayer::class))->newInstanceWithoutConstructor();
$player->spawned = true;
(new \ReflectionProperty(NetworkSession::class, "player"))->setValue($network, $player);
(new \ReflectionProperty(NetworkSession::class, "loggedIn"))->setValue($network, true);

$request = NpcRequestPacket::create(1, NpcRequestPacket::REQUEST_EXECUTE_ACTION, "", 0, "expired");
measure("Idle request", $network, static function() use ($network, $request) : void{
	$network->getNpcDialogueControllerIfCreated()?->handleResponse($request);
});

$create = static fn() => NpcDialogue::create($plugin)
	->setName("Merchant")
	->setText("Take a look at the catalog.")
	->addButton("Shop")
	->addButton("Goodbye");
$controller = $network->getNpcDialogueController();
$page = $create();
$controller->open($page) ?? throw new \RuntimeException("Could not open benchmark dialogue");

measure("Stale request, active page", $network, static function() use ($controller, $request) : void{
	$controller->handleResponse($request);
});
measure("Replace, new definition", $network, static function() use ($controller, $create) : void{
	$controller->open($create()) ?? throw new \RuntimeException("Could not replace benchmark dialogue");
});
measure("Replace, reused definition", $network, static function() use ($controller, $page) : void{
	$controller->open($page) ?? throw new \RuntimeException("Could not replace benchmark dialogue");
});
measure("Update, same portrait actor", $network, static function() use ($controller, $page) : void{
	$controller->getActive()?->update($page) ?? throw new \RuntimeException("Could not update benchmark dialogue");
});
