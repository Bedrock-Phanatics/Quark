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

namespace quark\network\mcpe\protocol;

use pocketmine\network\mcpe\protocol\TextPacket;

/**
 * Chat, popup and tip messages sent by the server.
 *
 * @phpstan-import-type TextBody from Packets
 */
final class TextPackets{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-param TextBody $body
	 */
	private static function text(bool $localize, array $body) : Packet{
		return Packets::text([
			"localize" => $localize,
			"body" => $body,
			"senders_xuid" => "",
			"platform_id" => "",
			"filtered_message" => null,
		]);
	}

	//TODO: HACK! Empty message crashes or bugs out client in 1.21.130
	private static function nonEmpty(string $message) : string{
		return $message === "" ? " " : $message;
	}

	public static function raw(string $message) : Packet{
		return self::text(false, ["message_only" => ["message_type" => TextPacket::TYPE_RAW, "message" => self::nonEmpty($message)]]);
	}

	public static function tip(string $message) : Packet{
		return self::text(false, ["message_only" => ["message_type" => TextPacket::TYPE_TIP, "message" => self::nonEmpty($message)]]);
	}

	public static function popup(string $message) : Packet{
		return self::text(false, ["message_and_params" => ["message_type" => TextPacket::TYPE_POPUP, "message" => self::nonEmpty($message), "parameter_list" => []]]);
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function translation(string $key, array $parameters = []) : Packet{
		return self::text(true, ["message_and_params" => ["message_type" => TextPacket::TYPE_TRANSLATION, "message" => self::nonEmpty($key), "parameter_list" => $parameters]]);
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function jukeboxPopup(string $key, array $parameters = []) : Packet{
		return self::text(true, ["message_and_params" => ["message_type" => TextPacket::TYPE_JUKEBOX_POPUP, "message" => self::nonEmpty($key), "parameter_list" => $parameters]]);
	}
}
