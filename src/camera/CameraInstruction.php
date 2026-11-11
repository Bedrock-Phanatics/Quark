<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\CameraAimAssistPacket;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraShakePacket;
use pocketmine\network\mcpe\protocol\ClientboundControlSchemeSetPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PlayerFogPacket;
use pocketmine\network\mcpe\protocol\SetHudPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistActionType;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistTargetMode;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionColor;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionTime;
use pocketmine\network\mcpe\protocol\types\camera\CameraFovInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEase;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionRotation;
use pocketmine\network\mcpe\protocol\types\camera\CameraTargetInstruction;
use pocketmine\network\mcpe\protocol\types\ControlScheme;
use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use quark\entity\Entity;
use quark\math\Vector2;
use quark\math\Vector3;
use function array_values;
use function count;
use function in_array;
use function preg_match;
use function strlen;

final class CameraInstruction{

	/**
	 * @param \Closure(CameraPresetRegistry, ?int): ClientboundPacket $factory
	 * @param \WeakReference<Entity>|null                             $entity
	 */
	private function __construct(
		private readonly \Closure $factory,
		public readonly ?string $preset = null,
		private readonly ?\WeakReference $entity = null,
		public readonly bool $requiresEntity = false,
		public readonly ?string $aimAssistPreset = null
	){}

	public static function set(
		string $preset = "minecraft:free",
		?Vector3 $position = null,
		?float $pitch = null,
		?float $yaw = null,
		?Vector3 $facing = null,
		CameraEase $ease = CameraEase::LINEAR,
		float $duration = 0.0,
		?Vector2 $viewOffset = null,
		?Vector3 $entityOffset = null,
		?bool $default = null,
		bool $ignoreStartingValues = false
	) : self{
		CameraPreset::validateIdentifier($preset);
		CameraValidation::duration($duration);
		if(($pitch === null) !== ($yaw === null) || ($pitch !== null && $facing !== null)){
			throw new \InvalidArgumentException("Supply pitch and yaw together, or a facing position");
		}
		if($pitch !== null && $yaw !== null){
			CameraValidation::number($pitch, -90, 90, "pitch");
			CameraValidation::number($yaw, -180, 180, "yaw");
		}
		foreach([$position, $facing, $entityOffset] as $vector){
			if($vector !== null){
				CameraValidation::vector3($vector);
			}
		}
		if($viewOffset !== null){
			CameraValidation::vector2($viewOffset);
		}
		$position = $position === null ? null : new Vector3($position->x, $position->y, $position->z);
		$facing = $facing === null ? null : new Vector3($facing->x, $facing->y, $facing->z);
		$viewOffset = $viewOffset === null ? null : new Vector2($viewOffset->x, $viewOffset->y);
		$entityOffset = $entityOffset === null ? null : new Vector3($entityOffset->x, $entityOffset->y, $entityOffset->z);
		return new self(static function(CameraPresetRegistry $registry) use ($preset, $duration, $ease, $position, $pitch, $yaw, $facing, $viewOffset, $entityOffset, $default, $ignoreStartingValues) : CameraInstructionPacket{
			$id = $registry->getId($preset);
			$root = $registry->get($preset);
			while($root->parent !== ""){
				$root = $registry->get($root->parent);
			}
			if(in_array($root->name, [CameraPreset::FIRST_PERSON, CameraPreset::THIRD_PERSON, CameraPreset::THIRD_PERSON_FRONT], true) && ($duration > 0 || $position !== null || $pitch !== null || $facing !== null)){
				throw new \InvalidArgumentException("Gameplay camera perspectives do not support free-camera transforms or easing");
			}
			return CameraInstructionPacket::create(
				new CameraSetInstruction(
					$id,
					$duration === 0.0 ? null : new CameraSetInstructionEase($ease->toProtocol(), $duration),
					$position === null ? null : clone $position,
					$pitch === null || $yaw === null ? null : new CameraSetInstructionRotation($pitch, $yaw),
					$facing === null ? null : clone $facing,
					$viewOffset === null ? null : clone $viewOffset,
					$entityOffset === null ? null : clone $entityOffset,
					$default, $ignoreStartingValues
				), null, null, null, null, null, null, null, null
			);
		}, $preset);
	}

	public static function clear() : self{
		return new self(static fn() => CameraInstructionPacket::create(null, true, null, null, true, null, null, null, true));
	}

