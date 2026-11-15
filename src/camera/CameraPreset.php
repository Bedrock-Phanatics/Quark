<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\types\camera\CameraPreset as ProtocolCameraPreset;
use pocketmine\network\mcpe\protocol\types\camera\CameraPresetAimAssist;
use pocketmine\network\mcpe\protocol\types\ControlScheme;
use quark\math\Vector2;
use quark\math\Vector3;
use function preg_match;
use function strlen;

final class CameraPreset{
	public const string FIRST_PERSON = "minecraft:first_person";
	public const string THIRD_PERSON = "minecraft:third_person";
	public const string THIRD_PERSON_FRONT = "minecraft:third_person_front";
	public const string FREE = "minecraft:free";
	public const string FOLLOW_ORBIT = "minecraft:follow_orbit";
	public const string FIXED_BOOM = "minecraft:fixed_boom";
	public const string CONTROL_SCHEME = "minecraft:control_scheme_camera";

	private readonly ?Vector2 $horizontalRotationLimitValue;
	private readonly ?Vector2 $verticalRotationLimitValue;
	private readonly ?Vector2 $viewOffsetValue;
	private readonly ?Vector3 $entityOffsetValue;
	private readonly ?Vector2 $startingRotationValue;
	private readonly ?CameraPresetAimAssist $aimAssistValue;

	public ?Vector2 $horizontalRotationLimit{
		get => $this->horizontalRotationLimitValue === null ? null : new Vector2($this->horizontalRotationLimitValue->x, $this->horizontalRotationLimitValue->y);
	}
	public ?Vector2 $verticalRotationLimit{
		get => $this->verticalRotationLimitValue === null ? null : new Vector2($this->verticalRotationLimitValue->x, $this->verticalRotationLimitValue->y);
	}
	public ?Vector2 $viewOffset{
		get => $this->viewOffsetValue === null ? null : new Vector2($this->viewOffsetValue->x, $this->viewOffsetValue->y);
	}
	public ?Vector3 $entityOffset{
		get => $this->entityOffsetValue === null ? null : new Vector3($this->entityOffsetValue->x, $this->entityOffsetValue->y, $this->entityOffsetValue->z);
	}
	public ?Vector2 $startingRotation{
		get => $this->startingRotationValue === null ? null : new Vector2($this->startingRotationValue->x, $this->startingRotationValue->y);
	}
	public ?CameraPresetAimAssist $aimAssist{
		get => $this->aimAssistValue === null ? null : self::copyAimAssist($this->aimAssistValue);
	}

