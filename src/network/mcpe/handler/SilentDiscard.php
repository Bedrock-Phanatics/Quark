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

namespace quark\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\Packet;

/**
 * When a packet's default handler isn't overridden, packets will normally be dropped without decoding and a debug
 * message will be logged. This attribute allows suppressing the debug message in this case, without overriding the
 * handler (which would force the packet to be decoded, wasting CPU time).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class SilentDiscard{

	/**
	 * @phpstan-param class-string<covariant Packet> $packetClass
	 */
	public function __construct(
		public readonly string $packetClass,
		public readonly string $comment = "",
	){}
}
