<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\console;

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use quark\utils\Process;
use function cli_set_process_title;
use function count;
use function dirname;
use function fwrite;
use function is_numeric;
use const PHP_EOL;
use const STDOUT;

if(!isset($argv) || count($argv) !== 2 || !is_numeric($argv[1])){
	echo "Usage: " . __FILE__ . " <command token seed>" . PHP_EOL;
	exit(1);
}

$commandTokenSeed = (int) $argv[1];

require dirname(__DIR__, 2) . '/vendor/autoload.php';

@cli_set_process_title('Quark Console Reader');

/** @phpstan-var ThreadSafeArray<int, string> $channel */
$channel = new ThreadSafeArray();
$thread = new class($channel) extends NativeThread{
	/**
	 * @phpstan-param ThreadSafeArray<int, string> $channel
	 */
	public function __construct(
		private ThreadSafeArray $channel,
	){}

	public function run() : void{
		require dirname(__DIR__, 2) . '/vendor/autoload.php';

		$channel = $this->channel;
		$reader = new ConsoleReader();
		while(true){ // @phpstan-ignore-line
			$line = $reader->readLine();
			if($line !== null){
				$channel->synchronized(function() use ($channel, $line) : void{
					$channel[] = $line;
					$channel->notify();
				});
			}
		}
	}
};

$thread->start(NativeThread::INHERIT_NONE);
while(true){
	$line = $channel->synchronized(function() use ($channel) : ?string{
		if(count($channel) === 0){
			$channel->wait(1_000_000);
		}
		return $channel->shift();
	});
	$message = $line !== null ? ConsoleReaderChildProcessUtils::createMessage($line, $commandTokenSeed) : "";
	if(@fwrite(STDOUT, $message . "\n") === false){
		//Always send even if there's no line, to check if the parent is alive
		//If the parent process was terminated forcibly, it won't close the connection properly, so feof() will return
		//false even though the connection is actually broken. However, fwrite() will fail.
		break;
	}
}

//For simplicity's sake, we don't bother with a graceful shutdown here.
//The parent process would normally forcibly terminate the child process anyway, so we only reach this point if the
//parent process was terminated forcibly and didn't clean up after itself.
Process::kill(Process::pid());
