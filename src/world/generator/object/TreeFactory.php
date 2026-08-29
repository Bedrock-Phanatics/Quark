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

namespace quark\world\generator\object;

use quark\block\VanillaBlocks;
use quark\utils\Random;

final class TreeFactory{

	/**
	 * @param TreeType|null $type default oak
	 */
	public static function get(Random $random, ?TreeType $type = null) : ?Tree{
		return match($type){
			null, TreeType::OAK => new OakTree(), //TODO: big oak has a 1/10 chance
			TreeType::SPRUCE => new SpruceTree(),
			TreeType::JUNGLE => new JungleTree(),
			TreeType::ACACIA => new AcaciaTree(),
			TreeType::BIRCH => new BirchTree($random->nextBoundedInt(39) === 0),
			TreeType::AZALEA => new AzaleaTree(),
			TreeType::CRIMSON => new NetherTree(VanillaBlocks::CRIMSON_STEM(), VanillaBlocks::NETHER_WART_BLOCK(), VanillaBlocks::SHROOMLIGHT(), ($random->nextBoundedInt(9) + 4) * ($random->nextBoundedInt(12) === 0 ? 2 : 1), hasVines: true, huge: $random->nextFloat() < 0.06),
			TreeType::WARPED => new NetherTree(VanillaBlocks::WARPED_STEM(), VanillaBlocks::WARPED_WART_BLOCK(), VanillaBlocks::SHROOMLIGHT(), ($random->nextBoundedInt(9) + 4) * ($random->nextBoundedInt(12) === 0 ? 2 : 1), hasVines: false, huge: $random->nextFloat() < 0.06),
			default => null,
		};
	}
}
