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

/**
 * Network-related classes
 */
namespace quark\network;

use quark\event\server\NetworkInterfaceRegisterEvent;
use quark\event\server\NetworkInterfaceUnregisterEvent;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\PacketBroadcaster;
use quark\utils\Utils;
use function get_class;
use function preg_match;
use function spl_object_id;
use function strtolower;
use function time;
use function trim;
use const PHP_INT_MAX;

class Network{
	/** @var NetworkInterface[] */
	private array $interfaces = [];

	/** @var AdvancedNetworkInterface[] */
	private array $advancedInterfaces = [];

	/** @var array<string, Transport> */
	private array $transports = [];
	/** @var array<string, Transport> */
	private array $activeTransports = [];
	private bool $transportsStarted = false;

	/** @var RawPacketHandler[] */
	private array $rawPacketHandlers = [];

	/**
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $bannedIps = [];

	private BidirectionalBandwidthStatsTracker $bandwidthTracker;
	private string $name;
	private NetworkSessionManager $sessionManager;

	public function __construct(
		private \Logger $logger
	){
		$this->sessionManager = new NetworkSessionManager();
		$this->bandwidthTracker = new BidirectionalBandwidthStatsTracker(5);
	}

	public function getBandwidthTracker() : BidirectionalBandwidthStatsTracker{ return $this->bandwidthTracker; }

	/**
	 * @return NetworkInterface[]
	 */
	public function getInterfaces() : array{
		return $this->interfaces;
	}

	public function getSessionManager() : NetworkSessionManager{
		return $this->sessionManager;
	}

	public function getConnectionCount() : int{
		return $this->sessionManager->getSessionCount();
	}

	public function getValidConnectionCount() : int{
		return $this->sessionManager->getValidSessionCount();
	}

	public function tick() : void{
		foreach($this->interfaces as $interface){
			$interface->tick();
		}

		$this->sessionManager->tick();
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	public function registerInterface(NetworkInterface $interface) : bool{
		return $this->addInterface($interface);
	}

	/** @throws NetworkInterfaceStartException */
	private function addInterface(NetworkInterface $interface) : bool{
		$ev = new NetworkInterfaceRegisterEvent($interface);
		$ev->call();
		if(!$ev->isCancelled()){
			$interface->start();
			$this->interfaces[$hash = spl_object_id($interface)] = $interface;
			if($interface instanceof AdvancedNetworkInterface){
				$this->advancedInterfaces[$hash] = $interface;
				$interface->setNetwork($this);
				foreach(Utils::stringifyKeys($this->bannedIps) as $ip => $until){
					$interface->blockAddress($ip);
				}
				foreach($this->rawPacketHandlers as $handler){
					$interface->addRawPacketFilter($handler->getPattern());
				}
			}
			$interface->setName($this->name);
			return true;
		}
		return false;
	}

	public function registerTransport(string $name, Transport $transport) : void{
		$name = strtolower(trim($name));
		if(isset($this->transports[$name])){
			throw new \InvalidArgumentException("Transport \"$name\" is already registered");
		}
		$this->transports[$name] = $transport;
	}

	public function getTransport(string $name) : ?Transport{
		return $this->transports[strtolower(trim($name))] ?? null;
	}

	/** @return array<string, Transport> */
	public function getTransports() : array{
		return $this->transports;
	}

	public function activateTransport(string $name) : void{
		$name = strtolower(trim($name));
		if(!isset($this->transports[$name])){
			throw new \InvalidArgumentException("Transport \"$name\" is not registered");
		}
		if($this->transportsStarted){
			throw new \LogicException("Transports have already been started");
		}
		$this->activeTransports[$name] = $this->transports[$name];
	}

	/** @return array<string, Transport> */
	public function getActiveTransports() : array{
		return $this->activeTransports;
	}

	/** @throws NetworkInterfaceStartException */
	public function startActiveTransports(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : void{
		if($this->transportsStarted){
			throw new \LogicException("Transports have already been started");
		}
		$this->transportsStarted = true;
		foreach(Utils::stringifyKeys($this->activeTransports) as $name => $transport){
			try{
				foreach($transport->createInterfaces($packetBroadcaster, $entityEventBroadcaster, $typeConverter) as $interface){
					$this->addInterface($interface);
				}
			}catch(NetworkInterfaceStartException $e){
				throw new NetworkInterfaceStartException("Failed to start transport \"$name\": " . $e->getMessage(), 0, $e);
			}
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function unregisterInterface(NetworkInterface $interface) : void{
		if(!isset($this->interfaces[$hash = spl_object_id($interface)])){
			throw new \InvalidArgumentException("Interface " . get_class($interface) . " is not registered on this network");
		}
		(new NetworkInterfaceUnregisterEvent($interface))->call();
		unset($this->interfaces[$hash], $this->advancedInterfaces[$hash]);
		$interface->shutdown();
	}

	/**
	 * Sets the server name shown on each interface Query
	 */
	public function setName(string $name) : void{
		$this->name = $name;
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	public function getName() : string{
		return $this->name;
	}

	public function updateName() : void{
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	public function sendPacket(string $address, int $port, string $payload) : void{
		foreach($this->advancedInterfaces as $interface){
			$interface->sendRawPacket($address, $port, $payload);
		}
	}

	/**
	 * Blocks an IP address from the main interface. Setting timeout to -1 will block it forever
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->bannedIps[$address] = $timeout > 0 ? time() + $timeout : PHP_INT_MAX;
		foreach($this->advancedInterfaces as $interface){
			$interface->blockAddress($address, $timeout);
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->bannedIps[$address]);
		foreach($this->advancedInterfaces as $interface){
			$interface->unblockAddress($address);
		}
	}

	/**
	 * Registers a raw packet handler on the network.
	 */
	public function registerRawPacketHandler(RawPacketHandler $handler) : void{
		$this->rawPacketHandlers[spl_object_id($handler)] = $handler;

		$regex = $handler->getPattern();
		foreach($this->advancedInterfaces as $interface){
			$interface->addRawPacketFilter($regex);
		}
	}

	/**
	 * Unregisters a previously-registered raw packet handler.
	 */
	public function unregisterRawPacketHandler(RawPacketHandler $handler) : void{
		unset($this->rawPacketHandlers[spl_object_id($handler)]);
	}

	public function processRawPacket(AdvancedNetworkInterface $interface, string $address, int $port, string $packet) : void{
		if(isset($this->bannedIps[$address]) && time() < $this->bannedIps[$address]){
			$this->logger->debug("Dropped raw packet from banned address $address $port");
			return;
		}
		$handled = false;
		foreach($this->rawPacketHandlers as $handler){
			if(preg_match($handler->getPattern(), $packet) === 1){
				try{
					$handled = $handler->handle($interface, $address, $port, $packet);
				}catch(PacketHandlingException $e){
					$handled = true;
					$this->logger->error("Bad raw packet from /$address:$port: " . $e->getMessage());
					$this->blockAddress($address, 600);
					break;
				}
			}
		}
		if(!$handled){
			$this->logger->debug("Unhandled raw packet from /$address:$port: " . Utils::printablePacketBuffer($packet));
		}
	}
}
