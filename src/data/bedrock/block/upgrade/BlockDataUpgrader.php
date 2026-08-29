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

namespace quark\data\bedrock\block\upgrade;

use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\nbt\tag\CompoundTag;

final class BlockDataUpgrader{

	public function __construct(
		private BlockIdMetaUpgrader $blockIdMetaUpgrader,
		private BlockStateUpgrader $blockStateUpgrader
	){}

	/**
	 * @throws BlockStateDeserializeException
	 */
	public function upgradeIntIdMeta(int $id, int $meta) : BlockStateData{
		return $this->blockIdMetaUpgrader->fromIntIdMeta($id, $meta);
	}

	/**
	 * @throws BlockStateDeserializeException
	 */
	public function upgradeStringIdMeta(string $id, int $meta) : BlockStateData{
		return $this->blockIdMetaUpgrader->fromStringIdMeta($id, $meta);
	}

	/**
	 * @throws BlockStateDeserializeException
	 */
	public function upgradeBlockStateNbt(CompoundTag $tag) : BlockStateData{
		if($tag->getTag("name") !== null && $tag->getTag("val") !== null){
			//Legacy (pre-1.13) blockstate - upgrade it to a version we understand
			$id = $tag->getString("name");
			$data = $tag->getShort("val");

			$blockStateData = $this->upgradeStringIdMeta($id, $data);
		}else{
			//Modern (post-1.13) blockstate
			$blockStateData = BlockStateData::fromNbt($tag);
		}

		return $this->blockStateUpgrader->upgrade($blockStateData);
	}

	public function getBlockStateUpgrader() : BlockStateUpgrader{ return $this->blockStateUpgrader; }

	public function getBlockIdMetaUpgrader() : BlockIdMetaUpgrader{ return $this->blockIdMetaUpgrader; }
}
