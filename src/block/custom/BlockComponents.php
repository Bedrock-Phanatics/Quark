<?php

declare(strict_types=1);

namespace quark\block\custom;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use quark\math\AxisAlignedBB;
use quark\utils\Utils;
use function count;
use function in_array;
use function is_bool;
use function preg_match;
use function preg_match_all;
use function strlen;

/**
 * Client visuals for a custom block. Server behavior such as collision, light, friction and mining time is derived
 * from the block itself and cannot be overridden here.
 */
final class BlockComponents{
	public const int MAX_PERMUTATIONS = 256;
	public const string FULL_BLOCK = "minecraft:geometry.full_block";
	public const string CROSS = "minecraft:geometry.cross";
	public const array RENDER_METHODS = ["opaque", "double_sided", "blend", "alpha_test", "alpha_test_single_sided", "blend_to_opaque", "alpha_test_to_opaque", "alpha_test_single_sided_to_opaque"];
	public const array FACES = ["*", "up", "down", "north", "south", "east", "west", "side"];

	private ?CompoundTag $geometry = null;
	/** @var array<string, CompoundTag> */
	private array $materials = [];
	private ?CompoundTag $transformation = null;
	private ?AxisAlignedBB $selectionBox = null;
	private ?string $mapColor = null;
	/** @var list<array{string, BlockComponents}> */
	private array $permutations = [];

	/**
	 * @param array<string, bool|string> $boneVisibility bone name => visible, or a Molang condition
	 */
	public function geometry(string $identifier, array $boneVisibility = [], ?string $culling = null) : self{
		if(preg_match('/^[a-z0-9_.:-]{1,128}$/D', $identifier) !== 1 || ($culling !== null && preg_match('/^[a-z0-9_.:-]{1,128}$/D', $culling) !== 1)){
			throw new \InvalidArgumentException("Invalid geometry or culling identifier");
		}
		$bones = CompoundTag::create();
		foreach(Utils::promoteKeys($boneVisibility) as $bone => $visible){
			if(preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', (string) $bone) !== 1){
				throw new \InvalidArgumentException("Invalid bone name '$bone'");
			}
			if(is_bool($visible)){
				$bones->setByte((string) $bone, $visible ? 1 : 0);
			}else{
				self::validateCondition($visible);
				$bones->setString((string) $bone, $visible);
			}
		}
		$this->geometry = CompoundTag::create()
			->setTag("bone_visibility", $bones)
			->setString("culling", $culling ?? "")
			->setString("culling_layer", "minecraft:culling_layer.undefined")
			->setString("culling_shape", "")
			->setString("identifier", $identifier)
			->setByte("ignoreGeometryForIsSolid", 0)
			->setByte("isV1Fullblock", 0)
			->setByte("needsLegacyTopRotation", 0)
			->setByte("useBlockTypeLightAbsorption", 0)
			->setByte("uv_lock", 0);
		return $this;
	}

	/** Uses one texture from terrain_texture.json for every face. */
	public function texture(string $texture, string $renderMethod = "opaque", bool $faceDimming = true, bool $ambientOcclusion = true) : self{
		return $this->material("*", $texture, $renderMethod, $faceDimming, $ambientOcclusion);
	}

	/** @param string $face one of {@see self::FACES}, or a custom material instance name used by the geometry */
	public function material(string $face, string $texture, string $renderMethod = "opaque", bool $faceDimming = true, bool $ambientOcclusion = true, bool $isotropic = false) : self{
		if(preg_match('/^[a-z0-9_*]{1,32}$/D', $face) !== 1 || preg_match('/^[a-z0-9_.:\/-]{1,128}$/D', $texture) !== 1 || !in_array($renderMethod, self::RENDER_METHODS, true)){
			throw new \InvalidArgumentException("Invalid material '$face', texture '$texture' or render method '$renderMethod'");
		}
		if(count($this->materials) >= 16 && !isset($this->materials[$face])){
			throw new \InvalidArgumentException("Too many material instances");
		}
		//packed_bools mirrors the vanilla data-driven palette, which sets bit 2 alongside face dimming
		$this->materials[$face] = CompoundTag::create()
			->setFloat("ambient_occlusion", $ambientOcclusion ? 1.0 : 0.0)
			->setByte("packed_bools", ($faceDimming ? 1 : 0) | ($isotropic ? 2 : 0) | 4)
			->setString("render_method", $renderMethod)
			->setString("texture", $texture)
			->setString("tint_method", "none")
			->setByte("face_dimming", $faceDimming ? 1 : 0)
			->setByte("isotropic", $isotropic ? 1 : 0);
		return $this;
	}

	/** Rotation in quarter turns, translation in blocks. Applies only inside a permutation. */
	public function transformation(int $rotateX = 0, int $rotateY = 0, int $rotateZ = 0, float $translateX = 0, float $translateY = 0, float $translateZ = 0, float $scale = 1.0) : self{
		foreach([$translateX, $translateY, $translateZ] as $t){
			if($t < -1 || $t > 1){ throw new \InvalidArgumentException("Translation must be within one block"); }
		}
		if($scale <= 0 || $scale > 2){ throw new \InvalidArgumentException("Scale must be in (0, 2]"); }
		$this->transformation = self::transformationTag($rotateX & 3, $rotateY & 3, $rotateZ & 3, $translateX, $translateY, $translateZ, $scale);
		return $this;
	}

