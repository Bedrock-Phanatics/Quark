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

namespace quark\block\inventory\window;

use quark\block\utils\AnimatedContainerLike;
use quark\inventory\Inventory;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;

/**
 * Window opened from a block. Shown to the client as a generic container unless a subclass says otherwise.
 */
class BlockInventoryWindow extends InventoryWindow{

	public function __construct(
		Player $viewer,
		Inventory $inventory,
		protected Position $holder
	){
		parent::__construct($viewer, $inventory);
	}

	public function getHolder() : Position{ return $this->holder; }

	public function onOpen() : void{
		parent::onOpen();
		$this->getAnimatedBlock()?->onViewerAdded();
	}

	public function onClose() : void{
		$this->getAnimatedBlock()?->onViewerRemoved();
		parent::onClose();
	}

	private function getAnimatedBlock() : ?AnimatedContainerLike{
		if(!$this->holder->isValid()){
			return null;
		}
		$block = $this->holder->getWorld()->getBlock($this->holder);
		return $block instanceof AnimatedContainerLike ? $block : null;
	}
}
