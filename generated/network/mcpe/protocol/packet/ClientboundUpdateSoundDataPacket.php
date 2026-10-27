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

namespace quark\network\mcpe\protocol\packet;

use Bedwire\PacketId;
use quark\network\mcpe\protocol\types\SoundDataEventFade;
use quark\network\mcpe\protocol\types\SoundDataEventPause;
use quark\network\mcpe\protocol\types\SoundDataEventResume;
use quark\network\mcpe\protocol\types\SoundDataEventStop;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ClientboundUpdateSoundDataPacket{
	public const int ID = PacketId::CLIENTBOUND_UPDATE_SOUND_DATA;

	/**
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $stop
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $setVolume
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $setPitch
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $fade
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $seekTo
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $pause
	 * @param array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume} $resume
	 */
	public function __construct(
		public int $serverSoundHandle,
		public array $stop,
		public array $setVolume,
		public array $setPitch,
		public array $fade,
		public array $seekTo,
		public array $pause,
		public array $resume,
	){}
}
