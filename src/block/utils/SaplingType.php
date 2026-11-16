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

namespace quark\block\utils;

use quark\utils\AssumptionFailedError;
use quark\world\generator\object\TreeType;

enum SaplingType{
	case OAK;
	case SPRUCE;
	case BIRCH;
	case JUNGLE;
	case ACACIA;
	case DARK_OAK;
	case CHERRY;
	case PALE_OAK;

	/**
	 * Returns null if Quark can't generate this tree yet, in which case the sapling never grows.
	 */
	public function getTreeType() : ?TreeType{
		return match($this){
			self::OAK => TreeType::OAK,
			self::SPRUCE => TreeType::SPRUCE,
			self::BIRCH => TreeType::BIRCH,
			self::JUNGLE => TreeType::JUNGLE,
			self::ACACIA => TreeType::ACACIA,
			self::DARK_OAK => TreeType::DARK_OAK,
			//TODO: Implement cherry and pale oak tree generation so these saplings can grow.
			self::CHERRY, self::PALE_OAK => null,
		};
	}

	public function getDisplayName() : string{
		return match($this){
			self::CHERRY => "Cherry",
			self::PALE_OAK => "Pale Oak",
			default => $this->getTreeType()?->getDisplayName() ?? throw new AssumptionFailedError("Missing display name for sapling $this->name"),
		};
	}
}
