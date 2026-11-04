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

namespace quarktests\ScriptTest;

use pocketmine\nbt\tag\CompoundTag;
use quark\block\custom\BlockComponents;
use quark\block\custom\BlockDefinition;
use quark\block\custom\BlockRegistry;
use quark\block\custom\BlockState;
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\inventory\CreativeCategory;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\plugin\PluginBase;

/**
 * @main quarktests\ScriptTest\Main
 * @api 6.0.0
 * @load STARTUP
 */
class SafeTile extends Tile{
	public string $owner = "";

	public function readSaveData(CompoundTag $nbt) : void{
		$this->owner = $nbt->getString("owner", "");
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setString("owner", $this->owner);
	}
}

class Main extends PluginBase{
	public function onEnable() : void{
		ItemRegistry::getInstance()->register(ItemDefinition::item("script_test:gem", "Test Gem")->withCreativeCategory(), $this);
		$blocks = BlockRegistry::getInstance();
		$blocks->register(BlockDefinition::cube("script_test:ruby_block", "Ruby Block", "ruby_block")->lightLevel(7)->hardness(0.5)->blastResistance(1)->creative(CreativeCategory::CONSTRUCTION), $this);
		$blocks->register(BlockDefinition::cube("script_test:vault", "Vault", "vault")->hardness(50)->blastResistance(3600000), $this);
		$blocks->register(BlockDefinition::cube("script_test:lamp", "Lamp", "lamp")->states(BlockState::bool("script_test:lit"))->creative(CreativeCategory::CONSTRUCTION), $this);
		$blocks->register(BlockDefinition::door("script_test:door", "Ruby Door")->withComponents((new BlockComponents())->geometry("geometry.ruby_door")->texture("ruby_block")), $this);
		TileFactory::getInstance()->register(SafeTile::class, ["script_test:safe"]);
		$blocks->register(BlockDefinition::cube("script_test:safe", "Safe", "safe")->tile(SafeTile::class), $this);
		$blocks->register(BlockDefinition::slab("script_test:ruby_slab", "Ruby")->withComponents((new BlockComponents())->geometry("geometry.ruby_slab")->texture("ruby_block")), $this);
	}
}
