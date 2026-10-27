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
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use quark\network\mcpe\protocol\types\ItemStackRequestData;
use quark\network\mcpe\protocol\types\PackedItemUseLegacyInventoryTransaction;
use quark\network\mcpe\protocol\types\PlayerBlockAction;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class PlayerAuthInputPacket{
	public const int ID = PacketId::PLAYER_AUTH_INPUT;

	/**
	 * @param list<int> $inputData
	 * @param list<PlayerBlockAction>|null $playerBlockActions
	 */
	public function __construct(
		public Vector2 $playerRotation,
		public Vector3 $position,
		public Vector2 $moveVector,
		public float $playerHeadRotation,
		public array $inputData,
		public int $inputMode,
		public int $playMode,
		public int $newInteractionModel,
		public Vector2 $interactRotation,
		public int $clientTick,
		public Vector3 $posDelta,
		public ?PackedItemUseLegacyInventoryTransaction $itemUseTransaction,
		public ?ItemStackRequestData $itemStackRequest,
		public ?array $playerBlockActions,
		public ?Vector2 $vehicleRotation,
		public ?int $clientPredictedVehicle,
		public Vector2 $analogMoveVector,
		public Vector3 $cameraOrientation,
		public Vector2 $rawMoveVector,
	){}
}
