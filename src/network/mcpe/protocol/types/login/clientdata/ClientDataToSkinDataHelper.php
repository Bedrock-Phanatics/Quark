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

namespace quark\network\mcpe\protocol\types\login\clientdata;

use pocketmine\color\Color;
use quark\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use quark\network\mcpe\protocol\types\skin\SkinAnimation;
use quark\network\mcpe\protocol\types\skin\SkinArmSizeType;
use quark\network\mcpe\protocol\types\skin\SkinData;
use quark\network\mcpe\protocol\types\skin\SkinImage;
use quark\utils\Utils;
use Ramsey\Uuid\Uuid;
use function array_map;
use function array_values;
use function base64_decode;
use function count;
use function hexdec;
use function is_int;
use function preg_match;

final class ClientDataToSkinDataHelper{

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function safeB64Decode(string $base64, string $context) : string{
		$result = base64_decode($base64, true);
		if($result === false){
			throw new \InvalidArgumentException("$context: Malformed base64, cannot be decoded");
		}
		return $result;
	}

	/**
	 * Parses a hex color string in #AARRGGBB or shorter formats into a Color.
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function parseColorString(string $colorString) : Color{
		if(preg_match('/^#([a-fA-F0-9]{1,8})$/', $colorString, $matches) !== 1){
			throw new \InvalidArgumentException("Invalid hex color string: '$colorString'");
		}

		$argb = hexdec($matches[1]);
		if(!is_int($argb)){
			throw new \InvalidArgumentException("Invalid hex color string: '$colorString'");
		}

		return Color::fromARGB($argb);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function parsePersonaSkinPiece(ClientDataPersonaSkinPiece $piece) : PersonaSkinPiece{
		if(!Uuid::isValid($piece->PackId)){
			throw new \InvalidArgumentException("Invalid Persona skin piece pack ID: '$piece->PackId'");
		}

		return new PersonaSkinPiece(
			$piece->PieceId,
			PersonaSkinPieceType::fromJsonString($piece->PieceType),
			Uuid::fromString($piece->PackId),
			$piece->IsDefault,
			$piece->ProductId
		);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function parsePersonaPieceTintColor(ClientDataPersonaPieceTintColor $tint) : PersonaPieceTintColor{
		if(($colorsCount = count($tint->Colors)) !== PersonaPieceTintColor::EXPECTED_COLOR_COUNT){
			throw new \InvalidArgumentException(
				"Persona skin piece tint must contain exactly " . PersonaPieceTintColor::EXPECTED_COLOR_COUNT . " colors, got " . $colorsCount
			);
		}

		/** @phpstan-var array{Color, Color, Color, Color} $colors */
		$colors = array_values(array_map(self::parseColorString(...), $tint->Colors));

		return new PersonaPieceTintColor(
			PersonaSkinPieceType::fromJsonString($tint->PieceType),
			$colors
		);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromClientData(ClientData $clientData) : SkinData{
		$animations = [];
		foreach(Utils::promoteKeys($clientData->AnimatedImageData) as $k => $animation){
			$animations[] = new SkinAnimation(
				new SkinImage(
					$animation->ImageHeight,
					$animation->ImageWidth,
					self::safeB64Decode($animation->Image, "AnimatedImageData.$k.Image")
				),
				$animation->Type,
				$animation->Frames,
				$animation->AnimationExpression
			);
		}

		return new SkinData(
			$clientData->SkinId,
			"",
			self::safeB64Decode($clientData->SkinResourcePatch, "SkinResourcePatch"),
			new SkinImage($clientData->SkinImageHeight, $clientData->SkinImageWidth, self::safeB64Decode($clientData->SkinData, "SkinData")),
			$animations,
			new SkinImage($clientData->CapeImageHeight, $clientData->CapeImageWidth, self::safeB64Decode($clientData->CapeData, "CapeData")),
			self::safeB64Decode($clientData->SkinGeometryData, "SkinGeometryData"),
			self::safeB64Decode($clientData->SkinGeometryDataEngineVersion, "SkinGeometryDataEngineVersion"), //yes, they actually base64'd the version!
			self::safeB64Decode($clientData->SkinAnimationData, "SkinAnimationData"),
			$clientData->CapeId,
			null,
			SkinArmSizeType::fromPacket($clientData->ArmSize),
			self::parseColorString($clientData->SkinColor),
			array_values(array_map(self::parsePersonaSkinPiece(...), $clientData->PersonaPieces)),
			array_values(array_map(self::parsePersonaPieceTintColor(...), $clientData->PieceTintColors)),
			$clientData->TrustedSkin ? SkinData::TRUSTED_SKIN_TRUE : SkinData::TRUSTED_SKIN_FALSE,
			$clientData->PremiumSkin,
			$clientData->PersonaSkin,
			$clientData->CapeOnClassicSkin,
			true, //assume this is true? there's no field for it ...
			$clientData->OverrideSkin ?? true,
			$clientData->ProfileHash,
		);
	}
}
