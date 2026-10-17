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

namespace quark\network\mcpe\nethernet;

use pmmp\thread\ThreadSafe;
use pocketmine\nethernet\signaling\SignalingRegistry;

abstract class NetherNetSignalingFactory extends ThreadSafe{

	/**
	 * Runs on the NetherNet thread. Signaling must not perform blocking operations.
	 */
	abstract public function register(SignalingRegistry $registry, NetherNetSignalingContext $context) : void;
}
