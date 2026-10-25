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

/**
 * When a handler has no method for a packet, the packet is dropped without decoding and a debug message is logged.
 * This attribute suppresses the debug message for the given packet, without the cost of decoding it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class SilentDiscard{

	/**
	 * @param int $packetId one of the Bedwire\PacketId constants
	 */
	public function __construct(
		public readonly int $packetId,
		public readonly string $comment = "",
	){}
}
