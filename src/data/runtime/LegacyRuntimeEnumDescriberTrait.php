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

namespace quark\data\runtime;

/**
 * Provides backwards-compatible shims for the old codegen'd enum describer methods.
 * This is kept for plugin backwards compatibility, but these functions should not be used in new code.
 * @deprecated
 */
trait LegacyRuntimeEnumDescriberTrait{
	abstract protected function enum(\UnitEnum &$case) : void;

	public function bellAttachmentType(\quark\block\utils\BellAttachmentType &$value) : void{
		$this->enum($value);
	}

	public function copperOxidation(\quark\block\utils\CopperOxidation &$value) : void{
		$this->enum($value);
	}

	public function coralType(\quark\block\utils\CoralType &$value) : void{
		$this->enum($value);
	}

	public function dirtType(\quark\block\utils\DirtType &$value) : void{
		$this->enum($value);
	}

	public function dripleafState(\quark\block\utils\DripleafState &$value) : void{
		$this->enum($value);
	}

	public function dyeColor(\quark\block\utils\DyeColor &$value) : void{
		$this->enum($value);
	}

	public function froglightType(\quark\block\utils\FroglightType &$value) : void{
		$this->enum($value);
	}

	public function leverFacing(\quark\block\utils\LeverFacing &$value) : void{
		$this->enum($value);
	}

	public function medicineType(\quark\item\MedicineType &$value) : void{
		$this->enum($value);
	}

	public function mobHeadType(\quark\block\utils\MobHeadType &$value) : void{
		$this->enum($value);
	}

	public function mushroomBlockType(\quark\block\utils\MushroomBlockType &$value) : void{
		$this->enum($value);
	}

	public function potionType(\quark\item\PotionType &$value) : void{
		$this->enum($value);
	}

	public function slabType(\quark\block\utils\SlabType &$value) : void{
		$this->enum($value);
	}

	public function suspiciousStewType(\quark\item\SuspiciousStewType &$value) : void{
		$this->enum($value);
	}
}
