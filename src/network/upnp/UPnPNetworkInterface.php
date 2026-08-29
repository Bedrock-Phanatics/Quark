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

namespace quark\network\upnp;

use quark\network\NetworkInterface;
use quark\utils\Internet;
use quark\utils\InternetException;

final class UPnPNetworkInterface implements NetworkInterface{
	private ?string $serviceURL = null;

	public function __construct(
		private \Logger $logger,
		private string $ip,
		private int $port
	){
		if(!Internet::$online){
			throw new \RuntimeException("Server is offline");
		}

		$this->logger = new \PrefixedLogger($logger, "UPnP Port Forwarder");
	}

	public function start() : void{
		$this->logger->info("Attempting to portforward...");

		try{
			$this->serviceURL = UPnP::getServiceUrl();
			UPnP::portForward($this->serviceURL, Internet::getInternalIP(), $this->port, $this->port);
			$this->logger->info("Forwarded $this->ip:$this->port to external port $this->port");
		}catch(UPnPException | InternetException $e){
			$this->logger->error("UPnP portforward failed: " . $e->getMessage());
		}
	}

	public function setName(string $name) : void{

	}

	public function tick() : void{

	}

	public function shutdown() : void{
		if($this->serviceURL === null){
			return;
		}

		UPnP::removePortForward($this->serviceURL, $this->port);
	}
}
