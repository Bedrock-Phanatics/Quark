<?php

declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use quark\permission\AccessStorage;
use quark\permission\BanList;
use quark\utils\Config;

$root = sys_get_temp_dir() . "/quark-access-bench-" . bin2hex(random_bytes(8));
mkdir($root);
$storage = new AccessStorage($root . "/access.sqlite", true);
$ops = new Config($storage->path(), Config::ENUM, [], $storage, "op");
$bans = new BanList($storage, "player");
$bans->load();
$ops->set("Alice");
$bans->addBan("Griefer");

$run = static function(int $count, callable $work) : float{
	$start = hrtime(true);
	for($i = 0; $i < $count; ++$i){
		$work($i);
	}
	return (hrtime(true) - $start) / $count;
};

try{
	printf("operator lookup: %.1f ns/op\n", $run(1_000_000, static fn() => $ops->exists("ALICE", true)));
	printf("ban lookup: %.1f ns/op\n", $run(100_000, static fn() => $bans->isBanned("GRIEFER")));
	printf("operator write: %.1f us/op\n", $run(1000, static fn(int $i) => $ops->set("Operator$i")) / 1000);
	printf("ban write: %.1f us/op\n", $run(1000, static fn(int $i) => $bans->addBan("Player$i")) / 1000);
}finally{
	$storage->close();
	foreach(new FilesystemIterator($root) as $file){
		unlink($file->getPathname());
	}
	rmdir($root);
}
