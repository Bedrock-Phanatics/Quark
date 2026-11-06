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

namespace quark\network\mcpe\convert;

use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use quark\entity\InvalidSkinException;
use quark\entity\Skin;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;
use const JSON_THROW_ON_ERROR;

class LegacySkinAdapter implements SkinAdapter{
	private const MAX_ANIMATIONS = 32;
	private const MAX_PERSONA_PIECES = 64;
	private const MAX_PIECE_TINT_COLORS = 16;
	private const MAX_IMAGE_SIZE = 4096;

	public function toSkinData(Skin $skin) : SkinData{
		$capeData = $skin->getCapeData();
		$capeImage = $capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData);
		$geometryName = $skin->getGeometryName();
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}
		$geometryData = $skin->getGeometryData();
		if($geometryData === ""){
			//the client drops the connection if it receives an empty geometry string
			$geometryData = SkinData::GEOMETRY_DATA_NONE;
		}
		return new SkinData(
			$skin->getSkinId(),
			json_encode(["geometry" => ["default" => $geometryName]], JSON_THROW_ON_ERROR),
			SkinImage::fromLegacy($skin->getSkinData()),
			[],
			$capeImage,
			$geometryData
		);
	}

	public function fromSkinData(SkinData $data) : Skin{
		self::checkLimits($data);
		if($data->isPersona()){
			return new Skin("Standard_Custom", str_repeat(random_bytes(3) . "\xff", 4096));
		}

		$capeData = $data->isPersonaCapeOnClassic() ? "" : $data->getCapeImage()->getData();

		$resourcePatch = json_decode($data->getResourcePatch(), true);
		if(is_array($resourcePatch) && isset($resourcePatch["geometry"]["default"]) && is_string($resourcePatch["geometry"]["default"])){
			$geometryName = $resourcePatch["geometry"]["default"];
		}else{
			throw new InvalidSkinException("Missing geometry name field");
		}

		return new Skin($data->getSkinId(), $data->getSkinImage()->getData(), $capeData, $geometryName, $data->getGeometryDataJson());
	}

	/**
	 * @throws InvalidSkinException
	 */
	private static function checkLimits(SkinData $data) : void{
		if(count($data->getAnimations()) > self::MAX_ANIMATIONS || count($data->getPersonaPieces()) > self::MAX_PERSONA_PIECES || count($data->getPieceTintColors()) > self::MAX_PIECE_TINT_COLORS){
			throw new InvalidSkinException("Skins may have at most " . self::MAX_ANIMATIONS . " animations, " . self::MAX_PERSONA_PIECES . " persona pieces and " . self::MAX_PIECE_TINT_COLORS . " piece tints");
		}
		self::checkImage($data->getSkinImage());
		self::checkImage($data->getCapeImage());
		foreach($data->getAnimations() as $animation){
			self::checkImage($animation->getImage());
			$type = $animation->getType();
			$expressionType = $animation->getExpressionType();
			if($type < 0 || $type > 0xffffffff || $expressionType < 0 || $expressionType > 0xffffffff){
				throw new InvalidSkinException("Animation type and expression must be in range 0 - " . 0xffffffff . ", got $type and $expressionType");
			}
		}
	}

	/**
	 * @throws InvalidSkinException
	 */
	private static function checkImage(SkinImage $image) : void{
		$width = $image->getWidth();
		$height = $image->getHeight();
		if($width > self::MAX_IMAGE_SIZE || $height > self::MAX_IMAGE_SIZE){
			throw new InvalidSkinException("Image dimensions must be at most " . self::MAX_IMAGE_SIZE . ", got {$width}x$height");
		}
	}
}
