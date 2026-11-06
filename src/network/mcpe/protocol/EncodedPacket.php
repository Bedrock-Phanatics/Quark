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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use function strlen;

/**
 * A packet held as its encoded bytes, for packets sent to many players or cached. It cannot be changed, so it is safe
 * to share between recipients.
 */
final class EncodedPacket{

	private function __construct(
		private readonly int $id,
		private readonly string $bytes
	){}

	public static function of(ClientboundPacket $packet) : self{
		return new self($packet->pid(), self::encode($packet));
	}

	/**
	 * @param (ClientboundPacket|EncodedPacket)[] $packets
	 * @return EncodedPacket[]
	 * @phpstan-return list<EncodedPacket>
	 */
	public static function ofMany(array $packets) : array{
		$encoded = [];
		foreach($packets as $packet){
			$encoded[] = $packet instanceof self ? $packet : self::of($packet);
		}
		return $encoded;
	}

	/**
	 * @throws PacketDecodeException if the packet header is malformed
	 */
	public static function fromBytes(string $bytes) : self{
		return new self(self::idOf($bytes), $bytes);
	}

	/**
	 * Encodes a packet with its header, as it is written into a batch.
	 */
	public static function encode(Packet $packet) : string{
		$out = new ByteBufferWriter();
		$packet->encode($out);
		return $out->getData();
	}

	/**
	 * Reads the packet ID from the header of an encoded packet.
	 *
	 * @throws PacketDecodeException
	 */
	public static function idOf(string $bytes) : int{
		if(strlen($bytes) === 0){
			throw new PacketDecodeException("Packet is empty");
		}
		try{
			return VarInt::unpackUnsignedInt($bytes) & DataPacket::PID_MASK;
		}catch(DataDecodeException $e){
			throw PacketDecodeException::wrap($e, "Packet header");
		}
	}

	/**
	 * Whether clients accept packets with this ID.
	 */
	public static function isClientbound(int $id) : bool{
		static $clientbound = [];
		return $clientbound[$id] ??= PacketPool::getInstance()->getPacketById($id) instanceof ClientboundPacket;
	}

	public function getId() : int{
		return $this->id;
	}

	public function getName() : string{
		return PacketPool::getInstance()->getPacketById($this->id)?->getName() ?? "unknown_$this->id";
	}

	public function getBytes() : string{
		return $this->bytes;
	}

	/**
	 * Decodes a new copy of the packet each time, so changing it never affects anyone else this packet is sent to.
	 *
	 * @throws PacketDecodeException if the bytes are malformed or the ID is unknown
	 */
	public function getPacket() : Packet{
		$packet = PacketPool::getInstance()->getPacketById($this->id) ?? throw new PacketDecodeException("Unknown packet ID $this->id");
		$packet->decode(new ByteBufferReader($this->bytes));
		return $packet;
	}
}
