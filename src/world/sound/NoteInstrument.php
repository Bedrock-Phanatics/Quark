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

namespace quark\world\sound;

enum NoteInstrument{
	case PIANO;
	case BASS_DRUM;
	case SNARE;
	case CLICKS_AND_STICKS;
	case DOUBLE_BASS;
	case BELL;
	case FLUTE;
	case CHIME;
	case GUITAR;
	case XYLOPHONE;
	case IRON_XYLOPHONE;
	case COW_BELL;
	case DIDGERIDOO;
	case BIT;
	case BANJO;
	case PLING;
}
