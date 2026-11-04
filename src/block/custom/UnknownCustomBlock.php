<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\block\Block;
use quark\block\BlockBreakInfo;
use quark\block\BlockIdentifier;
use quark\block\BlockTypeInfo;
use quark\data\bedrock\block\BlockStateData;
use quark\item\Item;
use quark\item\VanillaItems;

/**
 * Inert, unbreakable stand-in for a saved custom block state whose definition is missing or no longer accepts it.
 * It saves back exactly as it was loaded, so restoring the plugin restores the block.
 */
final class UnknownCustomBlock extends Block{
	/** @internal */
	public function __construct(BlockIdentifier $idInfo, private readonly BlockStateData $savedState){
		parent::__construct($idInfo, "Unavailable block (" . $savedState->getName() . ")", new BlockTypeInfo(BlockBreakInfo::indestructible()));
	}

	public function getSavedState() : BlockStateData{ return $this->savedState; }

	public function getPickedItem(bool $addUserData = false) : Item{ return VanillaItems::AIR(); }

	public function getDropsForCompatibleTool(Item $item) : array{ return []; }
}