	public static function fade(float $in = 0.5, float $stay = 1.0, float $out = 0.5, float $red = 0.0, float $green = 0.0, float $blue = 0.0) : self{
		foreach([$in, $stay, $out] as $duration){
			CameraValidation::number($duration, 0, 10, "fade duration");
		}
		foreach([$red, $green, $blue] as $color){
			CameraValidation::number($color, 0, 1, "fade color");
		}
		return new self(static fn() => CameraInstructionPacket::create(null, null,
			new CameraFadeInstruction(new CameraFadeInstructionTime($in, $stay, $out), new CameraFadeInstructionColor($red, $green, $blue)),
			null, null, null, null, null, null));
	}

	public static function fov(?float $degrees, float $duration = 0.0, CameraEase $ease = CameraEase::LINEAR) : self{
		if($degrees !== null){
			CameraValidation::number($degrees, 1, 179, "FOV");
		}
		CameraValidation::duration($duration);
		return new self(static fn() => CameraInstructionPacket::create(null, null, null, null, null,
			new CameraFovInstruction($degrees ?? 70.0, $duration, $ease->toProtocol(), $degrees === null), null, null, null));
	}

	public static function track(Entity $entity, ?Vector3 $offset = null) : self{
		if($offset !== null){
			CameraValidation::vector3($offset);
			$offset = new Vector3($offset->x, $offset->y, $offset->z);
		}
		return new self(static fn(CameraPresetRegistry $registry, ?int $id) => CameraInstructionPacket::create(null, null, null,
			new CameraTargetInstruction($offset === null ? null : clone $offset, $id ?? throw new \LogicException("Missing entity")), null, null, null, null, null),
			entity: \WeakReference::create($entity), requiresEntity: true);
	}

	public static function stopTracking() : self{
		return new self(static fn() => CameraInstructionPacket::create(null, null, null, null, true, null, null, null, null));
	}

	public static function attach(Entity $entity) : self{
		return new self(static fn(CameraPresetRegistry $registry, ?int $id) => CameraInstructionPacket::create(null, null, null, null, null, null, null, $id, null),
			entity: \WeakReference::create($entity), requiresEntity: true);
	}

	public static function detach() : self{
		return new self(static fn() => CameraInstructionPacket::create(null, null, null, null, null, null, null, null, true));
	}

	public static function shake(float $intensity, float $duration, bool $rotational = false) : self{
		CameraValidation::number($intensity, 0, 4, "shake intensity");
		CameraValidation::duration($duration);
		return new self(static fn() => CameraShakePacket::create($intensity, $duration,
			$rotational ? CameraShakePacket::TYPE_ROTATIONAL : CameraShakePacket::TYPE_POSITIONAL, CameraShakePacket::ACTION_ADD));
	}

	public static function stopShake() : self{
		return new self(static fn() => CameraShakePacket::create(0.0, 0.0, CameraShakePacket::TYPE_POSITIONAL, CameraShakePacket::ACTION_STOP));
	}

	public static function hud(HudVisibility $visibility, HudElement ...$elements) : self{
		return new self(static fn() => SetHudPacket::create($elements, $visibility));
	}

	/** @param list<string> $stack Resource-pack fog identifiers, replacing the client's fog stack. */
	public static function fog(array $stack) : self{
		if(count($stack) > 64){
			throw new \InvalidArgumentException("Too many fog layers");
		}
		foreach($stack as $identifier){
			if(strlen($identifier) > 128 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_\/.-]+$/D', $identifier) !== 1){
				throw new \InvalidArgumentException("Invalid fog identifier");
			}
		}
		return new self(static fn() => PlayerFogPacket::create(array_values($stack)));
	}

	public static function controlScheme(ControlScheme $scheme) : self{
		return new self(static fn() => ClientboundControlSchemeSetPacket::create($scheme));
	}

	public static function aimAssist(string $preset, Vector2 $angle, float $distance, CameraAimAssistTargetMode $mode) : self{
		CameraValidation::number($angle->x, 0, 90, "aim assist horizontal angle");
		CameraValidation::number($angle->y, 0, 90, "aim assist vertical angle");
		CameraValidation::number($distance, 0, 64, "aim assist distance");
		$angle = new Vector2($angle->x, $angle->y);
		return new self(static fn() => CameraAimAssistPacket::create($preset, clone $angle, $distance, $mode, CameraAimAssistActionType::SET, false), aimAssistPreset: $preset);
	}

	public static function clearAimAssist() : self{
		return new self(static fn() => CameraAimAssistPacket::create("", new Vector2(0, 0), 0.0,
			CameraAimAssistTargetMode::ANGLE, CameraAimAssistActionType::CLEAR, false));
	}

	public function getEntity() : ?Entity{
		$entity = $this->entity?->get();
		return $entity instanceof Entity ? $entity : null;
	}

	/** @internal */
	public function createPacket(CameraPresetRegistry $registry, ?int $entityId = null) : ClientboundPacket{
		return ($this->factory)($registry, $entityId);
	}
}
