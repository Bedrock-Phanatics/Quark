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

use pocketmine\network\mcpe\protocol\TextPacket as BedrockTextPacket;
use quark\network\mcpe\protocol\packet\TextPacket;
use quark\network\mcpe\protocol\types\TextMessageAndParams;
use quark\network\mcpe\protocol\types\TextMessageOnly;

/**
 * Chat, popup and tip messages sent by the server.
 */
final class TextPackets{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-param array{message_only: TextMessageOnly}|array{message_and_params: TextMessageAndParams} $body
	 */
	private static function text(bool $localize, array $body) : TextPacket{
		return new TextPacket($localize, $body, "", "");
	}

	//TODO: HACK! Empty message crashes or bugs out client in 1.21.130
	private static function nonEmpty(string $message) : string{
		return $message === "" ? " " : $message;
	}

	public static function raw(string $message) : TextPacket{
		return self::text(false, ["message_only" => new TextMessageOnly(BedrockTextPacket::TYPE_RAW, self::nonEmpty($message))]);
	}

	public static function tip(string $message) : TextPacket{
		return self::text(false, ["message_only" => new TextMessageOnly(BedrockTextPacket::TYPE_TIP, self::nonEmpty($message))]);
	}

	public static function popup(string $message) : TextPacket{
		return self::text(false, ["message_and_params" => new TextMessageAndParams(BedrockTextPacket::TYPE_POPUP, self::nonEmpty($message), [])]);
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function translation(string $key, array $parameters = []) : TextPacket{
		return self::text(true, ["message_and_params" => new TextMessageAndParams(BedrockTextPacket::TYPE_TRANSLATION, self::nonEmpty($key), $parameters)]);
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function jukeboxPopup(string $key, array $parameters = []) : TextPacket{
		return self::text(true, ["message_and_params" => new TextMessageAndParams(BedrockTextPacket::TYPE_JUKEBOX_POPUP, self::nonEmpty($key), $parameters)]);
	}
}
