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

use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Packets;
use quark\pulse\internal\PulseZones;
use quark\Server;
use function array_values;
use function count;
use function log;
use function spl_object_id;
use function strlen;

final class StandardPacketBroadcaster implements PacketBroadcaster{
	public function __construct(
		private Server $server
	){}

	public function broadcastPackets(array $recipients, array $packets) : void{
		$packets = array_values($packets);
		foreach($packets as $packet){
			if(!isset(Packets::CLIENTBOUND[$packet->getId()])){
				throw new \InvalidArgumentException("Clients do not accept " . $packet->getName() . " packets");
			}
		}
		//TODO: this shouldn't really be called here, since the broadcaster might be replaced by an alternative
		//implementation that doesn't fire events
		if(DataPacketSendEvent::hasHandlers()){
			$ev = new DataPacketSendEvent($recipients, $packets);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
			$packets = $ev->getPackets();
		}

		//nothing to share with one recipient, so leave encoding to its next flush
		$encodedPackets = null;
		if(count($recipients) > 1){
			$encodeScope = PulseZones::$playerNetworkSendEncode->start();
			try{
				$encodedPackets = EncodedPacket::ofMany($packets);
			}catch(\ValueError){
				//each recipient's flush reports the bad packet and drops only that session, instead of failing the tick
			}finally{
				PulseZones::$playerNetworkSendEncode->stop($encodeScope);
			}
		}
		if($encodedPackets === null){
			foreach($recipients as $recipient){
				$recipient->addToSendBuffer($packets);
			}
			return;
		}

		$compressors = [];

		$targetsByCompressor = [];
		foreach($recipients as $recipient){
			//TODO: different compressors might be compatible, it might not be necessary to split them up by object
			$compressor = $recipient->getCompressor();
			$compressors[spl_object_id($compressor)] = $compressor;

			$targetsByCompressor[spl_object_id($compressor)][] = $recipient;
		}
		//only a shared batch needs the batch size, so it is worked out the first time one might be built
		$totalLength = 0;
		$packetBuffers = null;

		foreach($targetsByCompressor as $compressorId => $compressorTargets){
			$compressor = $compressors[$compressorId];

			$threshold = $compressor->getCompressionThreshold();
			if(count($compressorTargets) > 1 && $threshold !== null){
				if($packetBuffers === null){
					$packetBuffers = [];
					foreach($encodedPackets as $encoded){
						$buffer = $encoded->getBytes();
						//varint length prefix + packet buffer
						$totalLength += (((int) log(strlen($buffer), 128)) + 1) + strlen($buffer);
						$packetBuffers[] = $buffer;
					}
				}
				if($totalLength >= $threshold){
					foreach($compressorTargets as $target){
						$target->checkSendable($packets);
					}
					//do not prepare shared batch unless we're sure it will be compressed
					$batch = $this->server->prepareBatch(NetworkSession::encodeBatch($packetBuffers), $compressor, pulse: PulseZones::$playerNetworkSendCompressBroadcast);
					foreach($compressorTargets as $target){
						$target->queueCompressed($batch);
					}
					continue;
				}
			}
			foreach($compressorTargets as $target){
				$target->addToSendBuffer($encodedPackets);
			}
		}
	}
}
