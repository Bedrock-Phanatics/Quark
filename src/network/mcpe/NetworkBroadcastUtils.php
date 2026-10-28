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

use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Packet;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use function count;
use function spl_object_id;

final class NetworkBroadcastUtils{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param Player[]                 $recipients
	 * @param (Packet|EncodedPacket)[] $packets
	 */
	public static function broadcastPackets(array $recipients, array $packets) : bool{
		if(count($packets) === 0){
			throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
		}

		$scope = PulseZones::$broadcastPackets->start();
		try{
			/** @phpstan-var array<int, PacketBroadcaster> $uniqueBroadcasters */
			$uniqueBroadcasters = [];
			/** @phpstan-var array<int, array<int, NetworkSession>> $broadcasterTargets */
			$broadcasterTargets = [];
			foreach($recipients as $player){
				if($player->isConnected()){
					$session = $player->getNetworkSession();
					$broadcaster = $session->getBroadcaster();
					$id = spl_object_id($broadcaster);
					$uniqueBroadcasters[$id] = $broadcaster;
					$broadcasterTargets[$id][spl_object_id($session)] = $session;
				}
			}
			foreach($uniqueBroadcasters as $id => $broadcaster){
				$broadcaster->broadcastPackets($broadcasterTargets[$id], $packets);
			}

			return count($uniqueBroadcasters) > 0;
		}finally{
			PulseZones::$broadcastPackets->stop($scope);
		}
	}

	/**
	 * @param Player[] $recipients
	 * @phpstan-param \Closure(EntityEventBroadcaster, array<int, NetworkSession>) : void $callback
	 */
	public static function broadcastEntityEvent(array $recipients, \Closure $callback) : void{
		$uniqueBroadcasters = [];
		$broadcasterTargets = [];

		foreach($recipients as $recipient){
			$session = $recipient->getNetworkSession();
			$broadcaster = $session->getEntityEventBroadcaster();
			$uniqueBroadcasters[spl_object_id($broadcaster)] = $broadcaster;
			$broadcasterTargets[spl_object_id($broadcaster)][spl_object_id($session)] = $session;
		}

		foreach($uniqueBroadcasters as $k => $broadcaster){
			$callback($broadcaster, $broadcasterTargets[$k]);
		}
	}
}
