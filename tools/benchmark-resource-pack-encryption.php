<?php

declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use quark\CoreConfig;
use quark\resourcepacks\ResourcePack;
use quark\resourcepacks\ResourcePackManager;
use quark\utils\Filesystem;

$sizeMiB = filter_var($argv[1] ?? "16", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 512]]);
if($sizeMiB === false){
	throw new InvalidArgumentException("Usage: php tools/benchmark-resource-pack-encryption.php [payload MiB: 1..512]");
}
$root = sys_get_temp_dir() . "/quark-pack-benchmark-" . bin2hex(random_bytes(16));
mkdir($root, 0700);
$logger = new class extends SimpleLogger{
	public function log($level, $message) : void{}
};
$measurements = [];
$downloads = static function(ResourcePack $pack) : float{
	$start = hrtime(true);
	$bytes = 0;
	for($offset = 0; $offset < $pack->getPackSize(); $offset += 262144){
		for($reader = 0; $reader < 32; ++$reader){
			$bytes += strlen($pack->getPackChunk(($offset + $reader * 262144) % $pack->getPackSize(), 262144));
		}
	}
	return $bytes / 1048576 / ((hrtime(true) - $start) / 1e9);
};

try{
	$payload = fopen($root . "/payload", "xb");
	if($payload === false){
		throw new RuntimeException("Cannot create benchmark payload");
	}
	try{
		for($i = 0; $i < $sizeMiB * 16; ++$i){
			if(fwrite($payload, random_bytes(65536)) !== 65536){
				throw new RuntimeException("Cannot write benchmark payload");
			}
		}
	}finally{
		fclose($payload);
	}
	$zip = new ZipArchive();
	if($zip->open($root . "/sample.zip", ZipArchive::CREATE) !== true){
		throw new RuntimeException("Cannot create benchmark pack");
	}
	$zip->addFromString("manifest.json", json_encode([
		"format_version" => 2,
		"header" => ["name" => "Benchmark", "uuid" => "481c7dcf-f147-4373-9cc5-afc6a651dc40", "version" => [1, 0, 0]],
		"modules" => [["type" => "resources", "uuid" => "799255a2-4ff9-4a37-ad9c-8ed09cfa5fff", "version" => [1, 0, 0]]]
	], JSON_THROW_ON_ERROR));
	$zip->addFile($root . "/payload", "payload.bin");
	$zip->setCompressionName("payload.bin", ZipArchive::CM_STORE);
	if(!$zip->close()){
		throw new RuntimeException("Cannot finish benchmark pack");
	}
	for($pass = 0; $pass < 3; ++$pass){
		foreach(["disabled", "cold", "warm"] as $mode){
			if($mode === "cold" && is_dir($root . "/.quark-encryption")){
				Filesystem::recursiveUnlink($root . "/.quark-encryption");
			}
			file_put_contents($root . "/resource_packs.toml", 'resource_stack = ["sample.zip"]' . "\n[encryption]\nenabled = " . ($mode === "disabled" ? "false" : "true") . "\n");
			CoreConfig::forget($root . "/resource_packs.toml");
			$before = memory_get_usage(true);
			memory_reset_peak_usage();
			$start = hrtime(true);
			$manager = new ResourcePackManager($root, $logger);
			$measurements[$mode]["startup_ms"][] = (hrtime(true) - $start) / 1e6;
			$measurements[$mode]["startup_peak_delta_mib"][] = (memory_get_peak_usage(true) - $before) / 1048576;
			$measurements[$mode]["startup_peak_mib"][] = memory_get_peak_usage(true) / 1048576;
			$measurements[$mode]["interleaved_32_reader_mib_s"][] = $downloads($manager->getResourceStack()[0]);
			unset($manager);
		}
	}
	foreach($measurements as $mode => $metrics){
		foreach($metrics as $metric => $samples){
			sort($samples);
			$measurements[$mode][$metric] = round($samples[1], 3);
		}
	}
	echo json_encode(["php" => PHP_VERSION, "payload_mib" => $sizeMiB, "samples" => 3, "measurements" => $measurements], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
}finally{
	Filesystem::recursiveUnlink($root);
}
