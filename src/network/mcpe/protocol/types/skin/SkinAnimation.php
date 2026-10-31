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

class SkinAnimation{

	public const TYPE_HEAD = 1;
	public const TYPE_BODY_32 = 2;
	public const TYPE_BODY_64 = 3;

	public const EXPRESSION_LINEAR = 0; //???
	public const EXPRESSION_BLINKING = 1;

	public function __construct(
		private SkinImage $image,
		private int $type,
		private float $frames,
		private int $expressionType
	){
		if($type < 0 || $type > 0xffffffff || $expressionType < 0 || $expressionType > 0xffffffff){
			throw new \InvalidArgumentException("Animation type and expression must be in range 0 - " . 0xffffffff . ", got $type and $expressionType");
		}
	}

	/**
	 * Image of the animation.
	 */
	public function getImage() : SkinImage{
		return $this->image;
	}

	/**
	 * The type of animation you are applying.
	 */
	public function getType() : int{
		return $this->type;
	}

	/**
	 * The total amount of frames in an animation.
	 */
	public function getFrames() : float{
		return $this->frames;
	}

	public function getExpressionType() : int{ return $this->expressionType; }
}
