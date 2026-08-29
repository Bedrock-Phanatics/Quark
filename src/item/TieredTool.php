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

namespace quark\item;

abstract class TieredTool extends Tool{
	protected ToolTier $tier;

	/**
	 * @param string[] $enchantmentTags
	 */
	public function __construct(ItemIdentifier $identifier, string $name, ToolTier $tier, array $enchantmentTags = []){
		parent::__construct($identifier, $name, $enchantmentTags);
		$this->tier = $tier;
	}

	public function getMaxDurability() : int{
		return $this->tier->getMaxDurability();
	}

	public function getTier() : ToolTier{
		return $this->tier;
	}

	protected function getBaseMiningEfficiency() : float{
		return $this->tier->getBaseEfficiency();
	}

	public function getEnchantability() : int{
		return $this->tier->getEnchantability();
	}

	public function getFuelTime() : int{
		if($this->tier === ToolTier::WOOD){
			return 200;
		}

		return 0;
	}

	public function isFireProof() : bool{
		return $this->tier === ToolTier::NETHERITE;
	}
}
