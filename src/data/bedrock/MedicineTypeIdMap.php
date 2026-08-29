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

namespace quark\data\bedrock;

use quark\item\MedicineType;
use quark\utils\SingletonTrait;

final class MedicineTypeIdMap{
	use SingletonTrait;
	/** @phpstan-use IntSaveIdMapTrait<MedicineType> */
	use IntSaveIdMapTrait;

	private function __construct(){
		foreach(MedicineType::cases() as $case){
			$this->register(match($case){
				MedicineType::ANTIDOTE => MedicineTypeIds::ANTIDOTE,
				MedicineType::ELIXIR => MedicineTypeIds::ELIXIR,
				MedicineType::EYE_DROPS => MedicineTypeIds::EYE_DROPS,
				MedicineType::TONIC => MedicineTypeIds::TONIC,
			}, $case);
		}
	}
}
