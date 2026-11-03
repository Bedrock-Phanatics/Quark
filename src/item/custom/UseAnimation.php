<?php

declare(strict_types=1);

namespace quark\item\custom;

enum UseAnimation : int{
	case NONE = 0;
	case EAT = 1;
	case DRINK = 2;
	case BLOCK = 3;
	case BOW = 4;
	case CAMERA = 5;
	case SPEAR = 6;
	case CROSSBOW = 9;
	case SPYGLASS = 10;
	case BRUSH = 12;
}
