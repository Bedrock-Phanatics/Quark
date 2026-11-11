<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEaseType;

enum CameraEase : string{
	case LINEAR = "linear";
	case SPRING = "spring";
	case IN_QUAD = "in_quad";
	case OUT_QUAD = "out_quad";
	case IN_OUT_QUAD = "in_out_quad";
	case IN_CUBIC = "in_cubic";
	case OUT_CUBIC = "out_cubic";
	case IN_OUT_CUBIC = "in_out_cubic";
	case IN_QUART = "in_quart";
	case OUT_QUART = "out_quart";
	case IN_OUT_QUART = "in_out_quart";
	case IN_QUINT = "in_quint";
	case OUT_QUINT = "out_quint";
	case IN_OUT_QUINT = "in_out_quint";
	case IN_SINE = "in_sine";
	case OUT_SINE = "out_sine";
	case IN_OUT_SINE = "in_out_sine";
	case IN_EXPO = "in_expo";
	case OUT_EXPO = "out_expo";
	case IN_OUT_EXPO = "in_out_expo";
	case IN_CIRC = "in_circ";
	case OUT_CIRC = "out_circ";
	case IN_OUT_CIRC = "in_out_circ";
	case IN_BOUNCE = "in_bounce";
	case OUT_BOUNCE = "out_bounce";
	case IN_OUT_BOUNCE = "in_out_bounce";
	case IN_BACK = "in_back";
	case OUT_BACK = "out_back";
	case IN_OUT_BACK = "in_out_back";
	case IN_ELASTIC = "in_elastic";
	case OUT_ELASTIC = "out_elastic";
	case IN_OUT_ELASTIC = "in_out_elastic";
	case INVERSE_LERP = "inverse_lerp";

	public function toProtocol() : CameraSetInstructionEaseType{
		return CameraSetInstructionEaseType::from($this->value);
	}
}
