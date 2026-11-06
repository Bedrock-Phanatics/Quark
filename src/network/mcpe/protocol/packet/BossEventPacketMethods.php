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

namespace quark\network\mcpe\protocol\packet;

trait BossEventPacketMethods{

	/**
	 * @param float $healthPercent how full the bar is, from 0 to 1
	 */
	public static function show(int $bossActorId, string $title, float $healthPercent, int $color = 0, int $overlay = 0) : self{
		return new self($bossActorId, self::TYPE_SHOW, $title, healthPercent: $healthPercent, color: $color, overlay: $overlay);
	}

	public static function hide(int $bossActorId) : self{
		return new self($bossActorId, self::TYPE_HIDE);
	}

	/**
	 * @param float $healthPercent how full the bar is, from 0 to 1
	 */
	public static function healthPercent(int $bossActorId, float $healthPercent) : self{
		return new self($bossActorId, self::TYPE_HEALTH_PERCENT, healthPercent: $healthPercent);
	}

	public static function title(int $bossActorId, string $title) : self{
		return new self($bossActorId, self::TYPE_TITLE, $title);
	}
}
