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

namespace quark\inventory;

use quark\inventory\transaction\action\validator\SlotValidator;
use quark\utils\ObjectSet;

/**
 * A "slot validated inventory" has validators which may restrict items
 * from being placed in particular slots of the inventory when transactions are executed.
 *
 * @phpstan-type SlotValidators ObjectSet<SlotValidator>
 */
interface SlotValidatedInventory{
	/**
	 * Returns a set of validators that will be used to determine whether an item can be placed in a particular slot.
	 * All validators need to return null for the transaction to be allowed.
	 * If one of the validators returns an exception, the transaction will be cancelled.
	 *
	 * There is no guarantee that the validators will be called in any particular order.
	 *
	 * @phpstan-return SlotValidators
	 */
	public function getSlotValidators() : ObjectSet;
}
