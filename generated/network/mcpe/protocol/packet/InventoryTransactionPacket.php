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

use Bedwire\PacketId;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\mcpe\protocol\types\ItemReleaseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseOnActorInventoryTransaction;
use quark\network\mcpe\protocol\types\LegacySetSlot;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class InventoryTransactionPacket{
	public const int ID = PacketId::INVENTORY_TRANSACTION;

	/**
	 * @param list<LegacySetSlot>|null $legacySetItemSlots
	 * @param array{normal_transaction_data: list<InventoryAction>}|array{inventory_mismatch_data: list<InventoryAction>}|array{item_use_inventory_transaction: ItemUseInventoryTransaction}|array{item_use_on_actor_inventory_transaction: ItemUseOnActorInventoryTransaction}|array{item_release_inventory_transaction: ItemReleaseInventoryTransaction} $transaction
	 */
	public function __construct(
		public int $legacyRequestId,
		public ?array $legacySetItemSlots,
		public array $transaction,
	){}
}
