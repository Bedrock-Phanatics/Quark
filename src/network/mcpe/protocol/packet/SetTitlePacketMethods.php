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

trait SetTitlePacketMethods{

	public static function title(string $text) : self{
		return new self(self::TYPE_SET_TITLE, $text);
	}

	public static function subtitle(string $text) : self{
		return new self(self::TYPE_SET_SUBTITLE, $text);
	}

	public static function actionBar(string $text) : self{
		return new self(self::TYPE_SET_ACTIONBAR_MESSAGE, $text);
	}

	public static function clearTitle() : self{
		return new self(self::TYPE_CLEAR_TITLE);
	}

	/**
	 * Clears the title and restores the default animation times.
	 */
	public static function resetTitle() : self{
		return new self(self::TYPE_RESET_TITLE);
	}

	/**
	 * Sets how long, in ticks, titles sent after it fade in, stay and fade out.
	 */
	public static function animationTimes(int $fadeIn, int $stay, int $fadeOut) : self{
		return new self(self::TYPE_SET_ANIMATION_TIMES, fadeInTime: $fadeIn, stayTime: $stay, fadeOutTime: $fadeOut);
	}
}
