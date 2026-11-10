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
 *
 * Overflowing shapes follow ext-qmath 0.3.1 rather than the old code: a box that leaves its own cell is seen from
 * every cell it reaches, diagonal ones included, by movement and by rays alike. The old code only looked at the six
 * face neighbours for movement and never for rays, so an arrow flew through the top half of a fence.
 */
final class LegacyCollision{
	/** @var array<int, array<int, array{int, list<AxisAlignedBB>}>> the per-cell cache World kept, for benchmarks */
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

	/**
	 * @return array{int, list<AxisAlignedBB>} collision info and the block's own boxes
	 */
	private function cell(int $x, int $y, int $z) : array{
		$info = $this->info($x, $y, $z);
		$boxes = match($info){
			RuntimeBlockStateRegistry::COLLISION_NONE => [],
			RuntimeBlockStateRegistry::COLLISION_CUBE => [AxisAlignedBB::one()->offset($x, $y, $z)],
			default => $this->world->getBlockAt($x, $y, $z)->getCollisionBoxes()
		};
		return [$info, $boxes];
	}

	/** @return list<AxisAlignedBB> */
	public function blockCollisionBoxes(AxisAlignedBB $bb) : array{
		$minX = (int) floor($bb->minX);
		$minY = (int) floor($bb->minY);
		$minZ = (int) floor($bb->minZ);
		$maxX = (int) floor($bb->maxX);
		$maxY = (int) floor($bb->maxY);
		$maxZ = (int) floor($bb->maxZ);
		$collides = [];
		for($z = $minZ - 1; $z <= $maxZ + 1; ++$z){
			for($x = $minX - 1; $x <= $maxX + 1; ++$x){
				for($y = $minY - 1; $y <= $maxY + 1; ++$y){
					[$info, $boxes] = $this->cached ?
						($this->cache[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)] ??= $this->cell($x, $y, $z)) :
						$this->cell($x, $y, $z);
					//the ring around the box only matters where a shape overflows into it
					$covered = $x >= $minX && $x <= $maxX && $y >= $minY && $y <= $maxY && $z >= $minZ && $z <= $maxZ;
					if(!$covered && $info !== RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW){
						continue;
					}
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
			$hit = $this->voxelIntercept((int) $vector3->x, (int) $vector3->y, (int) $vector3->z, $start, $end);
			if($hit !== null){
				return [$this->world->getBlockAt($hit[0], $hit[1], $hit[2]), $hit[3]];
			}
		}
		return null;
	}

	/**
	 * Nearest intercept lying inside one voxel, against its own boxes and those overflowing into it from the cells
	 * around. Walking the voxels in order then gives the nearest hit overall.
	 *
	 * @return array{int, int, int, RayTraceResult}|null the block owning the box that was hit
	 */
	private function voxelIntercept(int $x, int $y, int $z, Vector3 $start, Vector3 $end) : ?array{
		$best = null;
		$bestDistance = PHP_INT_MAX;
		$cells = [[$x, $y, $z]];
		for($ox = $x - 1; $ox <= $x + 1; ++$ox){
			for($oz = $z - 1; $oz <= $z + 1; ++$oz){
				for($oy = $y - 1; $oy <= $y + 1; ++$oy){
					if(($ox !== $x || $oy !== $y || $oz !== $z) && $this->info($ox, $oy, $oz) === RuntimeBlockStateRegistry::COLLISION_MAY_OVERFLOW){
						$cells[] = [$ox, $oy, $oz];
					}
				}
			}
		}
		foreach($cells as [$ox, $oy, $oz]){
			foreach($this->cell($ox, $oy, $oz)[1] as $bb){
				$hit = $bb->calculateIntercept($start, $end);
				if($hit === null){
					continue;
				}
				$v = $hit->hitVector;
				if($v->x < $x - 1e-6 || $v->x > $x + 1 + 1e-6 || $v->y < $y - 1e-6 || $v->y > $y + 1 + 1e-6 || $v->z < $z - 1e-6 || $v->z > $z + 1 + 1e-6){
					continue;
				}
				$distance = $v->distanceSquared($start);
				if($distance < $bestDistance){
					$best = [$ox, $oy, $oz, $hit];
					$bestDistance = $distance;
				}
			}
		}
		return $best;
	}

	/**
	 * Projectile::move() entity pass.
	 * @param AxisAlignedBB[] $boxes
	 * @phpstan-param array<int, AxisAlignedBB> $boxes
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
						if($this->voxelIntercept((int) $pos->x, (int) $pos->y, (int) $pos->z, $origin, $point) !== null){
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
										$posHash = World::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z);
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
