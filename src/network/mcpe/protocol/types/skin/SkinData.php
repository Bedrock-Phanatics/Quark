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

namespace quark\network\mcpe\protocol\types\skin;

use pocketmine\color\Color;
use quark\network\mcpe\protocol\ProtocolInfo;
use Ramsey\Uuid\Uuid;
use function count;

class SkinData{

	public const GEOMETRY_DATA_NONE = "{}";

	public const TRUSTED_SKIN_UNSET = "unset";
	public const TRUSTED_SKIN_FALSE = "false";
	public const TRUSTED_SKIN_TRUE = "true";

	public const MAX_ANIMATIONS = 32;
	public const MAX_PERSONA_PIECES = 64;
	public const MAX_PIECE_TINT_COLORS = 16;

	private Color $skinColor;
	private SkinImage $capeImage;
	private string $fullSkinId;

	/**
	 * @param SkinAnimation[]         $animations
	 * @param PersonaSkinPiece[]      $personaPieces
	 * @param PersonaPieceTintColor[] $pieceTintColors
	 * @phpstan-param list<SkinAnimation> $animations
	 * @phpstan-param list<PersonaSkinPiece> $personaPieces
	 * @phpstan-param list<PersonaPieceTintColor> $pieceTintColors
	 */
	public function __construct(
		private string $skinId,
		private string $playFabId,
		private string $resourcePatch,
		private SkinImage $skinImage,
		private array $animations = [],
		?SkinImage $capeImage = null,
		private string $geometryDataJson = self::GEOMETRY_DATA_NONE,
		private string $geometryDataEngineVersion = ProtocolInfo::MINECRAFT_VERSION_NETWORK,
		private string $animationData = "",
		private string $capeId = "",
		?string $fullSkinId = null,
		private SkinArmSizeType $armSize = SkinArmSizeType::WIDE,
		?Color $skinColor = null,
		private array $personaPieces = [],
		private array $pieceTintColors = [],
		private string $trustedSkinFlag = self::TRUSTED_SKIN_TRUE,
		private bool $premium = false,
		private bool $persona = false,
		private bool $personaCapeOnClassic = false,
		private bool $isPrimaryUser = true,
		private bool $override = true,
		private string $profileHash = ""
	){
		if(count($animations) > self::MAX_ANIMATIONS || count($personaPieces) > self::MAX_PERSONA_PIECES || count($pieceTintColors) > self::MAX_PIECE_TINT_COLORS){
			throw new \InvalidArgumentException("Skins may have at most " . self::MAX_ANIMATIONS . " animations, " . self::MAX_PERSONA_PIECES . " persona pieces and " . self::MAX_PIECE_TINT_COLORS . " piece tints");
		}
		$this->skinColor = $skinColor ?? new Color(0, 0, 0, 0);
		$this->capeImage = $capeImage ?? new SkinImage(0, 0, "");
		//this has to be unique or the client will do stupid things
		$this->fullSkinId = $fullSkinId ?? Uuid::uuid4()->toString();
	}

	public function getSkinId() : string{ return $this->skinId; }

	public function getPlayFabId() : string{ return $this->playFabId; }

	public function getResourcePatch() : string{ return $this->resourcePatch; }

	public function getSkinImage() : SkinImage{ return $this->skinImage; }

	/**
	 * @return SkinAnimation[]
	 * @phpstan-return list<SkinAnimation>
	 */
	public function getAnimations() : array{ return $this->animations; }

	public function getCapeImage() : SkinImage{ return $this->capeImage; }

	public function getGeometryDataJson() : string{ return $this->geometryDataJson; }

	public function getGeometryDataEngineVersion() : string{ return $this->geometryDataEngineVersion; }

	public function getAnimationData() : string{ return $this->animationData; }

	public function getCapeId() : string{ return $this->capeId; }

	public function getFullSkinId() : string{ return $this->fullSkinId; }

	public function getArmSize() : SkinArmSizeType{ return $this->armSize; }

	public function getSkinColor() : Color{ return $this->skinColor; }

	/**
	 * @return PersonaSkinPiece[]
	 * @phpstan-return list<PersonaSkinPiece>
	 */
	public function getPersonaPieces() : array{ return $this->personaPieces; }

	/**
	 * @return PersonaPieceTintColor[]
	 * @phpstan-return list<PersonaPieceTintColor>
	 */
	public function getPieceTintColors() : array{ return $this->pieceTintColors; }

	public function isPersona() : bool{ return $this->persona; }

	public function isPremium() : bool{ return $this->premium; }

	public function isPersonaCapeOnClassic() : bool{ return $this->personaCapeOnClassic; }

	public function isPrimaryUser() : bool{ return $this->isPrimaryUser; }

	public function isOverride() : bool{ return $this->override; }

	public function getTrustedSkinFlag() : string{ return $this->trustedSkinFlag; }

	public function getProfileHash() : string{ return $this->profileHash; }
}
