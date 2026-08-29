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

namespace quark\inventory\transaction\action\validator;

use quark\inventory\Inventory;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\Item;
use quark\utils\Utils;

class CallbackSlotValidator implements SlotValidator{
	/**
	 * @phpstan-param \Closure(Inventory, Item, int) : ?TransactionValidationException $validate
	 */
	public function __construct(
		private \Closure $validate
	){
		Utils::validateCallableSignature(function(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{ return null; }, $validate);
	}

	public function validate(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{
		return ($this->validate)($inventory, $item, $slot);
	}
}
