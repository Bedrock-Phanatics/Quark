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

namespace quark\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use quark\block\utils\RecordType;
use quark\network\mcpe\protocol\packet\PlaySoundPacket;
use quark\network\mcpe\protocol\packet\RecordStartedPacket;

class RecordSound implements Sound{
	public function __construct(
		private RecordType $recordType,
		private int $serverSoundHandleId = 0
	){
	}

	public function encode(Vector3 $pos) : array{
		$sound = match ($this->recordType) {
				RecordType::DISK_13 => LevelSoundEvent::RECORD_13,
				RecordType::DISK_5 => LevelSoundEvent::RECORD_5,
				RecordType::DISK_CAT => LevelSoundEvent::RECORD_CAT,
				RecordType::DISK_BLOCKS => LevelSoundEvent::RECORD_BLOCKS,
				RecordType::DISK_CHIRP => LevelSoundEvent::RECORD_CHIRP,
				RecordType::DISK_CREATOR => LevelSoundEvent::RECORD_CREATOR,
				RecordType::DISK_CREATOR_MUSIC_BOX => LevelSoundEvent::RECORD_CREATOR_MUSIC_BOX,
				RecordType::DISK_FAR => LevelSoundEvent::RECORD_FAR,
				RecordType::DISK_LAVA_CHICKEN => LevelSoundEvent::RECORD_LAVA_CHICKEN,
				RecordType::DISK_MALL => LevelSoundEvent::RECORD_MALL,
				RecordType::DISK_MELLOHI => LevelSoundEvent::RECORD_MELLOHI,
				RecordType::DISK_OTHERSIDE => LevelSoundEvent::RECORD_OTHERSIDE,
				RecordType::DISK_PIGSTEP => LevelSoundEvent::RECORD_PIGSTEP,
				RecordType::DISK_PRECIPICE => LevelSoundEvent::RECORD_PRECIPICE,
				RecordType::DISK_RELIC => LevelSoundEvent::RECORD_RELIC,
				RecordType::DISK_STAL => LevelSoundEvent::RECORD_STAL,
				RecordType::DISK_STRAD => LevelSoundEvent::RECORD_STRAD,
				RecordType::DISK_WARD => LevelSoundEvent::RECORD_WARD,
				RecordType::DISK_11 => LevelSoundEvent::RECORD_11,
				RecordType::DISK_WAIT => LevelSoundEvent::RECORD_WAIT
		};
		return [
			new PlaySoundPacket(
				name: $sound,
				//the position is in eighths of a block
				position: new Vector3((int) ($pos->x * 8), (int) ($pos->y * 8), (int) ($pos->z * 8)),
				volume: 1.0,
				pitch: 1.0,
				loopCount: 0,
				bypassListenerRangeCheck: true,
				serverSoundHandle: $this->serverSoundHandleId,
			),
			new RecordStartedPacket(blockPosition: $pos->floor(), serverSoundHandle: $this->serverSoundHandleId),
		];
	}
}
