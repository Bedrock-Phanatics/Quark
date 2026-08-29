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

namespace quark\block;

use quark\block\tile\Banner as TileBanner;
use quark\block\utils\BannerPatternLayer;
use quark\block\utils\Colored;
use quark\block\utils\ColoredTrait;
use quark\block\utils\SupportType;
use quark\item\Banner as ItemBanner;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use function assert;
use function count;

abstract class BaseBanner extends Transparent implements Colored{
	use ColoredTrait;

	/**
	 * @var BannerPatternLayer[]
	 * @phpstan-var list<BannerPatternLayer>
	 */
	protected array $patterns = [];

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBanner){
			if($tile->getType() === TileBanner::TYPE_OMINOUS){
				//illager banner is implemented as a separate block, as it doesn't support base color or custom patterns
				return $this->getOminousVersion();
			}
			$this->color = $tile->getBaseColor();
			$this->setPatterns($tile->getPatterns());
		}

		return $this;
	}

	/**
	 * TODO: make this abstract in PM6 (BC break)
	 */
	protected function getOminousVersion() : Block{
		return VanillaBlocks::AIR();
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileBanner);
		$tile->setBaseColor($this->color);
		$tile->setPatterns($this->patterns);
	}

	public function isSolid() : bool{
		return false;
	}

	public function getMaxStackSize() : int{
		return 16;
	}

	/**
	 * @return BannerPatternLayer[]
	 * @phpstan-return list<BannerPatternLayer>
	 */
	public function getPatterns() : array{
		return $this->patterns;
	}

	/**
	 * @param BannerPatternLayer[] $patterns
	 *
	 * @phpstan-param list<BannerPatternLayer> $patterns
	 * @return $this
	 */
	public function setPatterns(array $patterns) : self{
		foreach($patterns as $pattern){
			if(!$pattern instanceof BannerPatternLayer){
				throw new \TypeError("Array must only contain " . BannerPatternLayer::class . " objects");
			}
		}
		$this->patterns = $patterns;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->isSolid();
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$this->canBeSupportedBy($blockReplace->getSide($this->getSupportingFace()))){
			return false;
		}
		if($item instanceof ItemBanner){
			$this->color = $item->getColor();
			$this->setPatterns($item->getPatterns());
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	abstract protected function getSupportingFace() : int;

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedBy($this->getSide($this->getSupportingFace()))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drop = $this->asItem();
		if($drop instanceof ItemBanner && count($this->patterns) > 0){
			$drop->setPatterns($this->patterns);
		}

		return [$drop];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		$result = $this->asItem();
		if($addUserData && $result instanceof ItemBanner && count($this->patterns) > 0){
			$result->setPatterns($this->patterns);
		}
		return $result;
	}

	public function asItem() : Item{
		return VanillaItems::BANNER()->setColor($this->color);
	}
}
