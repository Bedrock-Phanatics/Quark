<?php

declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

if(isset($argv[1])){
	require $argv[1];
}

$root = sys_get_temp_dir() . "/quark-filesystem-benchmark-" . bin2hex(random_bytes(8));
mkdir($root);
$paths = new quark\FilesystemPaths($root, $root . "/plugins");
$migration = new quark\FilesystemMigration($paths);

try{
	$start = hrtime(true);
	$migration->prepare();
	$firstStartupMs = (hrtime(true) - $start) / 1e6;
	copy(dirname(__DIR__) . "/resources/server.toml", $paths->serverToml());
	for($i = 0; $i < 10; ++$i){
		$migration->prepare();
	}
	$startup = [];
	$config = [];
	for($i = 0; $i < 200; ++$i){
		$start = hrtime(true);
		$migration->prepare();
		$startup[] = (hrtime(true) - $start) / 1e6;
		quark\CoreConfig::forget($paths->serverToml());
		$start = hrtime(true);
		quark\CoreConfig::load($paths->serverToml());
		$config[] = (hrtime(true) - $start) / 1e6;
	}
	sort($startup);
	sort($config);
	echo json_encode([
		"layout_version" => quark\FilesystemMigration::VERSION,
		"first_startup_ms" => round($firstStartupMs, 3),
		"modern_startup_median_ms" => round($startup[100], 3),
		"modern_startup_p95_ms" => round($startup[190], 3),
		"config_cold_load_median_ms" => round($config[100], 3),
		"peak_memory_mib" => round(memory_get_peak_usage(true) / 1048576, 2)
	], JSON_THROW_ON_ERROR), PHP_EOL;
}finally{
	quark\utils\Filesystem::recursiveUnlink($root);
}
