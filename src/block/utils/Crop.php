<?php

declare(strict_types=1);

namespace quark\block\utils;

interface Crop extends Ageable{
	/** Returns whether the crop has reached its mature growth stage. */
	public function isMature() : bool;
}
