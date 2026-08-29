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

use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\item\VanillaItems;

class Tripwire extends Flowable{
	protected bool $triggered = false;
	protected bool $suspended = false; //unclear usage, makes hitbox bigger if set
	protected bool $connected = false;
	protected bool $disarmed = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->triggered);
		$w->bool($this->suspended);
		$w->bool($this->connected);
		$w->bool($this->disarmed);
	}

	public function isTriggered() : bool{ return $this->triggered; }

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		$this->triggered = $triggered;
		return $this;
	}

	public function isSuspended() : bool{ return $this->suspended; }

	/** @return $this */
	public function setSuspended(bool $suspended) : self{
		$this->suspended = $suspended;
		return $this;
	}

	public function isConnected() : bool{ return $this->connected; }

	/** @return $this */
	public function setConnected(bool $connected) : self{
		$this->connected = $connected;
		return $this;
	}

	public function isDisarmed() : bool{ return $this->disarmed; }

	/** @return $this */
	public function setDisarmed(bool $disarmed) : self{
		$this->disarmed = $disarmed;
		return $this;
	}

	public function asItem() : Item{
		return VanillaItems::STRING();
	}
}
