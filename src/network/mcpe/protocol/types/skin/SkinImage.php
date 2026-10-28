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

namespace quark\network\mcpe\protocol\types\skin;

use function strlen;

class SkinImage{
	public function __construct(
		private int $height,
		private int $width,
		private string $data
	){
		if($height < 0 || $width < 0){
			throw new \InvalidArgumentException("Height and width cannot be negative");
		}
		if(($expected = $height * $width * 4) !== ($actual = strlen($data))){
			throw new \InvalidArgumentException("Data should be exactly $expected bytes, got $actual bytes");
		}
	}

	public static function fromLegacy(string $data) : SkinImage{
		switch(strlen($data)){
			case 64 * 32 * 4:
				return new self(32, 64, $data);
			case 64 * 64 * 4:
				return new self(64, 64, $data);
			case 128 * 128 * 4:
				return new self(128, 128, $data);
		}

		throw new \InvalidArgumentException("Unknown size");
	}

	public function getHeight() : int{
		return $this->height;
	}

	public function getWidth() : int{
		return $this->width;
	}

	public function getData() : string{
		return $this->data;
	}
}
