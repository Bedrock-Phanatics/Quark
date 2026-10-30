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

namespace quark\network\mcpe\bedwire;

use quark\network\mcpe\compression\NativeCompressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\PacketBroadcaster;
use quark\network\NetworkInterfaceStartException;
use quark\network\Transport;
use quark\Server;
use quark\utils\Utils;
use quark\YmlServerProperties as Yml;
use Symfony\Component\Filesystem\Path;
use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function min;

/**
 * NetherNet through ext-bedwire, which handles signaling, WebRTC, batching, compression and login verification on
 * its own thread.
 */
final class BedwireTransport implements Transport{

	public const NAME = "nethernet";

	/** Clients look for LAN games on this port. */
	private const LAN_PORT = 7551;

	public function __construct(
		private Server $server
	){}

	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array{
		try{
			$options = $this->options();
		}catch(\InvalidArgumentException $e){
			throw new NetworkInterfaceStartException("Invalid NetherNet settings in config/server.toml: " . $e->getMessage(), 0, $e);
		}

		return [new BedwireInterface(
			$this->server,
			$this->server->getIp(),
			$this->server->getPort(),
			$options,
			//clients are told the server's algorithm, which ext-bedwire then compresses with
			new NativeCompressor($this->server->getNetworkCompressor()->getNetworkType()),
			$packetBroadcaster,
			$entityEventBroadcaster,
			$typeConverter
		)];
	}

	/**
	 * @phpstan-return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private function options() : array{
		$config = $this->server->getConfigGroup();
		$options = [
			//room for players still joining or being kicked, the player limit itself is enforced at login
			"max_sessions" => min(65535, $this->server->getMaxPlayers() * 2 + 16),
			"authentication" => $this->server->getOnlineMode(),
			"identity_file" => Path::makeAbsolute($config->getPropertyString(Yml::TRANSPORT_NETHERNET_KEY_FILE, "nethernet-identity.der"), $this->server->getDataPath()),
			"lan_port" => $config->getPropertyBool(Yml::TRANSPORT_NETHERNET_LAN_DISCOVERY, true) ? self::LAN_PORT : 0,
			"trusted_identity_keys" => $this->server->getTrustedProxyKeys(),
			"ice_servers" => self::parseIceServers($config->getProperty(Yml::TRANSPORT_NETHERNET_ICE_SERVERS)),
			"udp_mux" => $config->getPropertyBool(Yml::TRANSPORT_NETHERNET_ICE_UDP_MUX, false),
			"unreliable_events" => true,
		];
		$bindAddress = $this->server->getIp();
		if($bindAddress !== "0.0.0.0"){
			$options["bind_address"] = $bindAddress;
		}
		$portRange = $config->getProperty(Yml::TRANSPORT_NETHERNET_PORT_RANGE);
		if($portRange !== null){
			if(!is_array($portRange) || count($portRange) !== 2 || !is_int($portRange[0] ?? null) || !is_int($portRange[1] ?? null) || $portRange[0] < 1 || $portRange[0] > $portRange[1] || $portRange[1] > 65535){
				throw new \InvalidArgumentException("port-range must be [min, max] with 1 <= min <= max <= 65535");
			}
			$options["port_range_begin"] = $portRange[0];
			$options["port_range_end"] = $portRange[1];
		}
		return $options;
	}

	/**
	 * @phpstan-return list<string>
	 * @throws \InvalidArgumentException
	 */
	private static function parseIceServers(mixed $servers) : array{
		if($servers === null){
			return [];
		}
		if(!is_array($servers)){
			throw new \InvalidArgumentException("ice-servers must be a list of ICE server URLs, got " . get_debug_type($servers));
		}
		$parsed = [];
		foreach(Utils::promoteKeys($servers) as $index => $server){
			if(!is_string($server) || $server === ""){
				throw new \InvalidArgumentException("ice-servers entry $index must be a URL such as stun:stun.example.com:3478, got " . get_debug_type($server));
			}
			$parsed[] = $server;
		}
		return $parsed;
	}
}
