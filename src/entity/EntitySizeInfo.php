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

namespace quark\entity;

use function min;

final class EntitySizeInfo{
	private float $eyeHeight;

	public function __construct(
		private float $height,
		private float $width,
		?float $eyeHeight = null
	){
		$this->eyeHeight = $eyeHeight ?? min($this->height / 2 + 0.1, $this->height);
	}

	public function getHeight() : float{ return $this->height; }

	public function getWidth() : float{ return $this->width; }

	public function getEyeHeight() : float{ return $this->eyeHeight; }

	public function scale(float $newScale) : self{
		return new self(
			$this->height * $newScale,
			$this->width * $newScale,
			$this->eyeHeight * $newScale
		);
	}
}
