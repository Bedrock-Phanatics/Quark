<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\CameraAimAssistPresetsPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistCategory;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistCategoryPriority;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPreset;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPresetItemSettings;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPresetsPacketOperation;
use function array_values;
use function count;
use function is_string;
use function ksort;
use function preg_match;
use function strlen;
use const SORT_STRING;

final class CameraAimAssistRegistry{
	private const MAX_ENTRIES = 1024;
	private const MAX_PRIORITY = 0x7fffffff;

	/** @var array<string, CameraAimAssistCategory> */
	private array $categories = [];
	/** @var array<string, CameraAimAssistPreset> */
	private array $presets = [];
	private bool $frozen = false;
	private int $entryCount = 0;

	public function registerCategory(CameraAimAssistCategory $category) : void{
		$this->assertMutable();
		$name = $category->getName();
		self::assertIdentifier($name, true);
		if(isset($this->categories[$name])){
			throw new \InvalidArgumentException("Aim-assist category \"$name\" is already registered");
		}
		if(count($this->categories) >= self::MAX_ENTRIES){
			throw new \InvalidArgumentException("Aim-assist registry cannot contain more than " . self::MAX_ENTRIES . " categories");
		}

		$priorities = $category->getPriorities();
		$entryCount = 0;
		foreach([
			$priorities->getEntities(),
			$priorities->getBlocks(),
			$priorities->getBlockTags(),
			$priorities->getEntityTypeFamilies()
		] as $entries){
			self::assertCount($entries);
			$entryCount += count($entries);
			foreach($entries as $entry){
				if(!$entry instanceof CameraAimAssistCategoryPriority){
					throw new \InvalidArgumentException("Aim-assist priorities must contain CameraAimAssistCategoryPriority values");
				}
				self::assertIdentifier($entry->getIdentifier());
				self::assertPriority($entry->getPriority());
			}
		}
		self::assertOptionalPriority($priorities->getDefaultEntityPriority());
		self::assertOptionalPriority($priorities->getDefaultBlockPriority());
		$this->reserveEntries($entryCount);
		$this->categories[$name] = $category;
	}

	public function registerPreset(CameraAimAssistPreset $preset) : void{
		$this->assertMutable();
		$identifier = $preset->getIdentifier();
		self::assertIdentifier($identifier);
		if(isset($this->presets[$identifier])){
			throw new \InvalidArgumentException("Aim-assist preset \"$identifier\" is already registered");
		}
		if(count($this->presets) >= self::MAX_ENTRIES){
			throw new \InvalidArgumentException("Aim-assist registry cannot contain more than " . self::MAX_ENTRIES . " presets");
		}

		$exclusions = $preset->getExclusionSettings();
		$entryCount = 0;
		foreach([
			$exclusions->getBlocks(),
			$exclusions->getEntities(),
			$exclusions->getBlockTags(),
			$exclusions->getEntityTypeFamilies(),
			$preset->getLiquidTargetingList()
		] as $identifiers){
			self::assertCount($identifiers);
			$entryCount += count($identifiers);
			foreach($identifiers as $value){
				if(!is_string($value)){
					throw new \InvalidArgumentException("Aim-assist exclusions and liquid targets must be strings");
				}
				self::assertIdentifier($value);
			}
		}

		$itemSettings = $preset->getItemSettings();
		self::assertCount($itemSettings);
		$entryCount += count($itemSettings);
		foreach($itemSettings as $setting){
			if(!$setting instanceof CameraAimAssistPresetItemSettings){
				throw new \InvalidArgumentException("Aim-assist item settings must contain CameraAimAssistPresetItemSettings values");
			}
			self::assertIdentifier($setting->getItemIdentifier());
			$this->assertCategoryReference($setting->getCategoryName());
		}
		foreach([$preset->getDefaultItemSettings(), $preset->getDefaultHandSettings()] as $categoryName){
			if($categoryName !== null){
				$this->assertCategoryReference($categoryName);
			}
		}
		$this->reserveEntries($entryCount);
		$this->presets[$identifier] = $preset;
	}

	public function freeze() : void{
		$this->frozen = true;
	}

	public function hasPreset(string $identifier) : bool{
		return isset($this->presets[$identifier]);
	}

	public function createPacket() : CameraAimAssistPresetsPacket{
		$this->freeze();
		$categories = $this->categories;
		$presets = $this->presets;
		ksort($categories, SORT_STRING);
		ksort($presets, SORT_STRING);
		return CameraAimAssistPresetsPacket::create(
			array_values($categories),
			array_values($presets),
			CameraAimAssistPresetsPacketOperation::SET
		);
	}

	private function assertMutable() : void{
		if($this->frozen){
			throw new \LogicException("Aim-assist registry is frozen");
		}
	}

	private function reserveEntries(int $count) : void{
		if($this->entryCount + $count > 4096){
			throw new \InvalidArgumentException("Aim-assist definitions cannot contain more than 4096 total entries");
		}
		$this->entryCount += $count;
	}

	private function assertCategoryReference(string $name) : void{
		self::assertIdentifier($name, true);
		if(!isset($this->categories[$name])){
			throw new \InvalidArgumentException("Aim-assist category \"$name\" is not registered");
		}
	}

	private static function assertIdentifier(string $identifier, bool $allowUnnamespaced = false) : void{
		$pattern = $allowUnnamespaced ? '~\A(?:[a-z0-9_.-]+:)?[a-z0-9_./-]+\z~D' : '~\A[a-z0-9_.-]+:[a-z0-9_./-]+\z~D';
		if(strlen($identifier) > 128 || preg_match($pattern, $identifier) !== 1){
			throw new \InvalidArgumentException("Invalid aim-assist identifier: \"$identifier\"");
		}
	}

	private static function assertPriority(?int $priority) : void{
		if($priority === null || $priority < 0 || $priority > self::MAX_PRIORITY){
			throw new \InvalidArgumentException("Aim-assist priorities must be between 0 and " . self::MAX_PRIORITY);
		}
	}

	private static function assertOptionalPriority(?int $priority) : void{
		if($priority !== null){
			self::assertPriority($priority);
		}
	}

	/** @param array<array-key, mixed> $entries */
	private static function assertCount(array $entries) : void{
		if(count($entries) > self::MAX_ENTRIES){
			throw new \InvalidArgumentException("Aim-assist arrays cannot contain more than " . self::MAX_ENTRIES . " entries");
		}
	}
}
