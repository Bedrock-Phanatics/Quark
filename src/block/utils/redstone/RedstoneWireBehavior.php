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

namespace quark\block\utils\redstone;

use Generator;
use quark\block\Block;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\timings\Timings;
use quark\timings\TimingsHandler;
use quark\world\BlockTransaction;
use quark\world\redstone\RedstoneConfig;
use quark\world\redstone\RedstoneManager;
use quark\world\World;
use function array_fill;
use function assert;
use function count;
use function max;

trait RedstoneWireBehavior{
	use RedstoneBlockAccessTrait;

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		return RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN)->isSolid() && parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		if(!RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN)->isSolid()){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$diff = $this->recalculateBestPower() - $this->signalStrength;
		if($diff !== 0){
			$this->updatePowerLevels([$this]);
		}else{
			foreach($this->getRelyingHorizontalBlocks() as $block){
				if(!($block instanceof self)){
					$block->power($this);
				}
			}
		}
		parent::onNearbyBlockChange();
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->signalStrength = 0;
		if(parent::onBreak($item, $player, $returnedItems)){
			foreach($this->getRelyingBlocks() as $block){
				$block->power($this);
			}
			return true;
		}

		return false;
	}

	public function getPowerLevel() : int{
		return $this->signalStrength;
	}

	public function getOutputPowerLevel() : int{
		return max($this->signalStrength - 1, 0);
	}

	public function canStronglyPower(int $side) : bool{
		$manager = RedstoneManager::getInstance();
		if($side === Facing::DOWN){
			return true;
		}

		if($side === Facing::UP){
			return false;
		}

		$above_transparent = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP)->isTransparent();
		foreach($side === Facing::NORTH || $side === Facing::SOUTH ? [Facing::WEST, Facing::EAST] : [Facing::NORTH, Facing::SOUTH] as $side2){
			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side2);
			if($block instanceof PowerSource && $manager->isEnabledAt($block->position)){
				return false;
			}

			if($above_transparent){
				$up = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::UP);
				if($up instanceof self && $manager->isEnabledAt($up->position)){
					return false;
				}
			}

			if($block->isTransparent()){
				$down = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::DOWN);
				if($down instanceof self && $manager->isEnabledAt($down->position)){
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Resolves the complete connected wire component once. Power is propagated from
	 * external sources through 16 strength buckets, avoiding convergence rescans.
	 */
	/** @param list<Block> $seeds */
	private function updatePowerLevels(array $seeds) : void{
		if(!TimingsHandler::isEnabled()){
			$this->updatePowerLevelsInternal($seeds);
			return;
		}
		Timings::$redstoneWireNetworks->time(fn() => $this->updatePowerLevelsInternal($seeds));
	}

	/** @param list<Block> $seeds */
	private function updatePowerLevelsInternal(array $seeds) : void{
		$queue = [];
		$manager = RedstoneManager::getInstance();
		foreach($seeds as $seed){
			if($seed instanceof self && $manager->isEnabledAt($seed->position)){
				$queue[] = $seed;
			}
		}
		$queueIndex = 0;
		$wires = [];
		$adjacency = [];
		$others = [];
		$limit = RedstoneConfig::getMaxWireNetworkSize();

		while(isset($queue[$queueIndex])){
			$wire = $queue[$queueIndex++];
			$hash = World::blockHash((int) $wire->position->x, (int) $wire->position->y, (int) $wire->position->z);
			if(isset($wires[$hash])){
				continue;
			}
			if(count($wires) >= $limit){
				return;
			}
			$wires[$hash] = $wire;
			$adjacency[$hash] = [];
			foreach($wire->getRelyingBlocks() as $neighbour){
				assert($neighbour instanceof Block);
				if(!$manager->isEnabledAt($neighbour->position)){ continue; }
				$neighbourHash = World::blockHash((int) $neighbour->position->x, (int) $neighbour->position->y, (int) $neighbour->position->z);
				if($neighbour instanceof self){
					$adjacency[$hash][$neighbourHash] = true;
					if(!isset($wires[$neighbourHash])){
						$queue[] = $neighbour;
					}
				}else{
					$others[$neighbourHash] = $neighbour;
				}
			}
		}

		$levels = [];
		$buckets = array_fill(0, 16, []);
		foreach($wires as $hash => $wire){
			$level = $wire->recalculateBestPower(false);
			$levels[$hash] = $level;
			if($level > 0){
				$buckets[$level][] = $hash;
			}
		}
		for($level = 15; $level > 1; --$level){
			foreach($buckets[$level] as $hash){
				if($levels[$hash] !== $level){
					continue;
				}
				$nextLevel = $level - 1;
				foreach($adjacency[$hash] as $neighbourHash => $_){
					if(isset($levels[$neighbourHash]) && $levels[$neighbourHash] < $nextLevel){
						$levels[$neighbourHash] = $nextLevel;
						$buckets[$nextLevel][] = $neighbourHash;
					}
				}
			}
		}

		foreach($wires as $hash => $wire){
			if($wire->signalStrength !== $levels[$hash]){
				$wire->signalStrength = $levels[$hash];
				$wire->position->getWorld()->setBlockAt((int) $wire->position->x, (int) $wire->position->y, (int) $wire->position->z, $wire, false);
			}
		}
		foreach($others as $block){
			$block->power($this);
		}
	}

	public function power(PowerSource $source) : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		assert($source instanceof Block);
		if($source->getOutputPowerLevel() !== $this->signalStrength){
			$seeds = [$this];
			foreach(Facing::ALL as $side){
				$block = $source->getSide($side);
				if(!$block->position->equals($this->position)){
					$seeds[] = $block;
				}
			}
			$this->updatePowerLevels($seeds);
		}
	}
	public function canPower(int $side) : bool{
		return $side !== Facing::UP && ($side === Facing::DOWN || $this->canPowerHorizontalSide($side));
	}

	protected function canPowerHorizontalSide(int $horizontal_side) : bool{
		$manager = RedstoneManager::getInstance();
		$above_transparent = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP)->isTransparent();
		$side_opposite = Facing::opposite($horizontal_side);
		foreach(Facing::HORIZONTAL as $side){
			if($side === $side_opposite){
				continue;
			}

			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($block instanceof self && $manager->isEnabledAt($block->position)){
				return false;
			}

			if($above_transparent){
				$up = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::UP);
				if($up instanceof self && $manager->isEnabledAt($up->position)){
					return false;
				}
			}

			if($block->isTransparent()){
				$down = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::DOWN);
				if($down instanceof self && $manager->isEnabledAt($down->position)){
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingBlocks() : Generator{
		$down = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN);
		if($down instanceof Transmittable){
			yield $down;
		}

		foreach(Facing::ALL as $side){
			if($side !== Facing::UP){
				$block = RedstoneBlockUtils::getBlockAtSide($down->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}

		yield from $this->getRelyingHorizontalBlocks();
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingHorizontalBlocks() : Generator{
		$manager = RedstoneManager::getInstance();
		$blocks = [];
		foreach(Facing::HORIZONTAL as $side){
			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($block instanceof Transmittable){
				yield $block;
			}
			$blocks[$side] = $block;
		}
		$above_transparent = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP)->isTransparent();
		foreach($blocks as $side => $block){
			if(!($block instanceof self) && $this->canStronglyPower($side)){
				$side_opposite = Facing::opposite($side);
				foreach(Facing::ALL as $side2){
					if($side2 === $side_opposite){
						continue;
					}

					$block2 = RedstoneBlockUtils::getBlockAtSide($block->position, $side2);
					if($block2 instanceof Transmittable){
						yield $block2;
					}
				}
			}

			if($above_transparent){
				$up = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::UP);
				if($up instanceof self && $manager->isEnabledAt($up->position)){
					yield $up;
				}
			}

			if($block->isTransparent()){
				$down = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::DOWN);
				if($down instanceof self && $manager->isEnabledAt($down->position)){
					yield $down;
				}
			}
		}
	}

	protected function recalculateBestPower(bool $includeWires = true) : int{
		$manager = RedstoneManager::getInstance();
		$world = $manager->get($this->position->getWorld());
		$above = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP);
		$below = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN);
		foreach([Facing::DOWN => $above, Facing::UP => $below] as $opposite_side => $block){
			foreach($world->getStronglyPoweringSources($block, $opposite_side) as $power_source){
				if(!($power_source instanceof self)){
					return 15;
				}
			}
		}

		$best_power = 0;
		$above_transparent = $above->isTransparent();
		foreach(Facing::HORIZONTAL as $side){
			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($block instanceof PowerSource && $manager->isEnabledAt($block->position)){
				if(($includeWires && $block instanceof self) || (!($block instanceof self) && $block->canPower(Facing::opposite($side)))){
					$power = $block->getOutputPowerLevel();
					if($power > $best_power){
						$best_power = $power;
						if($best_power === 15){
							break;
						}
					}
				}
			}else{
				foreach($world->getStronglyPoweringSources($block, Facing::opposite($side)) as $power_source){
					if(!($power_source instanceof self)){
						return 15;
					}
				}
				if($above_transparent){
					$up = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::UP);
					if($includeWires && $up instanceof self && $manager->isEnabledAt($up->position)){
						$power = $up->getOutputPowerLevel();
						if($power > $best_power){
							$best_power = $power;
							if($best_power === 15){
								break;
							}
						}
					}
				}
				if($block->isTransparent()){
					$down = RedstoneBlockUtils::getBlockAtSide($block->position, Facing::DOWN);
					if($includeWires && $down instanceof self && $manager->isEnabledAt($down->position)){
						$power = $down->getOutputPowerLevel();
						if($power > $best_power){
							$best_power = $power;
							if($best_power === 15){
								break;
							}
						}
					}
				}
			}
		}
		return $best_power;
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}
