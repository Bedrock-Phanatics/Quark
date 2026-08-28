<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\block\Block;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\world\particle\BlockPunchParticle;
use pocketmine\world\sound\BlockPunchSound;
use function abs;
use function max;

final class SurvivalBlockBreakHandler{

	public const DEFAULT_FX_INTERVAL_TICKS = 5;

	private int $fxTicker = 0;
	private float $breakSpeed;
	private float $breakProgress = 0;

	private float $progress = 0;

	public function __construct(
		private Player $player,
		private Vector3 $blockPos,
		private Block $block,
		private int $targetedFace,
		private int $maxPlayerDistance,
		private int $fxTickInterval = self::DEFAULT_FX_INTERVAL_TICKS
	){
		$this->breakSpeed = $this->calculateBreakProgressPerTick();
		if($this->breakSpeed > 0){
			$this->player->getWorld()->broadcastPacketToViewers(
				$this->blockPos,
				LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int) (65535 * $this->breakSpeed), $this->blockPos)
			);
		}
	}

	/**
	 * @return float
	 */
	private function calculateBreakProgressPerTick() : float{
		if($this->block->getBreakInfo()->breaksInstantly()){
			return 1.0;
		}
		if(!$this->block->getBreakInfo()->isBreakable()){
			return 0.0;
		}
		$breakTimePerTick = $this->block->getBreakInfo()->getBreakTime($this->player->getInventory()->getItemInHand()) * 20;
		if(!$this->player->isOnGround() && !$this->player->isFlying()){
			$breakTimePerTick *= 5;
		}
		if($this->player->isUnderwater() && !$this->player->getArmorInventory()->getHelmet()->hasEnchantment(VanillaEnchantments::AQUA_AFFINITY())){
			$breakTimePerTick *= 5;
		}
		if($breakTimePerTick > 0){
			$progressPerTick = 1 / $breakTimePerTick;

			$haste = $this->player->getEffects()->get(VanillaEffects::HASTE());
			if($haste !== null){
				$hasteLevel = $haste->getEffectLevel();
				$progressPerTick *= (1 + 0.2 * $hasteLevel) * (1.2 ** $hasteLevel);
			}

			$miningFatigue = $this->player->getEffects()->get(VanillaEffects::MINING_FATIGUE());
			if($miningFatigue !== null){
				$miningFatigueLevel = $miningFatigue->getEffectLevel();
				$progressPerTick *= 0.21 ** $miningFatigueLevel;
			}

			return $progressPerTick;
		}
		return 1;
	}

	/**
	 * @return bool
	 */
	public function update() : bool{
		if($this->player->getPosition()->distanceSquared($this->blockPos->add(0.5, 0.5, 0.5)) > $this->maxPlayerDistance ** 2){
			return false;
		}

		$newBreakSpeed = $this->calculateBreakProgressPerTick();
		if(abs($newBreakSpeed - $this->breakSpeed) > 0.0001){
			$this->breakSpeed = $newBreakSpeed;
			$this->player->getWorld()->broadcastPacketToViewers(
				$this->blockPos,
				LevelEventPacket::create(LevelEvent::BLOCK_BREAK_SPEED, (int) (65535 * $this->breakSpeed), $this->blockPos)
			);
		}

		$this->breakProgress += $this->breakSpeed;

		if(($this->fxTicker++ % $this->fxTickInterval) === 0 && $this->breakProgress < 1){
			$this->player->getWorld()->addParticle($this->blockPos, new BlockPunchParticle($this->block, $this->targetedFace));
			$this->player->getWorld()->addSound($this->blockPos, new BlockPunchSound($this->block));
			$this->player->broadcastAnimation(new ArmSwingAnimation($this->player), $this->player->getViewers());
		}

		$this->addTick($this->calculateDestroyRate());

		return $this->breakProgress < 1;
	}

	/**
	 * @return Vector3
	 */
	public function getBlockPos() : Vector3{
		return $this->blockPos;
	}

	/**
	 * @return int
	 */
	public function getTargetedFace() : int{
		return $this->targetedFace;
	}

	/**
	 * @param int $face
	 *
	 * @return void
	 */
	public function setTargetedFace(int $face) : void{
		Facing::validate($face);
		$this->targetedFace = $face;
	}

	/**
	 * @return float
	 */
	public function getBreakSpeed() : float{
		return $this->progress;
	}

	/**
	 * @return float
	 */
	public function getBreakProgress() : float{
		return $this->progress;
	}

	/**
	 * @return void
	 */
	public function __destruct(){
		if($this->player->getWorld()->isInLoadedTerrain($this->blockPos)){
			$this->player->getWorld()->broadcastPacketToViewers(
				$this->blockPos,
				LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $this->blockPos)
			);
		}
	}

	/**
	 * @param float $tick
	 *
	 * @return float
	 */
	public function addTick(float $tick = 1.0) : float{
		return $this->progress += $tick;
	}

	/**
	 * @return float
	 */
	private function calculateDestroyRate() : float{
		$hardness = $this->block->getBreakInfo()->getHardness();
		if($hardness <= 0.0){
			return 1.0;
		}

		$item = $this->player->getInventory()->getItemInHand();
		$speed = $this->calculateDestroySpeed($item);

		$breakInfo = $this->block->getBreakInfo();
		$isCompatible = $breakInfo->isToolCompatible(VanillaItems::AIR()) || $breakInfo->isToolCompatible($item);

		return ($speed / $hardness) * ($isCompatible ? 0.033333335 : 0.0099999998);
	}

	/**
	 * @param Item $item
	 *
	 * @return float
	 */
	private function calculateDestroySpeed(Item $item) : float{
		$breakInfo = $this->block->getBreakInfo();
		$isCompatible = ($breakInfo->getToolType() & $item->getBlockToolType()) !== 0;
		$speed = $item->getMiningEfficiency($isCompatible);

		$effectManager = $this->player->getEffects();
		$haste = $effectManager->get(VanillaEffects::HASTE());
		$conduitPower = $effectManager->get(VanillaEffects::CONDUIT_POWER());
		$miningFatigue = $effectManager->get(VanillaEffects::MINING_FATIGUE());

		$hasteLevel = 0;
		if($haste !== null){
			$hasteLevel = $haste->getEffectLevel();
		}
		if($conduitPower !== null){
			$hasteLevel = max($hasteLevel, $conduitPower->getEffectLevel());
		}

		if($hasteLevel > 0){
			$speed *= 1 + ($hasteLevel * 0.2);
		}

		if($miningFatigue !== null){
			$speed *= 0.3 ** $miningFatigue->getEffectLevel();
		}

		if(!$this->player->isOnGround() && !$this->player->getAllowFlight()){
			$speed *= 0.2;
		}

		if($this->player->isUnderwater()){
			$hasAquaAffinity = $this->player->getArmorInventory()->getHelmet()->hasEnchantment(VanillaEnchantments::AQUA_AFFINITY());
			if(!$hasAquaAffinity){
				$speed *= 0.2;
			}
		}

		return $speed;
	}
}