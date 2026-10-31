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

use quark\network\mcpe\protocol\packet\TextPacket;
use quark\network\mcpe\protocol\types\TextBody;
use quark\network\mcpe\protocol\types\TextMessageAndParams;
use quark\network\mcpe\protocol\types\TextMessageOnly;
use function array_slice;
use function count;
use function mb_strcut;
use function strlen;

/**
 * Chat, popup and tip messages sent by the server.
 */
final class TextPackets{

	private function __construct(){
		//NOOP
	}

	private static function text(bool $localize, TextBody $body) : TextPacket{
		return new TextPacket($localize, $body, "", "");
	}

	private const MAX_MESSAGE_BYTES = 65536;
	private const MAX_PARAMETERS = 4;

	//TODO: HACK! Empty message crashes or bugs out client in 1.21.130
	private static function message(string $message) : string{
		if($message === ""){
			return " ";
		}
		return strlen($message) > self::MAX_MESSAGE_BYTES ? mb_strcut($message, 0, self::MAX_MESSAGE_BYTES, "UTF-8") : $message;
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 * @phpstan-return list<string>
	 */
	private static function parameters(array $parameters) : array{
		return count($parameters) > self::MAX_PARAMETERS ? array_slice($parameters, 0, self::MAX_PARAMETERS) : $parameters;
	}

	public static function raw(string $message) : TextPacket{
		return self::text(false, new TextMessageOnly(TextPacket::TYPE_RAW, self::message($message)));
	}

	public static function tip(string $message) : TextPacket{
		return self::text(false, new TextMessageOnly(TextPacket::TYPE_TIP, self::message($message)));
	}

	public static function popup(string $message) : TextPacket{
		return self::text(false, new TextMessageAndParams(TextPacket::TYPE_POPUP, self::message($message), []));
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function translation(string $key, array $parameters = []) : TextPacket{
		return self::text(true, new TextMessageAndParams(TextPacket::TYPE_TRANSLATION, self::message($key), self::parameters($parameters)));
	}

	/**
	 * @param string[] $parameters
	 * @phpstan-param list<string> $parameters
	 */
	public static function jukeboxPopup(string $key, array $parameters = []) : TextPacket{
		return self::text(true, new TextMessageAndParams(TextPacket::TYPE_JUKEBOX_POPUP, self::message($key), self::parameters($parameters)));
	}
}
