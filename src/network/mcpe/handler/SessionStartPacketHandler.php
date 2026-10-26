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

use Bedwire\Protocol;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Packets;

/**
 * @phpstan-import-type RequestNetworkSettingsFields from Packets
 */
final class SessionStartPacketHandler extends PacketHandler{

	/**
	 * @phpstan-param \Closure() : void $onSuccess
	 */
	public function __construct(
		private NetworkSession $session,
		private \Closure $onSuccess
	){}

	/**
	 * @phpstan-param RequestNetworkSettingsFields $packet
	 */
	public function handleRequestNetworkSettings(array $packet) : bool{
		$protocolVersion = $packet["client_network_version"];
		$this->session->recordProtocolVersion($protocolVersion);
		if(!$this->isCompatibleProtocol($protocolVersion)){
			$this->session->disconnectIncompatibleProtocol($protocolVersion);

			return true;
		}

		//TODO: we're filling in the defaults to get pre-1.19.30 behaviour back for now, but we should explore the new options in the future
		$this->session->sendDataPacket(Packets::networkSettings([
			"compression_threshold" => NetworkSettingsPacket::COMPRESS_EVERYTHING,
			"compression_algorithm" => $this->session->getCompressor()->getNetworkType()->value,
			"client_throttle_enabled" => false,
			"client_throttle_threshold" => 0,
			"client_throttle_scalar" => 0.0,
		]));
		($this->onSuccess)();

		return true;
	}

	protected function isCompatibleProtocol(int $protocolVersion) : bool{
		return $protocolVersion === Protocol::VERSION;
	}
}