	/** Overrides the selection outline, which otherwise follows the block's collision boxes. */
	public function selectionBox(AxisAlignedBB $box) : self{
		if($box->minX < 0 || $box->minY < 0 || $box->minZ < 0 || $box->maxX > 1 || $box->maxY > 1 || $box->maxZ > 1 || $box->minX >= $box->maxX || $box->minY >= $box->maxY || $box->minZ >= $box->maxZ){
			throw new \InvalidArgumentException("Selection boxes must be non-empty and inside the block");
		}
		$this->selectionBox = clone $box;
		return $this;
	}

	public function mapColor(string $hex) : self{
		if(preg_match('/^#[0-9a-fA-F]{6}$/D', $hex) !== 1){
			throw new \InvalidArgumentException("Map color must be #RRGGBB");
		}
		$this->mapColor = $hex;
		return $this;
	}

	/**
	 * Applies extra visuals while the Molang condition holds, e.g. "query.block_state('suko:lit') == true".
	 * Later permutations override earlier ones.
	 */
	public function permutation(string $condition, BlockComponents $components) : self{
		self::validateCondition($condition);
		if($components->permutations !== [] || $components->selectionBox !== null){
			throw new \InvalidArgumentException("Permutations cannot contain nested permutations or selection boxes");
		}
		if(count($this->permutations) >= self::MAX_PERMUTATIONS){
			throw new \InvalidArgumentException("Too many permutations");
		}
		$this->permutations[] = [$condition, clone $components];
		return $this;
	}

	/** @internal */
	public static function validateCondition(string $condition) : void{
		if($condition === "" || strlen($condition) > 1024 || preg_match('/^[A-Za-z0-9_.:\'"()!=<>&|+\-*\/ ]+$/D', $condition) !== 1){
			throw new \InvalidArgumentException("Invalid permutation condition '$condition'");
		}
	}

	/**
	 * @internal
	 * @param array<string, true> $stateNames
	 */
	public function validateStates(array $stateNames) : void{
		$conditions = [];
		foreach($this->permutations as [$condition]){
			$conditions[] = $condition;
		}
		foreach($conditions as $condition){
			preg_match_all('/query\.block_state\(\s*\'([^\']*)\'\s*\)/', $condition, $matches);
			foreach($matches[1] as $name){
				if(!isset($stateNames[$name])){
					throw new \InvalidArgumentException("Permutation condition uses unknown state '$name'");
				}
			}
		}
	}

	public function hasGeometry() : bool{ return $this->geometry !== null; }

	public function hasMaterials() : bool{ return $this->materials !== []; }

	public function getSelectionBox() : ?AxisAlignedBB{ return $this->selectionBox === null ? null : clone $this->selectionBox; }

	/** @internal */
	public function toNbt() : CompoundTag{
		$tag = CompoundTag::create();
		if($this->geometry !== null){
			$tag->setTag("minecraft:geometry", clone $this->geometry);
		}
		if($this->materials !== []){
			$materials = CompoundTag::create();
			foreach(Utils::stringifyKeys($this->materials) as $face => $material){
				$materials->setTag($face, clone $material);
			}
			$tag->setTag("minecraft:material_instances", CompoundTag::create()->setTag("mappings", CompoundTag::create())->setTag("materials", $materials));
		}
		if($this->transformation !== null){
			$tag->setTag("minecraft:transformation", clone $this->transformation);
		}
		if($this->mapColor !== null){
			$tag->setTag("minecraft:map_color", CompoundTag::create()->setString("color", $this->mapColor)->setString("tint_method", "none"));
		}
		return $tag;
	}

	/**
	 * @internal
	 * @return list<array{string, CompoundTag}>
	 */
	public function getPermutationTags() : array{
		$result = [];
		foreach($this->permutations as [$condition, $components]){
			$result[] = [$condition, $components->toNbt()];
		}
		return $result;
	}

	/** @internal */
	public static function transformationTag(int $rx, int $ry, int $rz, float $tx = 0, float $ty = 0, float $tz = 0, float $scale = 1.0) : CompoundTag{
		return CompoundTag::create()
			->setInt("RX", $rx)->setFloat("RXP", 0)
			->setInt("RY", $ry)->setFloat("RYP", 0)
			->setInt("RZ", $rz)->setFloat("RZP", 0)
			->setFloat("SX", $scale)->setFloat("SXP", 0)
			->setFloat("SY", $scale)->setFloat("SYP", 0)
			->setFloat("SZ", $scale)->setFloat("SZP", 0)
			->setFloat("TX", $tx)->setFloat("TY", $ty)->setFloat("TZ", $tz)
			->setByte("hasJsonVersionBeforeValidation", 0);
	}

	/** @internal Bedrock uses pixels, with selection origins centered on X and Z. */
	public static function selectionTag(AxisAlignedBB $box) : CompoundTag{
		return CompoundTag::create()
			->setByte("enabled", 1)
			->setTag("origin", new ListTag([new FloatTag($box->minX * 16 - 8), new FloatTag($box->minY * 16), new FloatTag($box->minZ * 16 - 8)]))
			->setTag("size", new ListTag([new FloatTag(($box->maxX - $box->minX) * 16), new FloatTag(($box->maxY - $box->minY) * 16), new FloatTag(($box->maxZ - $box->minZ) * 16)]));
	}
}
