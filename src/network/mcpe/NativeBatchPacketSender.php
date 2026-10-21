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
	 * Tells the transport that PHP verified the login, which it needs before the session may leave the login stage.
	 *
	 * @param string $clientPublicKeyDer the key from the verified login
	 */
	public function acceptLogin(string $clientPublicKeyDer) : void;
}
