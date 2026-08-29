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

namespace quark\item;

use quark\utils\RegistrySource;
use quark\world\sound\ArmorEquipChainSound;
use quark\world\sound\ArmorEquipCopperSound;
use quark\world\sound\ArmorEquipDiamondSound;
use quark\world\sound\ArmorEquipGenericSound;
use quark\world\sound\ArmorEquipGoldSound;
use quark\world\sound\ArmorEquipIronSound;
use quark\world\sound\ArmorEquipLeatherSound;
use quark\world\sound\ArmorEquipNetheriteSound;

/**
 * @internal
 * @phpstan-extends RegistrySource<ArmorMaterial>
 */
final class VanillaArmorMaterialsInputs extends RegistrySource{
	public function getTargetClassName() : string{
		return "VanillaArmorMaterials";
	}

	public function getTargetClassDocComment() : array{
		return ["Allows getting any vanilla armor material implemented by Quark"];
	}

	protected function register(string $name, ArmorMaterial $armorMaterial) : void{
		self::registerValue($name, $armorMaterial);
	}

	protected function setup() : void{
		self::register("leather", new ArmorMaterial(15, new ArmorEquipLeatherSound()));
		self::register("chainmail", new ArmorMaterial(12, new ArmorEquipChainSound()));
		self::register("copper", new ArmorMaterial(8, new ArmorEquipCopperSound()));
		self::register("iron", new ArmorMaterial(9, new ArmorEquipIronSound()));
		self::register("turtle", new ArmorMaterial(9, new ArmorEquipGenericSound()));
		self::register("gold", new ArmorMaterial(25, new ArmorEquipGoldSound()));
		self::register("diamond", new ArmorMaterial(10, new ArmorEquipDiamondSound()));
		self::register("netherite", new ArmorMaterial(15, new ArmorEquipNetheriteSound()));
	}
}