	public function __construct(
		public readonly string $name,
		public readonly string $parent = self::FREE,
		public readonly ?float $xPosition = null,
		public readonly ?float $yPosition = null,
		public readonly ?float $zPosition = null,
		public readonly ?float $pitch = null,
		public readonly ?float $yaw = null,
		public readonly ?float $rotationSpeed = null,
		public readonly ?bool $snapToTarget = null,
		?Vector2 $horizontalRotationLimit = null,
		?Vector2 $verticalRotationLimit = null,
		public readonly ?bool $continueTargeting = null,
		public readonly ?float $blockListeningRadius = null,
		?Vector2 $viewOffset = null,
		?Vector3 $entityOffset = null,
		public readonly ?float $radius = null,
		public readonly ?float $yawLimitMin = null,
		public readonly ?float $yawLimitMax = null,
		public readonly ?int $audioListenerType = null,
		public readonly ?bool $playerEffects = null,
		?CameraPresetAimAssist $aimAssist = null,
		public readonly ?ControlScheme $controlScheme = null,
		public readonly bool $applyInheritedStartingRotation = false,
		?Vector2 $startingRotation = null
	){
		self::validateIdentifier($name);
		if($parent !== ""){
			self::validateIdentifier($parent);
		}
		foreach([$xPosition, $yPosition, $zPosition] as $coordinate){
			if($coordinate !== null){
				CameraValidation::number($coordinate, -30_000_000, 30_000_000, "Position");
			}
		}
		if($pitch !== null){
			CameraValidation::number($pitch, -90, 90, "Pitch");
		}
		if($yaw !== null){
			CameraValidation::number($yaw, -180, 180, "Yaw");
		}
		if($rotationSpeed !== null){
			CameraValidation::number($rotationSpeed, 0, 30_000_000, "Rotation speed");
		}
		if($blockListeningRadius !== null){
			CameraValidation::number($blockListeningRadius, 0, 1024, "Tracking radius");
		}
		if($radius !== null){
			CameraValidation::number($radius, 0.1, 100, "Radius");
		}
		foreach([$yawLimitMin, $yawLimitMax] as $limit){
			if($limit !== null){
				CameraValidation::number($limit, -180, 180, "Yaw limit");
			}
		}
		if($yawLimitMin !== null && $yawLimitMax !== null && $yawLimitMin > $yawLimitMax){
			throw new \InvalidArgumentException("Minimum yaw must not exceed maximum yaw");
		}
		if($audioListenerType !== null && $audioListenerType !== ProtocolCameraPreset::AUDIO_LISTENER_TYPE_CAMERA && $audioListenerType !== ProtocolCameraPreset::AUDIO_LISTENER_TYPE_PLAYER){
			throw new \InvalidArgumentException("Unknown camera audio listener type");
		}
		if($horizontalRotationLimit !== null){
			CameraValidation::number($horizontalRotationLimit->x, 0, 360, "Left rotation limit");
			CameraValidation::number($horizontalRotationLimit->y, 0, 360 - $horizontalRotationLimit->x, "Right rotation limit");
		}
		if($verticalRotationLimit !== null){
			CameraValidation::number($verticalRotationLimit->x, 0, 180, "Lower rotation limit");
			CameraValidation::number($verticalRotationLimit->y, 0, 180 - $verticalRotationLimit->x, "Upper rotation limit");
		}
		if($viewOffset !== null){
			CameraValidation::vector2($viewOffset);
		}
		if($entityOffset !== null){
			CameraValidation::vector3($entityOffset, 100);
		}
		if($startingRotation !== null){
			CameraValidation::number($startingRotation->x, -90, 90, "Starting pitch");
			CameraValidation::number($startingRotation->y, -180, 180, "Starting yaw");
		}
		if($aimAssist !== null){
			if(($id = $aimAssist->getPresetId()) !== null){
				self::validateIdentifier($id);
			}
			if(($angle = $aimAssist->getViewAngle()) !== null){
				CameraValidation::number($angle->x, 0, 90, "Aim assist angle x");
				CameraValidation::number($angle->y, 0, 90, "Aim assist angle y");
			}
			if(($distance = $aimAssist->getDistance()) !== null){
				CameraValidation::number($distance, 0, 1024, "Aim assist distance");
			}
		}
		$this->horizontalRotationLimitValue = $horizontalRotationLimit === null ? null : new Vector2($horizontalRotationLimit->x, $horizontalRotationLimit->y);
		$this->verticalRotationLimitValue = $verticalRotationLimit === null ? null : new Vector2($verticalRotationLimit->x, $verticalRotationLimit->y);
		$this->viewOffsetValue = $viewOffset === null ? null : new Vector2($viewOffset->x, $viewOffset->y);
		$this->entityOffsetValue = $entityOffset === null ? null : new Vector3($entityOffset->x, $entityOffset->y, $entityOffset->z);
		$this->startingRotationValue = $startingRotation === null ? null : new Vector2($startingRotation->x, $startingRotation->y);
		$this->aimAssistValue = $aimAssist === null ? null : self::copyAimAssist($aimAssist);
	}

	private static function copyAimAssist(CameraPresetAimAssist $aimAssist) : CameraPresetAimAssist{
		$angle = $aimAssist->getViewAngle();
		return new CameraPresetAimAssist(
			$aimAssist->getPresetId(), $aimAssist->getTargetMode(),
			$angle === null ? null : new Vector2($angle->x, $angle->y), $aimAssist->getDistance()
		);
	}

	public static function validateIdentifier(string $identifier) : void{
		if(strlen($identifier) > 256 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1){
			throw new \InvalidArgumentException("Invalid camera identifier: $identifier");
		}
	}

	public function toProtocol() : ProtocolCameraPreset{
		return new ProtocolCameraPreset(
			$this->name, $this->parent,
			$this->xPosition, $this->yPosition, $this->zPosition, $this->pitch, $this->yaw,
			$this->rotationSpeed, $this->snapToTarget, $this->horizontalRotationLimit, $this->verticalRotationLimit,
			$this->continueTargeting, $this->blockListeningRadius, $this->viewOffset, $this->entityOffset,
			$this->radius, $this->yawLimitMin, $this->yawLimitMax, $this->audioListenerType, $this->playerEffects,
			$this->aimAssist, $this->controlScheme, $this->applyInheritedStartingRotation, $this->startingRotation
		);
	}
}
