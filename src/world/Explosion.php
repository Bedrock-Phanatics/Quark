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

namespace quark\world;

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\TNT;
use quark\block\utils\redstone\PowerSource;
use quark\block\utils\SupportType;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\event\block\BlockExplodeEvent;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityExplodeEvent;
use quark\item\VanillaItems;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\physics\ExplosionMath;
use quark\math\physics\ResistanceGrid;
use quark\math\Vector3;
use quark\math\VoxelRayTrace;
use quark\utils\AssumptionFailedError;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use quark\world\particle\HugeExplodeSeedParticle;
use quark\world\sound\ExplodeSound;
use quark\world\utils\SubChunkExplorer;
use quark\world\utils\SubChunkExplorerStatus;
use function ceil;
use function count;
use function floor;
use function is_finite;
use function min;
use function mt_rand;
use function pack;
use function sqrt;
use const NAN;

class Explosion{
	public const DEFAULT_FIRE_CHANCE = 1.0 / 3.0;

	/** About radius 28, larger explosions use the PHP ray march instead of a 9 MiB grid */
	private const MAX_GRID_CELLS = 1 << 20;
	/** ExplosionMath refuses rays longer than this in total */
	private const MAX_NATIVE_STEPS = 1 << 24;

	private int $rays = 16;
	public World $world;

	/**
	 * @var Block[]
	 * @phpstan-var array<int, Block>
	 */
	public array $affectedBlocks = [];
	public float $stepLen = 0.3;
	/** @var Block[] */
	private array $fireIgnitions = [];

	public function __construct(
		public Position $source,
		public float $radius,
		private Entity|Block|null $what = null,
		private float $fireChance = 0.0
	){
		if(!$this->source->isValid()){
			throw new \InvalidArgumentException("Position does not have a valid world");
		}
		$this->world = $this->source->getWorld();
		Utils::checkFloatNotInfOrNaN("radius", $radius);
		Utils::checkFloatNotInfOrNaN("fireChance", $fireChance);
		if($fireChance < 0.0 || $fireChance > 1.0){
			throw new \InvalidArgumentException("Fire chance must be a number between 0 and 1.");
		}
		if($radius <= 0){
			throw new \InvalidArgumentException("Explosion radius must be greater than 0, got $radius");
		}
	}

	/**
	 * Calculates which blocks will be destroyed by this explosion. If explodeB() is called without calling this, no blocks
	 * will be destroyed.
	 */
	public function explodeA() : bool{
		if($this->radius < 0.1){
			return false;
		}
		if(!is_finite($this->stepLen) || $this->stepLen <= 0){
			throw new \InvalidArgumentException("Explosion step length must be positive and finite, got $this->stepLen");
		}

		$incendiary = $this->fireChance > 0;
		$grid = $this->createResistanceGrid();
		if($grid === null){
			$this->traceRays($incendiary);
		}elseif(!$incendiary){
			$cells = ExplosionMath::traceRays($grid, $this->source, $this->radius, $this->rays, $this->stepLen);
			for($n = 0, $count = count($cells); $n < $count; $n += 3){
				$this->affect($cells[$n], $cells[$n + 1], $cells[$n + 2], false);
			}
		}else{
			//one ray at a time, so the fire rolls interleave with the mt_rand() of each ray as before
			$mRays = $this->rays - 1;
			for($i = 0; $i < $this->rays; ++$i){
				for($j = 0; $j < $this->rays; ++$j){
					for($k = 0; $k < $this->rays; ++$k){
						if($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays){
							$cells = ExplosionMath::traceRay($grid, $this->source, $this->radius, $i, $j, $k, $this->rays, $this->stepLen);
							for($n = 0, $count = count($cells); $n < $count; $n += 3){
								$this->affect($cells[$n], $cells[$n + 1], $cells[$n + 2], true);
							}
						}
					}
				}
			}
		}

		return true;
	}

