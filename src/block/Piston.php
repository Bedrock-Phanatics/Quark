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

namespace quark\block;

use quark\block\tile\piston\PistonArm;
use quark\block\tile\piston\PistonMovement;

use quark\block\utils\AnyFacing;
use quark\block\utils\redstone\Movable;
use quark\block\utils\redstone\Powerable;
use quark\block\utils\redstone\PowerableTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\block\utils\redstone\RedstoneBlockUtils;
use quark\data\runtime\RuntimeDataDescriber;
use quark\event\block\BlockTeleportEvent;
use quark\event\block\PistonPullBlockEvent;
use quark\event\block\PistonPushBlockEvent;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\timings\Timings;
use quark\timings\TimingsHandler;
use quark\world\BlockTransaction;
use quark\world\redstone\RedstoneConfig;
use quark\world\redstone\RedstoneManager;
use quark\world\redstone\RedstoneWorldState;
use quark\world\sound\PistonInSound;
use quark\world\sound\PistonOutSound;
use quark\world\World;
use RuntimeException;
use function abs;
use function assert;
use function count;

class Piston extends Transparent implements AnyFacing, Powerable, Movable{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;

	public const int PUSH_DISTANCE = 12;

	public const int STATE_CONTRACT_IDLE = 0;
	public const int STATE_CONTRACT_BEGIN = 1;
	public const int STATE_RETRACT_BEGIN = 2;
	public const int STATE_RETRACT_WAITING = 3;
	public const int STATE_RETRACT_IDLE = 4;

	protected int $facing = Facing::NORTH;

	/** @var self::STATE_* */
	protected int $state = self::STATE_RETRACT_IDLE;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	public function __construct(
		BlockIdentifier $idInfo,
		string $name,
		BlockTypeInfo $typeInfo,
		readonly protected bool $sticky
	){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function readStateFromWorld() : Block{
		$result = parent::readStateFromWorld();
		if($result !== $this){
			return $result;
		}

		$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		if($tile instanceof PistonArm){
			$this->state = $tile->state;
			if($this->state === self::STATE_RETRACT_WAITING){
				$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, RedstoneWorldState::redstoneTicks(1));
			}
		}
		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		assert($tile instanceof PistonArm);
		$tile->state = $this->state;
		$tile->sticky = $this->sticky;
		$tile->clearSpawnCompoundCache();
		$this->executeState();
	}

