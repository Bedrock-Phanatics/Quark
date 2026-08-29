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

use quark\block\BlockTest;
use quark\block\RuntimeBlockStateRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/* This script needs to be re-run after any intentional blockfactory change (adding or removing a block state). */

[$newTable, $newTiles] = BlockTest::computeConsistencyCheckTable(RuntimeBlockStateRegistry::getInstance());

$oldTablePath = __DIR__ . '/block_factory_consistency_check.json';
if(file_exists($oldTablePath)){
	$errors = BlockTest::computeConsistencyCheckDiff($oldTablePath, $newTable, $newTiles);

	if(count($errors) > 0){
		echo count($errors) . " changes detected:\n";
		foreach($errors as $error){
			echo $error . "\n";
		}
	}else{
		echo "No changes detected\n";
	}
}else{
	echo "WARNING: Unable to calculate diff, no previous consistency check file found\n";
}

ksort($newTable, SORT_STRING);
ksort($newTiles, SORT_STRING);

file_put_contents($oldTablePath, json_encode(["stateCounts" => $newTable, "tiles" => $newTiles], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
