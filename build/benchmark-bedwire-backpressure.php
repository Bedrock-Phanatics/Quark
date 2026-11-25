<?php

declare(strict_types=1);

use quark\network\mcpe\bedwire\BedwireInterface;
use quark\network\mcpe\NetworkSession;
use quark\Server;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Run with the packaged PHP runtime. Simulates an ext-bedwire command ring that stays full for 100 flushes.
const COMMANDS = 4096;
const FLUSHES = 100;
const SAMPLES = 9;

$interfaceClass = new ReflectionClass(BedwireInterface::class);
$session = new ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
$server = new ReflectionClass(Server::class)->newInstanceWithoutConstructor();
$defer = $interfaceClass->getMethod('defer');
$flush = $interfaceClass->getMethod('flushDeferred');
$samples = [];
$attempts = 0;

for($sample = -1; $sample < SAMPLES; ++$sample){
	$interface = $interfaceClass->newInstanceWithoutConstructor();
	$interfaceClass->getProperty('server')->setValue($interface, $server);
	$interfaceClass->getProperty('sessions')->setValue($interface, [1 => $session]);
	$attempts = 0;
	$enqueue = function(string $batch) use ($interface, $defer, &$attempts) : void{
		$retry = function() use ($interface, $defer, $batch, &$attempts, &$retry) : void{
			++$attempts;
			$defer->invoke($interface, 1, strlen($batch), $retry);
		};
		$defer->invoke($interface, 1, strlen($batch), $retry);
	};
	for($command = 0; $command < COMMANDS; ++$command){
		$enqueue(str_repeat('a', 256) . pack('V', $command));
	}
	$batchStart = hrtime(true);
	for($tick = 0; $tick < FLUSHES; ++$tick){
		$flush->invoke($interface);
	}
	$elapsed = (hrtime(true) - $batchStart) / FLUSHES / 1e3;
	if($sample >= 0){
		$samples[] = $elapsed;
	}
	/** @var array<int, list<mixed>> $queue */
	$queue = $interfaceClass->getProperty('deferred')->getValue($interface);
	if(count($queue[1]) !== COMMANDS){
		throw new RuntimeException('Deferred commands were lost');
	}
	$interfaceClass->getProperty('deferred')->setValue($interface, []);
	unset($interface, $enqueue, $queue);
	gc_collect_cycles();
}

sort($samples);
printf("%d deferred sends, %d flushes, median %.1f µs/flush, %.0f callbacks/flush\n", COMMANDS, FLUSHES, $samples[intdiv(SAMPLES, 2)], $attempts / FLUSHES);