	public function isSticky() : bool{
		return $this->sticky;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	public function setFacing(int $facing) : self{
		$this->facing = $facing;
		return $this;
	}

	/**
	 * @return self::STATE_*
	 */
	public function getState() : int{
		return $this->state;
	}

	/**
	 * @param self::STATE_* $state
	 */
	public function setState(int $state) : self{
		$this->state = $state;
		return $this;
	}

	private function executeState() : void{
		$initial_state = $this->state;
		do{
			$old_state = $this->state;
			switch($this->state){
				case self::STATE_CONTRACT_BEGIN:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmCollision){
						if($block->getFacing() !== $this->facing){
							$this->setState(self::STATE_RETRACT_BEGIN);
						}else{
							$this->setState(self::STATE_CONTRACT_IDLE);
						}
					}elseif($this->pushBlocks()){
						$this->setState(self::STATE_CONTRACT_IDLE);
						$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new PistonOutSound());
					}else{
						$this->setState(self::STATE_RETRACT_IDLE);
					}
					break;
				case self::STATE_CONTRACT_IDLE:
					$block = $this->getSide($this->getSideFacing());
					if(!($block instanceof PistonArmCollision) || $block->getFacing() !== $this->facing){
						$block->position->getWorld()->setBlockAt((int) $block->position->x, (int) $block->position->y, (int) $block->position->z, VanillaBlocks::PISTON_ARM_COLLISION()->setFacing($this->facing), false);
					}
					break;
				case self::STATE_RETRACT_BEGIN:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmCollision && $block->getFacing() === $this->facing){
						$this->setState(self::STATE_RETRACT_WAITING);
					}else{
						$this->setState(self::STATE_RETRACT_IDLE);
					}
					break;
				case self::STATE_RETRACT_WAITING:
					$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, RedstoneWorldState::redstoneTicks(1));
					break;
				case self::STATE_RETRACT_IDLE:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmCollision && $block->getFacing() === $this->facing){
						$block->position->getWorld()->setBlockAt((int) $block->position->x, (int) $block->position->y, (int) $block->position->z, VanillaBlocks::AIR());
					}
					break;
				default:
					throw new RuntimeException("Unexpected state: {$this->state}");
			}
		}while($old_state !== $this->state);
		if($this->state !== $initial_state){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this, false);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;

				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = $player->getHorizontalFacing();
				}
			}else{
				$this->facing = $player->getHorizontalFacing();
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->state !== self::STATE_RETRACT_IDLE){
			$this->setState(self::STATE_RETRACT_IDLE);
			$this->executeState();
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	private function canBlockBeBroken(Block $block) : bool{
		return $block->isTransparent() && !$block->isSolid();
	}

	private function canBlockBeMoved(Block $block, Vector3 $future_pos) : bool{
		if(!$this->isPositionLoaded($block->position) || !$this->isPositionLoaded($future_pos)){ return false; }
		if($this->canBlockBeBroken($block) || $block->canBeFlowedInto() || !$block->getBreakInfo()->isBreakable()){
			return false;
		}
		if($block instanceof Movable ? !$block->canBeMoved() : ($block->position->getWorld()->getTileAt((int) $block->position->x, (int) $block->position->y, (int) $block->position->z) !== null)){
			return false;
		}
		if(!BlockTeleportEvent::hasHandlers()){
			return true;
		}
		($ev = new BlockTeleportEvent(clone $block, clone $future_pos))->call();
		return !$ev->isCancelled();
	}

	private function pullBlocks() : bool{
		if(!$this->beginMovement()){ return false; }
		try{
			return $this->pullBlocksInternal();
		}finally{
			$this->endMovement();
		}
	}

	private function pullBlocksInternal() : bool{
		$facing = $this->getSideFacing();
		$block = $this->getSide($facing, 2);
		$future_pos = $this->position->getSide($facing);
		if(!$this->isPositionLoaded($block->position) || !$this->isPositionLoaded($future_pos)){ return false; }
		$future_block = $this->position->getWorld()->getBlockAt((int) $future_pos->x, (int) $future_pos->y, (int) $future_pos->z);
		if(!($future_block instanceof PistonArmCollision) && !$future_block->canBeReplaced() && !$future_block->canBeFlowedInto()){
			return false;
		}
		if(!$this->canBlockBeMoved($block, $future_pos)){
			return false;
		}
		if(PistonPullBlockEvent::hasHandlers()){
			$ev = new PistonPullBlockEvent($this, clone $block);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
		}
		$this->position->getWorld()->setBlockAt((int) $future_pos->x, (int) $future_pos->y, (int) $future_pos->z, VanillaBlocks::AIR(), false);
		RedstoneBlockUtils::moveBlockAndTile($this->position->getWorld(), $block->position, $future_pos);
		return true;
	}

	private function pushBlocks() : bool{
		if(!$this->beginMovement()){ return false; }
		try{
			return $this->pushBlocksInternal();
		}finally{
			$this->endMovement();
		}
	}

	private function pushBlocksInternal() : bool{
		$movements = [];
		$terminalBlock = null;
		$facing = $this->getSideFacing();
		for($i = 0; $i <= self::PUSH_DISTANCE; $i++){
			$block = $this->getSide($facing, 1 + $i);
			if($block->canBeReplaced() || $block->canBeFlowedInto()){
				$terminalBlock = $block;
				break;
			}
			if($i === self::PUSH_DISTANCE){
				return false;
			}
			$future_pos = $block->position->getSide($facing);
			if($block instanceof PistonArmCollision || !$this->canBlockBeMoved($block, $future_pos)){
				return false;
			}
			$movements[] = new PistonMovement(clone $block, clone $block->position, clone $future_pos);
		}
		$arm_pos = $this->position->getSide($facing);
		if(!$this->isPositionLoaded($arm_pos)){ return false; }
		if(PistonPushBlockEvent::hasHandlers()){
			$ev = new PistonPushBlockEvent($this, clone $arm_pos, $movements);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
		}
		if($terminalBlock !== null && !($terminalBlock instanceof Air)){
			if($terminalBlock instanceof Liquid){
				$this->position->getWorld()->setBlock($terminalBlock->position, VanillaBlocks::AIR());
			}elseif(!$this->position->getWorld()->useBreakOn($terminalBlock->position)){
				return false;
			}
		}

		$this->pushEntities(count($movements));
		foreach(RedstoneBlockUtils::reverse($movements) as $movement){
			RedstoneBlockUtils::moveBlockAndTile($this->position->getWorld(), $movement->from, $movement->to);
		}
		return true;
	}

	private function pushEntities(int $distance) : void{
		$facing = $this->getSideFacing();
		$facing_offset = Vector3::zero()->getSide($facing, $distance);
		$arm_pos = $this->position->getSide($facing);
		$collisionBox = AxisAlignedBB::one()
			->offset($arm_pos->x, $arm_pos->y, $arm_pos->z)
			->offset($facing_offset->x, $facing_offset->y, $facing_offset->z);
		foreach($this->position->getWorld()->getNearbyEntities($collisionBox) as $entity){
			if($entity->isFlaggedForDespawn() || $entity->isClosed()){
				continue;
			}
			$entityBox = $entity->getBoundingBox();
			[$moveX, $moveY, $moveZ] = match($facing){
				Facing::EAST => [$collisionBox->maxX - $entityBox->minX + 0.01, 0.0, 0.0],
				Facing::WEST => [$collisionBox->minX - $entityBox->maxX - 0.01, 0.0, 0.0],
				Facing::UP => [0.0, $collisionBox->maxY - $entityBox->minY + 0.01, 0.0],
				Facing::DOWN => [0.0, $collisionBox->minY - $entityBox->maxY - 0.01, 0.0],
				Facing::SOUTH => [0.0, 0.0, $collisionBox->maxZ - $entityBox->minZ + 0.01],
				Facing::NORTH => [0.0, 0.0, $collisionBox->minZ - $entityBox->maxZ - 0.01],
				default => throw new RuntimeException("Unexpected facing: {$facing}")
			};
			$entity->moveByPiston($moveX, $moveY, $moveZ);
		}
	}

	private function isPositionLoaded(Vector3 $position) : bool{
		$world = $this->position->getWorld();
		$x = (int) $position->x;
		$y = (int) $position->y;
		$z = (int) $position->z;
		return $world->isInWorld($x, $y, $z) && $world->isChunkLoaded($x >> 4, $z >> 4);
	}

	/** @var array<string, true> */
	private static array $activeMovements = [];

	private function beginMovement() : bool{
		$key = $this->position->getWorld()->getId() . ":" . World::blockHash((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		if(isset(self::$activeMovements[$key])){ return false; }
		self::$activeMovements[$key] = true;
		return true;
	}

	private function endMovement() : void{
		$key = $this->position->getWorld()->getId() . ":" . World::blockHash((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		unset(self::$activeMovements[$key]);
	}

	public function getSideFacing() : int{
		return $this->facing >= 2 ? Facing::opposite($this->facing) : $this->facing;
	}

	public function isPowered() : bool{
		return $this->state !== self::STATE_RETRACT_IDLE;
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return $side !== $this->getSideFacing();
	}

	protected function onReceivePower(int $power) : void{
		if(!TimingsHandler::isEnabled()){
			$this->handleReceivedPower($power);
			return;
		}
		Timings::$redstonePistons->time(fn() => $this->handleReceivedPower($power));
	}

	private function handleReceivedPower(int $power) : void{
		$manager = RedstoneManager::getInstance();
		if(!$manager->isEnabledAt($this->position) || !RedstoneConfig::arePistonsEnabled() || !$manager->get($this->position->getWorld())->tryPistonAction()){ return; }
		if($power > 0){
			if($this->state !== self::STATE_CONTRACT_BEGIN && $this->state !== self::STATE_CONTRACT_IDLE && $this->state !== self::STATE_RETRACT_BEGIN){
				$next_state = self::STATE_CONTRACT_BEGIN;
			}else{
				$next_state = null;
			}
		}else{
			if($this->state !== self::STATE_RETRACT_BEGIN && /*$this->state !== self::STATE_RETRACT_WAITING && */$this->state !== self::STATE_RETRACT_IDLE && $this->state !== self::STATE_CONTRACT_BEGIN){
				$next_state = self::STATE_RETRACT_BEGIN;
			}else{
				$next_state = null;
			}
		}
		if($next_state !== null){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setState($next_state), false);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->state === self::STATE_RETRACT_WAITING){
			if($this->sticky){
				$this->pullBlocks();
			}
			$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new PistonInSound());
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setState(self::STATE_RETRACT_IDLE), false);
		}
	}

	public function canBeMoved() : bool{
		return $this->state === self::STATE_RETRACT_IDLE;
	}
}
