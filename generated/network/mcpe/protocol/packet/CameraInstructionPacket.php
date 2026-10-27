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
use quark\network\mcpe\protocol\types\CameraInstructionFade;
use quark\network\mcpe\protocol\types\CameraInstructionFieldOfView;
use quark\network\mcpe\protocol\types\CameraInstructionSet;
use quark\network\mcpe\protocol\types\CameraInstructionTargetData;
use quark\network\mcpe\protocol\types\CameraSplineInstruction;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class CameraInstructionPacket{
	public const int ID = PacketId::CAMERA_INSTRUCTION;

	public function __construct(
		public ?CameraInstructionSet $set = null,
		public ?bool $clear = null,
		public ?CameraInstructionFade $fade = null,
		public ?CameraInstructionTargetData $target = null,
		public ?bool $removeTarget = null,
		public ?CameraInstructionFieldOfView $fieldOfView = null,
		public ?CameraSplineInstruction $spline = null,
		public ?int $attachToEntity = null,
		public ?bool $detachFromEntity = null,
	){}
}
