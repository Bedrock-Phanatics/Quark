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
use quark\network\mcpe\protocol\LegacyPacketAdapter;
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
		$packets = LegacyPacketAdapter::fromLegacyList(array_values($packets));
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

		if(count($recipients) === 1){
			//nothing to share, so leave encoding to the recipient's next flush
			foreach($recipients as $recipient){
				foreach($packets as $packet){
					$recipient->addToSendBuffer($packet);
				}
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

		$totalLength = 0;
		$packetBuffers = [];
		$encodeScope = PulseZones::$playerNetworkSendEncode->start();
		try{
			foreach($packets as $packet){
				$buffer = $packet->getEncoded();
				//varint length prefix + packet buffer
				$totalLength += (((int) log(strlen($buffer), 128)) + 1) + strlen($buffer);
				$packetBuffers[] = $buffer;
			}
		}finally{
			PulseZones::$playerNetworkSendEncode->stop($encodeScope);
		}

		foreach($targetsByCompressor as $compressorId => $compressorTargets){
			$compressor = $compressors[$compressorId];

			$threshold = $compressor->getCompressionThreshold();
			if(count($compressorTargets) > 1 && $threshold !== null && $totalLength >= $threshold){
				//do not prepare shared batch unless we're sure it will be compressed
				$batch = $this->server->prepareBatch(NetworkSession::encodeBatch($packetBuffers), $compressor, pulse: PulseZones::$playerNetworkSendCompressBroadcast);
				foreach($compressorTargets as $target){
					$target->queueCompressed($batch);
				}
			}else{
				foreach($compressorTargets as $target){
					foreach($packets as $packet){
						$target->addToSendBuffer($packet);
					}
				}
			}
		}
	}
}
