<?php

declare(strict_types=1);

namespace quark\item\custom;

use quark\item\enchantment\ItemEnchantmentTags;
use quark\item\ItemIdentifier;
use quark\item\Sword;
use quark\item\ToolTier;

class CustomSword extends Sword{

	public function __construct(ItemIdentifier $identifier, string $name, private readonly int $attackPoints, private readonly int $durability, ToolTier $tier = ToolTier::DIAMOND){
		parent::__construct($identifier, $name, $tier, [ItemEnchantmentTags::SWORD]);
	}

	public function getAttackPoints() : int{
		return $this->attackPoints;
	}

	public function getMaxDurability() : int{
		return $this->durability;
	}
}
