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

use quark\block\tile\ContainerTile;
use quark\world\Position;
use quark\world\sound\Sound;
use function count;

trait AnimatedContainerLikeTrait{

	/**
	 * Returns how many players have the menu open, including one that is opening or closing it right now.
	 */
	protected function getViewerCount() : int{
		$position = $this->getPosition();
		$tile = $position->getWorld()->getTile($position);
		return $tile instanceof ContainerTile ? count($tile->getInventory()->getViewers()) : 0;
	}

	abstract protected function getOpenSound() : Sound;

	abstract protected function getCloseSound() : Sound;

	abstract protected function playAnimationVisual(Position $position, bool $isOpen) : void;

	protected function playAnimationSound(Position $position, bool $isOpen) : void{
		$position->getWorld()->addSound($position->add(0.5, 0.5, 0.5), $isOpen ? $this->getOpenSound() : $this->getCloseSound());
	}

	abstract public function getPosition() : Position;

	protected function doAnimationEffects(bool $isOpen) : void{
		$position = $this->getPosition();
		$this->playAnimationVisual($position, $isOpen);
		$this->playAnimationSound($position, $isOpen);
	}

	public function onViewerAdded() : void{
		if($this->getViewerCount() === 1){
			$this->doAnimationEffects(true);
		}
	}

	public function onViewerRemoved() : void{
		if($this->getViewerCount() === 1){
			$this->doAnimationEffects(false);
		}
	}
}
