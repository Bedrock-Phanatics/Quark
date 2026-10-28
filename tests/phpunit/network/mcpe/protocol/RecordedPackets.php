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

use PHPUnit\Framework\TestCase;
use quark\utils\Filesystem;
use function base64_decode;
use function gzinflate;
use function is_array;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Packets BedrockProtocol encoded for these tests while it was still a dependency, in the order each test encoded
 * them. They pin the bytes the native encoder has to match, and the hostile packets it refuses to write itself.
 */
final class RecordedPackets{
	/** @var array<string, list<string>>|null */
	private static ?array $packets = null;
	/** @var array<string, int> */
	private static array $replayed = [];

	/**
	 * The test's next recorded packet, for tests that replay them in the order they first encoded them.
	 */
	public static function next(TestCase $test) : string{
		$key = self::key($test);
		$index = self::$replayed[$key] ?? 0;
		self::$replayed[$key] = $index + 1;
		return self::get($test, $index);
	}

	private static function key(TestCase $test) : string{
		return $test::class . "::" . $test->name() . ($test->dataName() !== "" ? "#" . $test->dataName() : "");
	}

	public static function get(TestCase $test, int $index) : string{
		if(self::$packets === null){
			$packets = json_decode(Filesystem::fileGetContents(__DIR__ . "/golden/bedrock-protocol.json"), true, flags: JSON_THROW_ON_ERROR);
			if(!is_array($packets)){
				throw new \RuntimeException("Recorded packets should be an object of lists");
			}
			/** @var array<string, list<string>> $packets */
			self::$packets = $packets;
		}
		$key = self::key($test);
		$encoded = self::$packets[$key][$index] ?? throw new \RuntimeException("No packet $index recorded for $key");
		$compressed = base64_decode($encoded, true);
		$bytes = $compressed !== false ? gzinflate($compressed) : false;
		if($bytes === false){
			throw new \RuntimeException("Packet $index recorded for $key is corrupt");
		}
		return $bytes;
	}
}
