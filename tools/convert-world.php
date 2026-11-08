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

namespace quark\tools\convert_world;

use quark\world\format\io\FormatConverter;
use quark\world\format\io\WorldMigration;
use quark\world\format\io\WorldProviderManager;
use quark\world\format\io\WorldProviderManagerEntry;
use quark\world\format\io\WritableWorldProviderManagerEntry;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function count;
use function dirname;
use function fwrite;
use function getopt;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function is_writable;
use function mkdir;
use function realpath;
use const PHP_EOL;
use const STDERR;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$providerManager = new WorldProviderManager();
$writableFormats = array_filter($providerManager->getAvailableProviders(), fn(WorldProviderManagerEntry $class) => $class instanceof WritableWorldProviderManagerEntry);
$requiredOpts = [
	"world" => "path to the input world for conversion",
	"backup" => "path to back up the original files",
	"format" => "desired output format (can be one of: " . implode(", ", array_keys($writableFormats)) . ")"
];
$usageMessage = "Options:\n";
foreach($requiredOpts as $_opt => $_desc){
	$usageMessage .= "\t--$_opt : $_desc\n";
}
$plainArgs = getopt("", array_map(function(string $str){ return "$str:"; }, array_keys($requiredOpts)));
$args = [];
foreach($requiredOpts as $opt => $desc){
	if(!isset($plainArgs[$opt]) || !is_string($plainArgs[$opt])){
		fwrite(STDERR, $usageMessage);
		exit(1);
	}
	$args[$opt] = $plainArgs[$opt];
}
if(!array_key_exists($args["format"], $writableFormats)){
	fwrite(STDERR, $usageMessage);
	exit(1);
}

$inputPath = realpath($args["world"]);
if($inputPath === false){
	fwrite(STDERR, "Cannot find input world at location: " . $args["world"] . PHP_EOL);
	exit(1);
}
$backupPath = realpath($args["backup"]);
if($backupPath === false || (!@mkdir($backupPath, 0777, true) && !is_dir($backupPath)) || !is_writable($backupPath)){
	fwrite(STDERR, "Backup file path " . $args["backup"] . " is not writable (permission error or doesn't exist), aborting" . PHP_EOL);
	exit(1);
}

$oldProviderClasses = $providerManager->getMatchingProviders($inputPath);
if(count($oldProviderClasses) === 0){
	fwrite(STDERR, "Unknown input world format" . PHP_EOL);
	exit(1);
}
if(count($oldProviderClasses) > 1){
	fwrite(STDERR, "Ambiguous input world format: matched " . count($oldProviderClasses) . " (" . implode(", ", array_keys($oldProviderClasses)) . ")" . PHP_EOL);
	exit(1);
}
$oldFormat = array_keys($oldProviderClasses)[0];
$oldProviderClass = array_shift($oldProviderClasses);

//LevelDB and ZigriteDB convert natively, keeping every database record rather than only the chunks PHP understands
$native = [WorldMigration::LEVELDB, WorldMigration::ZIGRITEDB];
if(in_array($oldFormat, $native, true) && in_array($args["format"], $native, true)){
	try{
		$result = WorldMigration::convert($inputPath, $args["format"], $backupPath);
	}catch(\Throwable $e){
		fwrite(STDERR, $e->getMessage() . PHP_EOL);
		exit(1);
	}
	echo "Converted $inputPath to " . $args["format"] . ": " . $result["chunk_records"] . " chunk records, " . $result["other_records"] . " other records and " . $result["files"] . " world files were copied and checked. The original is at " . $result["backup"] . PHP_EOL;
	exit(0);
}
$oldProvider = $oldProviderClass->fromPath($inputPath, new \PrefixedLogger(\GlobalLogger::get(), "Old World Provider"));

$converter = new FormatConverter($oldProvider, $writableFormats[$args["format"]], $backupPath, \GlobalLogger::get());
$converter->execute();
