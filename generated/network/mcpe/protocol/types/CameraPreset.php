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

namespace quark\network\mcpe\protocol\types;

use pocketmine\math\Vector2;
use pocketmine\math\Vector3;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class CameraPreset{
	public function __construct(
		public string $name,
		public string $inheritFrom,
		public ?float $posX,
		public ?float $posY,
		public ?float $posZ,
		public ?float $rotX,
		public ?float $rotY,
		public ?float $rotationSpeed,
		public ?bool $snapToTarget,
		public ?Vector2 $horizontalRotationLimit,
		public ?Vector2 $verticalRotationLimit,
		public ?bool $continueTargeting,
		public ?float $blockListeningRadius,
		public ?Vector2 $viewOffset,
		public ?Vector3 $entityOffset,
		public ?float $radius,
		public ?float $yawLimitMin,
		public ?float $yawLimitMax,
		public ?int $listener,
		public ?bool $playerEffects,
		public ?CameraAimAssistCommandPresetDefinition $aimAssist,
		public ?int $controlScheme,
		public bool $applyInheritedStartingRotation,
		public ?Vector2 $startingRotation = null,
	){}
}
