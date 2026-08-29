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

namespace quark\inventory\transaction;

use quark\inventory\Inventory;
use quark\inventory\transaction\action\InventoryAction;
use function spl_object_id;

final class TransactionBuilder{

	/** @var TransactionBuilderInventory[] */
	private array $inventories = [];

	/** @var InventoryAction[] */
	private array $extraActions = [];

	public function addAction(InventoryAction $action) : void{
		$this->extraActions[spl_object_id($action)] = $action;
	}

	public function getInventory(Inventory $inventory) : TransactionBuilderInventory{
		$id = spl_object_id($inventory);
		return $this->inventories[$id] ??= new TransactionBuilderInventory($inventory);
	}

	/**
	 * @return InventoryAction[]
	 */
	public function generateActions() : array{
		$actions = $this->extraActions;

		foreach($this->inventories as $inventory){
			foreach($inventory->generateActions() as $action){
				$actions[spl_object_id($action)] = $action;
			}
		}

		return $actions;
	}
}
