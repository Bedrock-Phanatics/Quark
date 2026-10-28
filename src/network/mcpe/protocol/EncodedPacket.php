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

namespace quark\network\mcpe\protocol;

use Bedwire\PacketException;
use Bedwire\Protocol;

/**
 * A packet held as its encoded bytes, for packets sent to many players or cached. It cannot be changed, so it is safe
 * to share between recipients.
 */
final class EncodedPacket{
	public const int MAX_DECODED_VALUES = 65536;

	private function __construct(
		private readonly int $id,
		private readonly string $bytes
	){}

	/**
	 * @throws \ValueError if the packet's fields do not fit it
	 */
	public static function of(Packet $packet) : self{
		ProtocolClasses::bind();
		return new self($packet->getId(), Protocol::encode($packet->getId(), $packet instanceof ArrayPacket ? $packet->fields : $packet));
	}

	/**
	 * @throws PacketException if the packet header is malformed
	 */
	public static function fromBytes(string $bytes) : self{
		return new self(Protocol::packetId($bytes), $bytes);
	}

	public function getId() : int{
		return $this->id;
	}

	public function getName() : string{
		return Protocol::name($this->id) ?? "unknown_$this->id";
	}

	public function getBytes() : string{
		return $this->bytes;
	}

	/**
	 * Decodes a new copy of the packet each time, so changing it never affects anyone else this packet is sent to.
	 *
	 * @throws PacketException if the bytes are malformed or the ID is unknown
	 */
	public function getPacket() : Packet{
		ProtocolClasses::bind();
		$packet = Protocol::decode($this->bytes, self::MAX_DECODED_VALUES);
		if(!$packet instanceof Packet){
			throw new PacketException("Packet $this->id does not decode to a packet class");
		}
		return $packet;
	}
}
