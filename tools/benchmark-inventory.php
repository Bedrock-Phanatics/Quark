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

namespace quark\tools\benchmark_inventory;

use quark\block\VanillaBlocks;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use function dirname;
use function hrtime;
use function json_encode;
use function memory_get_usage;
use function round;
use function sort;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @phpstan-param \Closure() : void $fn
 * @return array{median_ns_per_op: float, ops: int}
 */
function measure(int $ops, \Closure $fn) : array{
	$fn();
	$samples = [];
	for($run = 0; $run < 7; ++$run){
		$start = hrtime(true);
		for($i = 0; $i < $ops; ++$i){
			$fn();
		}
		$samples[] = (hrtime(true) - $start) / $ops;
	}
	sort($samples);
	return ["median_ns_per_op" => round($samples[3], 1), "ops" => $ops];
}

function listened(Inventory $inventory) : Inventory{
	$inventory->getListeners()->add(new CallbackInventoryListener(static function() : void{}, static function() : void{}));
	return $inventory;
}

$stone = VanillaBlocks::STONE()->asItem()->setCount(64);
$sword = VanillaItems::DIAMOND_SWORD()->setCustomName("bench");
$contents = [];
for($i = 0; $i < 36; ++$i){
	$contents[$i] = ($i % 2 === 0 ? $stone : $sword);
}

$player = listened(new SimpleInventory(36));
$slot = 0;
$results = [];
$results["set_item"] = measure(200_000, static function() use ($player, $stone, &$slot) : void{
	$player->setItem($slot, $stone);
	$slot = ($slot + 1) % 36;
});
$results["set_contents_36"] = measure(20_000, static function() use ($player, $contents) : void{ $player->setContents($contents); });
$results["get_contents_36"] = measure(20_000, static function() use ($player) : void{ $player->getContents(); });
$results["add_remove_256"] = measure(20_000, static function() use ($player, $stone) : void{
	$player->clearAll();
	$player->addItem($stone, $stone, $stone, $stone);
	$player->removeItem((clone $stone)->setCount(256));
});

$left = listened(new SimpleInventory(27));
$right = listened(new SimpleInventory(27));
$newCombined = static fn() => new CombinedInventoryProxy([$left, $right]);
$results["double_chest_create"] = measure(100_000, static function() use ($newCombined) : void{ $newCombined(); });
$double = listened($newCombined());
$results["double_chest_set_get"] = measure(200_000, static function() use ($double, $stone, &$slot) : void{
	$double->setItem($slot, $stone);
	$double->getItem(53 - $slot);
	$slot = ($slot + 1) % 54;
});
$results["double_chest_add_remove_256"] = measure(20_000, static function() use ($double, $stone) : void{
	$double->clearAll();
	$double->addItem($stone, $stone, $stone, $stone);
	$double->removeItem((clone $stone)->setCount(256));
});
$results["memory_bytes"] = memory_get_usage();

echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
