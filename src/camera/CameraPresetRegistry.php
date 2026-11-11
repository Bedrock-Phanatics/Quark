<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use function array_keys;
use function array_reverse;
use function count;
use function ksort;
use function str_starts_with;
use const SORT_STRING;

final class CameraPresetRegistry{
	/** @var array<string, CameraPreset> */
	private array $presets = [];
	/** @var array<string, int>|null */
	private ?array $ids = null;

	public function __construct(){
		foreach([
			CameraPreset::FIRST_PERSON, CameraPreset::THIRD_PERSON, CameraPreset::THIRD_PERSON_FRONT,
			CameraPreset::FREE, CameraPreset::FOLLOW_ORBIT, CameraPreset::FIXED_BOOM, CameraPreset::CONTROL_SCHEME
		] as $name){
			$this->presets[$name] = new CameraPreset($name, parent: "");
		}
	}

	public function register(CameraPreset $preset) : void{
		if($this->isFrozen()){
			throw new \LogicException("Camera presets cannot be changed after synchronization starts");
		}
		if(isset($this->presets[$preset->name])){
			throw new \InvalidArgumentException("Camera preset already registered: $preset->name");
		}
		if(str_starts_with($preset->name, "minecraft:") || $preset->parent === ""){
			throw new \InvalidArgumentException("Custom camera presets require a custom namespace and a parent");
		}
		if(count($this->presets) >= 1024){
			throw new \InvalidArgumentException("At most 1024 camera presets may be registered");
		}
		$this->presets[$preset->name] = $preset;
	}

	public function get(string $name) : CameraPreset{
		return $this->presets[$name] ?? throw new \InvalidArgumentException("Unknown camera preset: $name");
	}

	public function getId(string $name) : int{
		if($this->ids === null){
			throw new \LogicException("Camera presets must be frozen before resolving network IDs");
		}
		return $this->ids[$name] ?? throw new \InvalidArgumentException("Unknown camera preset: $name");
	}

	public function isFrozen() : bool{
		return $this->ids !== null;
	}

	public function freeze() : void{
		if($this->isFrozen()){
			return;
		}
		$completed = [];
		foreach($this->presets as $preset){
			$name = $preset->name;
			$path = [];
			while($name !== "" && !isset($completed[$name])){
				if(isset($path[$name])){
					throw new \InvalidArgumentException("Camera preset inheritance cycle at $name");
				}
				$path[$name] = $name;
				$name = $this->get($name)->parent;
			}
			[$minYaw, $maxYaw] = $name === "" ? [null, null] : $completed[$name];
			foreach(array_reverse($path) as $ancestor){
				$definition = $this->get($ancestor);
				$minYaw = $definition->yawLimitMin ?? $minYaw;
				$maxYaw = $definition->yawLimitMax ?? $maxYaw;
				if($minYaw !== null && $maxYaw !== null && $minYaw > $maxYaw){
					throw new \InvalidArgumentException("Inherited minimum yaw exceeds maximum yaw in $ancestor");
				}
				$completed[$ancestor] = [$minYaw, $maxYaw];
			}
		}
		$sorted = $this->presets;
		ksort($sorted, SORT_STRING);
		$ids = [];
		foreach(array_keys($sorted) as $id => $name){
			$ids[$name] = $id;
		}
		$this->presets = $sorted;
		$this->ids = $ids;
	}

	public function createPacket() : CameraPresetsPacket{
		$this->freeze();
		$presets = [];
		foreach($this->presets as $preset){
			$presets[] = $preset->toProtocol();
		}
		return CameraPresetsPacket::create($presets);
	}

	/** @internal */
	public function validateAimAssist(CameraAimAssistRegistry $registry) : void{
		foreach($this->presets as $preset){
			$id = $preset->aimAssist?->getPresetId();
			if($id !== null && !$registry->hasPreset($id)){
				throw new \InvalidArgumentException("Unknown aim assist preset: $id");
			}
		}
	}
}
