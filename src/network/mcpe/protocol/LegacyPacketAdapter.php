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
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

/**
 * @internal Bridges BedrockProtocol packet objects while their remaining users move to Packet.
 */
final class LegacyPacketAdapter{

	private function __construct(){
		//NOOP
	}

	public static function fromLegacy(ClientboundPacket $packet) : Packet{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		return Packet::fromEncoded($writer->getData());
	}

	/**
	 * @phpstan-param list<Packet|ClientboundPacket> $packets
	 * @phpstan-return list<Packet>
	 */
	public static function fromLegacyList(array $packets) : array{
		$result = [];
		foreach($packets as $packet){
			$result[] = $packet instanceof Packet ? $packet : self::fromLegacy($packet);
		}
		return $result;
	}

	/**
	 * @param int|null $unread set to the number of bytes the decoder left unread
	 * @param-out int  $unread
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function toLegacy(Packet $packet, ?int &$unread = null) : ServerboundPacket{
		$encoded = $packet->getEncoded();
		$legacy = PacketPool::getInstance()->getPacket($encoded);
		if(!$legacy instanceof ServerboundPacket){
			throw new PacketDecodeException("Unexpected non-serverbound packet " . $packet->getName());
		}
		$stream = new ByteBufferReader($encoded);
		$legacy->decode($stream);
		$unread = $stream->getUnreadLength();
		return $legacy;
	}
}
