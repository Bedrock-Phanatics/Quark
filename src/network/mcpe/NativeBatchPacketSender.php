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

namespace quark\network\mcpe;

use quark\network\mcpe\protocol\Packet;

/**
 * A sender for transports that batch, compress and frame natively, such as Bedwire. Sessions using one hand it
 * uncompressed batches and receive already decompressed packets through NetworkSession::handleDecodedPackets().
 */
interface NativeBatchPacketSender extends PacketSender{

	/**
	 * Sends a batch body, each packet behind its varint length, starting $offset bytes into $batch.
	 */
	public function sendBatch(string $batch, int $offset) : void;

	/**
	 * Encodes packets straight into the transport's send queue, next to ones that are already encoded. Returns false
	 * when it cannot take them this way, such as a packet that does not encode or a batch over the transport's
	 * limits, so the caller can encode them itself and report or split them.
	 *
	 * @param (Packet|string)[] $packets
	 * @phpstan-param list<Packet|string> $packets
	 */
	public function sendPackets(array $packets) : bool;

	/**
	 * Tells the transport that PHP verified the login, which it needs before the session may leave the login stage.
	 *
	 * @param string $clientPublicKeyDer the key from the verified login
	 */
	public function acceptLogin(string $clientPublicKeyDer) : void;
}
