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

namespace quark\network\mcpe\raklib;

use quark\lang\KnownTranslationFactory;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\PacketBroadcaster;
use quark\network\Network;
use quark\network\Transport;
use quark\Server;
use quark\ServerProperties;
use raklib\utils\InternetAddress;

/**
 * @deprecated Deprecated in favor of NetherNet as of Minecraft Bedrock 1.26.50
 */
final class RakLibTransport implements Transport{

	public const NAME = "raknet";

	public function __construct(
		private Server $server
	){}

	/**
	 * Returns whether a RakLib interface bound to the given address is registered on the network.
	 *
	 * @internal
	 */
	public static function isListeningOn(Network $network, string $ip, int $port, bool $ipV6) : bool{
		$address = new InternetAddress($ip, $port, $ipV6 ? 6 : 4);
		foreach($network->getInterfaces() as $interface){
			if($interface instanceof RakLibInterface && $interface->getBindAddress()->equals($address)){
				return true;
			}
		}
		return false;
	}

	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array{
		$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::quark_server_raknet_deprecated()));

		$interfaces = [new RakLibInterface($this->server, $this->server->getIp(), $this->server->getPort(), false, $packetBroadcaster, $entityEventBroadcaster, $typeConverter)];
		if($this->server->getConfigGroup()->getConfigBool(ServerProperties::ENABLE_IPV6, true)){
			$interfaces[] = new RakLibInterface($this->server, $this->server->getIpV6(), $this->server->getPortV6(), true, $packetBroadcaster, $entityEventBroadcaster, $typeConverter);
		}
		return $interfaces;
	}
}
