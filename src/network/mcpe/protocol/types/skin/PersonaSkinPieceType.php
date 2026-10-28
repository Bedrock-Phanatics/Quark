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

use quark\network\mcpe\protocol\types\PacketOrdinalEnumTrait;
use function count;

enum PersonaSkinPieceType : string{
	use PacketOrdinalEnumTrait;

	case UNKNOWN = 'unknown';
	case SKELETON = 'skeleton';
	case BODY = 'body';
	case SKIN = 'skin';
	case BOTTOM = 'bottom';
	case FEET = 'feet';
	case DRESS = 'dress';
	case TOP = 'top';
	case HIGH_PANTS = 'high_pants'; // TODO: check this, underscores are usually stripped?
	case HANDS = 'hands';
	case OUTERWEAR = 'outerwear';
	case FACIAL_HAIR = 'facialhair';
	case MOUTH = 'mouth';
	case EYES = 'eyes';
	case HAIR = 'hair';
	case HOOD = 'hood';
	case BACK = 'back';
	case FACE_ACCESSORY = 'faceaccessory';
	case HEAD = 'head';
	case LEGS = 'legs';
	case LEFT_LEG = 'leftleg';
	case RIGHT_LEG = 'rightleg';
	case ARMS = 'arms';
	case LEFT_ARM = 'leftarm';
	case RIGHT_ARM = 'rightarm';
	case CAPES = 'capes';
	case CLASSIC_SKIN = 'classicskin';
	case EMOTE = 'emote';
	case UNSUPPORTED = 'unsupported';

	private const JSON_STRING_TO_CASE = [
		"persona_unknown" => self::UNKNOWN,
		"persona_skeleton" => self::SKELETON,
		"persona_body" => self::BODY,
		"persona_skin" => self::SKIN,
		"persona_bottom" => self::BOTTOM,
		"persona_feet" => self::FEET,
		"persona_dress" => self::DRESS,
		"persona_top" => self::TOP,
		"persona_high_pants" => self::HIGH_PANTS,
		"persona_hand" => self::HANDS,
		"persona_outerwear" => self::OUTERWEAR,
		"persona_facial_hair" => self::FACIAL_HAIR,
		"persona_mouth" => self::MOUTH,
		"persona_eyes" => self::EYES,
		"persona_hair" => self::HAIR,
		"persona_hood" => self::HOOD,
		"persona_back" => self::BACK,
		"persona_face_accessory" => self::FACE_ACCESSORY,
		"persona_head" => self::HEAD,
		"persona_legs" => self::LEGS,
		"persona_left_leg" => self::LEFT_LEG,
		"persona_right_leg" => self::RIGHT_LEG,
		"persona_arms" => self::ARMS,
		"persona_left_arm" => self::LEFT_ARM,
		"persona_right_arm" => self::RIGHT_ARM,
		"persona_capes" => self::CAPES,
		"persona_classic_skin" => self::CLASSIC_SKIN,
		"persona_emote" => self::EMOTE,
		"persona_unsupported" => self::UNSUPPORTED,
	];

	public function toJsonString() : string{
		/** @var array<string, string> $caseToJsonString */
		static $caseToJsonString = [];

		if(count($caseToJsonString) === 0){
			foreach(self::JSON_STRING_TO_CASE as $jsonString => $case){
				$caseToJsonString[$case->name] = $jsonString;
			}
		}

		return $caseToJsonString[$this->name] ?? throw new \LogicException("Missing JSON string mapping for case " . $this->name);
	}

	public static function fromJsonString(string $raw) : self{
		return self::JSON_STRING_TO_CASE[$raw] ?? throw new \InvalidArgumentException("Unknown raw '$raw' JSON string case");
	}
}