	/**
	 * Blast resistance of every cell a ray can reach, null when the native trace can't take this explosion.
	 */
	private function createResistanceGrid() : ?ResistanceGrid{
		if($this->radius * 1.3 / ($this->stepLen * 0.75) * ExplosionMath::rayCount($this->rays) > self::MAX_NATIVE_STEPS){
			return null;
		}
		//force drops by at least 0.75 * stepLen per step, so no ray gets further than this
		$reach = $this->radius * 1.3 / 0.75 + $this->stepLen;
		$minX = (int) floor($this->source->x - $reach) - 1;
		$minY = (int) floor($this->source->y - $reach) - 1;
		$minZ = (int) floor($this->source->z - $reach) - 1;
		$maxX = (int) floor($this->source->x + $reach) + 1;
		$maxY = (int) floor($this->source->y + $reach) + 1;
		$maxZ = (int) floor($this->source->z + $reach) + 1;
		if(($maxX - $minX + 1) * ($maxY - $minY + 1) * ($maxZ - $minZ + 1) > self::MAX_GRID_CELLS){
			return null;
		}

		$grid = new ResistanceGrid($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
		$blastResistance = RuntimeBlockStateRegistry::getInstance()->blastResistance;
		$invalid = pack("e", NAN);
		for($chunkX = $minX >> SubChunk::COORD_BIT_SIZE; $chunkX <= $maxX >> SubChunk::COORD_BIT_SIZE; ++$chunkX){
			for($chunkZ = $minZ >> SubChunk::COORD_BIT_SIZE; $chunkZ <= $maxZ >> SubChunk::COORD_BIT_SIZE; ++$chunkZ){
				$chunk = $this->world->getChunk($chunkX, $chunkZ);
				for($chunkY = $minY >> SubChunk::COORD_BIT_SIZE; $chunkY <= $maxY >> SubChunk::COORD_BIT_SIZE; ++$chunkY){
					//the same cells SubChunkExplorer reports as invalid
					if($chunk === null || $chunkY < Chunk::MIN_SUBCHUNK_INDEX || $chunkY > Chunk::MAX_SUBCHUNK_INDEX){
						$grid->setSectionPalette($chunkX, $chunkY, $chunkZ, 0, "", $invalid);
						continue;
					}
					$subChunk = $chunk->getSubChunk($chunkY);
					$layer = $subChunk->getBlockLayer0();
					if($layer === null){
						$grid->setSectionPalette($chunkX, $chunkY, $chunkZ, 0, "", pack("e", $blastResistance[$subChunk->getEmptyBlockId()] ?? 0));
						continue;
					}
					$resistances = "";
					foreach($layer->getPalette() as $stateId){
						$resistances .= pack("e", $blastResistance[$stateId] ?? 0);
					}
					$grid->setSectionPalette($chunkX, $chunkY, $chunkZ, $layer->getBitsPerBlock(), $layer->getWordArray(), $resistances);
				}
			}
		}
		return $grid;
	}

	private function affect(int $x, int $y, int $z, bool $incendiary) : void{
		if(isset($this->affectedBlocks[World::blockHash($x, $y, $z)])){
			return;
		}
		foreach($this->world->getBlockAt($x, $y, $z, true, false)->getAffectedBlocks() as $affectedBlock){
			$affectedBlockPos = $affectedBlock->getPosition();
			$posHash = World::blockHash($affectedBlockPos->x, $affectedBlockPos->y, $affectedBlockPos->z);
			$this->affectedBlocks[$posHash] = $affectedBlock;

			if($incendiary && Utils::getRandomFloat() <= $this->fireChance){
				$this->fireIgnitions[$posHash] = $affectedBlock;
			}
		}
	}

	/**
	 * Ray march for explosions too large for a resistance grid.
	 */
	private function traceRays(bool $incendiary) : void{
		$blockFactory = RuntimeBlockStateRegistry::getInstance();
		$subChunkExplorer = new SubChunkExplorer($this->world);

		$mRays = $this->rays - 1;
		for($i = 0; $i < $this->rays; ++$i){
			for($j = 0; $j < $this->rays; ++$j){
				for($k = 0; $k < $this->rays; ++$k){
					if($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays){
						//this could be written as new Vector3(...)->normalize()->multiply(stepLen), but we're avoiding Vector3 for performance here
						[$shiftX, $shiftY, $shiftZ] = [$i / $mRays * 2 - 1, $j / $mRays * 2 - 1, $k / $mRays * 2 - 1];
						$len = sqrt($shiftX ** 2 + $shiftY ** 2 + $shiftZ ** 2);
						[$shiftX, $shiftY, $shiftZ] = [($shiftX / $len) * $this->stepLen, ($shiftY / $len) * $this->stepLen, ($shiftZ / $len) * $this->stepLen];
						$pointerX = $this->source->x;
						$pointerY = $this->source->y;
						$pointerZ = $this->source->z;

						for($blastForce = $this->radius * (mt_rand(700, 1300) / 1000); $blastForce > 0; $blastForce -= $this->stepLen * 0.75){
							$x = (int) $pointerX;
							$y = (int) $pointerY;
							$z = (int) $pointerZ;
							$vBlockX = $pointerX >= $x ? $x : $x - 1;
							$vBlockY = $pointerY >= $y ? $y : $y - 1;
							$vBlockZ = $pointerZ >= $z ? $z : $z - 1;

							$pointerX += $shiftX;
							$pointerY += $shiftY;
							$pointerZ += $shiftZ;

							if($subChunkExplorer->moveTo($vBlockX, $vBlockY, $vBlockZ) === SubChunkExplorerStatus::INVALID){
								continue;
							}
							$subChunk = $subChunkExplorer->currentSubChunk;
							if($subChunk === null){
								throw new AssumptionFailedError("SubChunkExplorer subchunk should not be null here");
							}

							$state = $subChunk->getBlockStateId($vBlockX & SubChunk::COORD_MASK, $vBlockY & SubChunk::COORD_MASK, $vBlockZ & SubChunk::COORD_MASK);

							$blastResistance = $blockFactory->blastResistance[$state] ?? 0;
							if($blastResistance >= 0){
								$blastForce -= ($blastResistance / 5 + 0.3) * $this->stepLen;
								if($blastForce > 0){
									$this->affect($vBlockX, $vBlockY, $vBlockZ, $incendiary);
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Executes the explosion's effects on the world. This includes destroying blocks (if any), harming and knocking back entities,
	 * and creating sounds and particles.
	 */
	public function explodeB() : bool{
		$source = (new Vector3($this->source->x, $this->source->y, $this->source->z))->floor();
		$yield = min(100, (1 / $this->radius) * 100);

		if($this->what instanceof Entity){
			$ev = new EntityExplodeEvent($this->what, $this->source, $this->affectedBlocks, $yield, $this->fireIgnitions);

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$yield = $ev->getYield();
			$this->affectedBlocks = $ev->getBlockList();
			$this->fireIgnitions = $ev->getIgnitions();
		}elseif($this->what instanceof Block){
			$ev = new BlockExplodeEvent(
				$this->what,
				$this->source,
				$this->affectedBlocks,
				$yield,
				$this->fireIgnitions,
			);

			$ev->call();
			if($ev->isCancelled()){
				return false;
			}else{
				$yield = $ev->getYield();
				$this->affectedBlocks = $ev->getAffectedBlocks();
				$this->fireIgnitions = $ev->getIgnitions();
			}
		}

		$explosionSize = $this->radius * 2;
		$minX = (int) floor($this->source->x - $explosionSize - 1);
		$maxX = (int) ceil($this->source->x + $explosionSize + 1);
		$minY = (int) floor($this->source->y - $explosionSize - 1);
		$maxY = (int) ceil($this->source->y + $explosionSize + 1);
		$minZ = (int) floor($this->source->z - $explosionSize - 1);
		$maxZ = (int) ceil($this->source->z + $explosionSize + 1);

		$explosionBB = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);

		/** @var Entity[] $list */
		$list = $this->world->getNearbyEntities($explosionBB, $this->what instanceof Entity ? $this->what : null);
		foreach($list as $entity){
			$entityPos = $entity->getPosition();
			$distance = $entityPos->distance($this->source) / $explosionSize;

			if($distance <= 1){
				$motion = $entityPos->subtractVector($this->source)->normalize();
				$exposure = $this->getExposure($this->source, $entity);

				$impact = (1 - $distance) * $exposure;

				$damage = (int) ((($impact * $impact + $impact) / 2) * 8 * $explosionSize + 1);

				if($this->what instanceof Entity){
					$ev = new EntityDamageByEntityEvent($this->what, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage);
				}elseif($this->what instanceof Block){
					$ev = new EntityDamageByBlockEvent($this->what, $entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
				}else{
					$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage);
				}

				$entity->attack($ev);
				$entity->setMotion($entity->getMotion()->addVector($motion->multiply($impact)));
			}
		}

		$air = VanillaItems::AIR();
		$airBlock = VanillaBlocks::AIR();
		$fireBlock = VanillaBlocks::FIRE();

		$powerSources = [];
		foreach($this->affectedBlocks as $hash => $block){
			$pos = $block->getPosition();
			if($block instanceof TNT){
				if(!$block->ignite(mt_rand(10, 30))){
					$this->world->setBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z, $airBlock);
				}
			}elseif($block instanceof PowerSource){
				$powerSources[$hash] = $block;
			}else{
				$this->dropBlock($block, $yield);
				if(($t = $this->world->getTileAt($pos->x, $pos->y, $pos->z)) !== null){
					$t->onBlockDestroyed(); //needed to create drops for inventories
				}
				$this->world->setBlockAt($pos->x, $pos->y, $pos->z, $this->isFireIgnited($hash, $block) ? $fireBlock : $airBlock);
			}
		}

		//breaking these lets circuits react, which must only happen once the rest of the terrain is gone,
		//otherwise a piston could move a block the explosion has already dropped
		foreach($powerSources as $hash => $affected){
			$pos = $affected->getPosition();
			$block = $this->world->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z);
			if(!$block->hasSameTypeId($affected)){
				continue;
			}
			$this->dropBlock($block, $yield);
			$block->onBreak($air);
			if($this->isFireIgnited($hash, $block) && $this->world->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z)->canBeReplaced()){
				$this->world->setBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z, $fireBlock);
			}
		}

		$this->world->addParticle($source, new HugeExplodeSeedParticle());
		$this->world->addSound($source, new ExplodeSound());

		return true;
	}

	private function dropBlock(Block $block, float $yield) : void{
		if(mt_rand(0, 100) < $yield){
			$air = VanillaItems::AIR();
			$drops = $block->getBreakInfo()->isExplosionHarvestable() ?
				$block->getDropsForCompatibleTool($air) :
				$block->getDrops($air);
			foreach($drops as $drop){
				$this->world->dropItem($block->getPosition()->add(0.5, 0.5, 0.5), $drop);
			}
		}
	}

	private function isFireIgnited(int $hash, Block $block) : bool{
		return isset($this->fireIgnitions[$hash]) && $block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL;
	}

	/**
	 * Returns the explosion exposure of an entity, used to calculate explosion impact.
	 */
	private function getExposure(Vector3 $origin, Entity $entity) : float{
		$bb = $entity->getBoundingBox();
		try{
			$region = $this->world->getCollisionRegion($bb->union(new AxisAlignedBB($origin->x, $origin->y, $origin->z, $origin->x, $origin->y, $origin->z)));
			return ExplosionMath::exposure($region, $origin, $bb);
		}catch(\ValueError){
			//outside the coordinates collision data supports, or too many voxels for one native call
		}

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
						self::lerp($x, $bb->minX, $bb->maxX) + $xOffset,
						self::lerp($y, $bb->minY, $bb->maxY),
						self::lerp($z, $bb->minZ, $bb->maxZ) + $zOffset
					);

					$intercepted = false;

					foreach(VoxelRayTrace::betweenPoints($origin, $point) as $pos){
						$block = $this->world->getBlock($pos);
						if($block->calculateIntercept($origin, $point) !== null){
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
	 * Sets a chance between 0 and 1 of creating a fire.
	 * For example, if the chance is 1/3, then that amount of affected blocks will be ignited.
	 *
	 * @param float $fireChance 0 ... 1
	 */
	public function setFireChance(float $fireChance) : void{
		Utils::checkFloatNotInfOrNaN("fireChance", $fireChance);
		if($fireChance < 0.0 || $fireChance > 1.0){
			throw new \InvalidArgumentException("Fire chance must be a number between 0 and 1.");
		}
		$this->fireChance = $fireChance;
	}

	private static function lerp(float $scale, float $a, float $b) : float{
		return $a + $scale * ($b - $a);
	}
}
