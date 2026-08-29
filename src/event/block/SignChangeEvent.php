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

namespace quark\event\block;

use quark\block\BaseSign;
use quark\block\utils\SignText;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\player\Player;

/**
 * Called when a sign's text is changed by a player.
 */
class SignChangeEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	private SignText $oldText;

	public function __construct(
		private BaseSign $sign,
		private Player $player,
		private SignText $text,
		private bool $frontFace = true
	){
		$this->oldText = $this->sign->getFaceText($this->frontFace);
		parent::__construct($sign);
	}

	public function getSign() : BaseSign{
		return $this->sign;
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	/**
	 * Returns the text currently on the sign.
	 */
	public function getOldText() : SignText{
		return $this->oldText;
	}

	/**
	 * Returns the text which will be on the sign after the event.
	 */
	public function getNewText() : SignText{
		return $this->text;
	}

	/**
	 * Sets the text to be written on the sign after the event.
	 */
	public function setNewText(SignText $text) : void{
		$this->text = $text;
	}

	public function isFrontFace() : bool{ return $this->frontFace; }
}
