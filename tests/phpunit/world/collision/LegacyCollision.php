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

namespace quark\world\collision;

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\RayTraceResult;
use quark\math\Vector3;
use quark\math\VoxelRayTrace;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use quark\world\utils\SubChunkExplorer;
use quark\world\utils\SubChunkExplorerStatus;
use quark\world\World;
use function array_keys;
use function floor;
use function mt_rand;
use function sqrt;
use const PHP_INT_MAX;

/**
 * The PHP collision, raycast and explosion code Quark used before ext-qmath, without its caches, kept as the
 * reference the native paths are checked against.
 */
final class LegacyCollision{
	/** @var array<int, array<int, list<AxisAlignedBB>>> the per-cell cache World kept, for benchmarks */
	private array $cache = [];

	public function __construct(
		private World $world,
		private bool $cached = false
	){}

	/** Drops a changed block and its neighbours from the cache, like World::setBlockAt() did. */
	public function forget(int $x, int $y, int $z) : void{
		unset($this->cache[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)]);
		foreach(Facing::OFFSET as [$dx, $dy, $dz]){
			unset($this->cache[World::chunkHash(($x + $dx) >> Chunk::COORD_BIT_SIZE, ($z + $dz) >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x + $dx, $y + $dy, $z + $dz)]);
		}
	}

	private function info(int $x, int $y, int $z) : int{
		if(!$this->world->isInWorld($x, $y, $z)){
			return RuntimeBlockStateRegistry::COLLISION_NONE;
		}
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return RuntimeBlockStateRegistry::COLLISION_NONE;
		}
		$stateId = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		return RuntimeBlockStateRegistry::getInstance()->collisionInfo[$stateId];
	}

	/** @return list<AxisAlignedBB> */
	private function cellBoxes(int $x, int $y, int $z) : array{
		$info = $this->info($x, $y, $z);
		$boxes = match($info){
			RuntimeBlockStateRegistry::COLLISION_NONE => [],
			RuntimeBlockStateRegistry::COLLISION_CUBE => [AxisAlignedBB::one()->offset($x, $y, $z)],
			default => $this->world->getBlockAt($x, $y, $z)->getCollisionBoxes()
		};
		if($info !== RuntimeBlockStateRegistry::COLLISION_CUBE){
			$cellBB = AxisAlignedBB::one()->offset($x, $y, $z);
			foreach(Facing::OFFSET as [$dx, $dy, $dz]){
				if($this->info($x + $dx, $y + $dy, $z + $dz) === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW){
					foreach($this->world->getBlockAt($x + $dx, $y + $dy, $z + $dz)->getCollisionBoxes() as $extraBox){
						if($extraBox->intersectsWith($cellBB)){
							$boxes[] = $extraBox;
						}
					}
				}
			}
		}
		return $boxes;
	}

	/** @return list<AxisAlignedBB> */
	public function blockCollisionBoxes(AxisAlignedBB $bb) : array{
		$collides = [];
		for($z = (int) floor($bb->minZ); $z <= (int) floor($bb->maxZ); ++$z){
			for($x = (int) floor($bb->minX); $x <= (int) floor($bb->maxX); ++$x){
				for($y = (int) floor($bb->minY); $y <= (int) floor($bb->maxY); ++$y){
					$boxes = $this->cached ?
						($this->cache[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)] ??= $this->cellBoxes($x, $y, $z)) :
						$this->cellBoxes($x, $y, $z);
					foreach($boxes as $blockBB){
						if($blockBB->intersectsWith($bb)){
							$collides[] = $blockBB;
						}
					}
				}
			}
		}
		return $collides;
	}

	/**
	 * Entity::move() collision without keepMovement.
	 * @return array{float, float, float, AxisAlignedBB, bool}
	 */
	public function move(AxisAlignedBB $boundingBox, float $dx, float $dy, float $dz, float $stepHeight, bool $onGround) : array{
		$wantedX = $dx;
		$wantedY = $dy;
		$wantedZ = $dz;
		$stepped = false;
		$moveBB = clone $boundingBox;
		$list = $this->blockCollisionBoxes($moveBB->addCoord($dx, $dy, $dz));
		foreach($list as $bb){
			$dy = $bb->calculateYOffset($moveBB, $dy);
		}
		$moveBB->offset(0, $dy, 0);
		$fallingFlag = ($onGround || ($dy !== $wantedY && $wantedY < 0));
		foreach($list as $bb){
			$dx = $bb->calculateXOffset($moveBB, $dx);
		}
		$moveBB->offset($dx, 0, 0);
		foreach($list as $bb){
			$dz = $bb->calculateZOffset($moveBB, $dz);
		}
		$moveBB->offset(0, 0, $dz);

		if($stepHeight > 0 && $fallingFlag && ($wantedX !== $dx || $wantedZ !== $dz)){
			$cx = $dx;
			$cy = $dy;
			$cz = $dz;
			$dx = $wantedX;
			$dy = $stepHeight;
			$dz = $wantedZ;
			$stepBB = clone $boundingBox;
			$list = $this->blockCollisionBoxes($stepBB->addCoord($dx, $dy, $dz));
			foreach($list as $bb){
				$dy = $bb->calculateYOffset($stepBB, $dy);
			}
			$stepBB->offset(0, $dy, 0);
			foreach($list as $bb){
				$dx = $bb->calculateXOffset($stepBB, $dx);
			}
			$stepBB->offset($dx, 0, 0);
			foreach($list as $bb){
				$dz = $bb->calculateZOffset($stepBB, $dz);
			}
			$stepBB->offset(0, 0, $dz);
			$reverseDY = -$dy;
			foreach($list as $bb){
				$reverseDY = $bb->calculateYOffset($stepBB, $reverseDY);
			}
			$dy += $reverseDY;
			$stepBB->offset(0, $reverseDY, 0);

			if(($cx ** 2 + $cz ** 2) >= ($dx ** 2 + $dz ** 2)){
				$dx = $cx;
				$dy = $cy;
				$dz = $cz;
			}else{
				$moveBB = $stepBB;
				$stepped = true;
			}
		}
		return [$dx, $dy, $dz, $moveBB, $stepped];
	}

	/**
	 * Projectile::move() block pass.
	 * @return array{Block, RayTraceResult}|null
	 */
	public function projectileBlocks(Vector3 $start, Vector3 $end) : ?array{
		foreach(VoxelRayTrace::betweenPoints($start, $end) as $vector3){
			$block = $this->world->getBlockAt((int) $vector3->x, (int) $vector3->y, (int) $vector3->z);
			$hit = $block->calculateIntercept($start, $end);
			if($hit !== null){
				return [$block, $hit];
			}
		}
		return null;
	}

	/**
	 * Projectile::move() entity pass.
	 * @param AxisAlignedBB[] $boxes
	 * @return array{int, RayTraceResult}|null
	 */
	public function projectileEntities(Vector3 $start, Vector3 $end, array $boxes) : ?array{
		$entityDistance = PHP_INT_MAX;
		$hitResult = null;
		foreach($boxes as $key => $box){
			$entityHitResult = $box->expandedCopy(0.3, 0.3, 0.3)->calculateIntercept($start, $end);
			if($entityHitResult === null){
				continue;
			}
			$distance = $start->distanceSquared($entityHitResult->hitVector);
			if($distance < $entityDistance){
				$entityDistance = $distance;
				$hitResult = [$key, $entityHitResult];
				$end = $entityHitResult->hitVector;
			}
		}
		return $hitResult;
	}

	public function exposure(Vector3 $origin, AxisAlignedBB $bb) : float{
		$diff = (new Vector3($bb->getXLength(), $bb->getYLength(), $bb->getZLength()))->multiply(2)->add(1, 1, 1);
		$step = new Vector3(1.0 / $diff->x, 1.0 / $diff->y, 1.0 / $diff->z);
		$xOffset = (1.0 - (floor($diff->x) / $diff->x)) / 2.0;
		$zOffset = (1.0 - (floor($diff->z) / $diff->z)) / 2.0;
		$checks = 0.0;
		$hits = 0.0;
		for($x = 0.0; $x <= 1.0; $x += $step->x){
			for($y = 0.0; $y <= 1.0; $y += $step->y){
				for($z = 0.0; $z <= 1.0; $z += $step->z){
					$point = new Vector3(
						$bb->minX + $x * ($bb->maxX - $bb->minX) + $xOffset,
						$bb->minY + $y * ($bb->maxY - $bb->minY),
						$bb->minZ + $z * ($bb->maxZ - $bb->minZ) + $zOffset
					);
					$intercepted = false;
					foreach(VoxelRayTrace::betweenPoints($origin, $point) as $pos){
						if($this->world->getBlock($pos)->calculateIntercept($origin, $point) !== null){
							$intercepted = true;
							break;
						}
					}
					if(!$intercepted){
						$hits++;
					}
					$checks++;
				}
			}
		}
		return $checks > 0.0 ? $hits / $checks : 0.0;
	}

	/**
	 * Explosion::explodeA() ray march with the default 16 rays.
	 * @return array{list<int>, list<int>} affected and ignited block hashes in insertion order
	 */
	public function explodeA(Vector3 $source, float $radius, float $fireChance, float $stepLen = 0.3) : array{
		$blockFactory = RuntimeBlockStateRegistry::getInstance();
		$explorer = new SubChunkExplorer($this->world);
		$affected = [];
		$ignitions = [];
		$rays = 16;
		$mRays = $rays - 1;
		$incendiary = $fireChance > 0;
		for($i = 0; $i < $rays; ++$i){
			for($j = 0; $j < $rays; ++$j){
				for($k = 0; $k < $rays; ++$k){
					if($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays){
						[$shiftX, $shiftY, $shiftZ] = [$i / $mRays * 2 - 1, $j / $mRays * 2 - 1, $k / $mRays * 2 - 1];
						$len = sqrt($shiftX ** 2 + $shiftY ** 2 + $shiftZ ** 2);
						[$shiftX, $shiftY, $shiftZ] = [($shiftX / $len) * $stepLen, ($shiftY / $len) * $stepLen, ($shiftZ / $len) * $stepLen];
						$pointerX = $source->x;
						$pointerY = $source->y;
						$pointerZ = $source->z;
						for($blastForce = $radius * (mt_rand(700, 1300) / 1000); $blastForce > 0; $blastForce -= $stepLen * 0.75){
							$x = (int) $pointerX;
							$y = (int) $pointerY;
							$z = (int) $pointerZ;
							$vBlockX = $pointerX >= $x ? $x : $x - 1;
							$vBlockY = $pointerY >= $y ? $y : $y - 1;
							$vBlockZ = $pointerZ >= $z ? $z : $z - 1;
							$pointerX += $shiftX;
							$pointerY += $shiftY;
							$pointerZ += $shiftZ;
							if($explorer->moveTo($vBlockX, $vBlockY, $vBlockZ) === SubChunkExplorerStatus::INVALID){
								continue;
							}
							$subChunk = $explorer->currentSubChunk ?? throw new \AssertionError("No subchunk");
							$state = $subChunk->getBlockStateId($vBlockX & SubChunk::COORD_MASK, $vBlockY & SubChunk::COORD_MASK, $vBlockZ & SubChunk::COORD_MASK);
							$blastResistance = $blockFactory->blastResistance[$state] ?? 0;
							if($blastResistance >= 0){
								$blastForce -= ($blastResistance / 5 + 0.3) * $stepLen;
								if($blastForce > 0 && !isset($affected[World::blockHash($vBlockX, $vBlockY, $vBlockZ)])){
									foreach($this->world->getBlockAt($vBlockX, $vBlockY, $vBlockZ, true, false)->getAffectedBlocks() as $affectedBlock){
										$pos = $affectedBlock->getPosition();
										$posHash = World::blockHash($pos->x, $pos->y, $pos->z);
										$affected[$posHash] = true;
										if($incendiary && Utils::getRandomFloat() <= $fireChance){
											$ignitions[$posHash] = true;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		return [array_keys($affected), array_keys($ignitions)];
	}
}
