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

namespace quark\build\generate_protocol_packets;

use Bedwire\Protocol;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function array_keys;
use function count;
use function dirname;
use function extension_loaded;
use function file_put_contents;
use function hash;
use function implode;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function str_replace;
use function strtoupper;
use function ucwords;
use const JSON_THROW_ON_ERROR;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @return array<mixed>
 */
function table(mixed $value, string $what) : array{
	if(!is_array($value)){
		throw new \RuntimeException("Expected $what to be an array");
	}
	return $value;
}

function str(mixed $value, string $what) : string{
	if(!is_string($value)){
		throw new \RuntimeException("Expected $what to be a string");
	}
	return $value;
}

function pascal(string $name) : string{
	return str_replace("_", "", ucwords($name, "_"));
}

final class Generator{
	/**
	 * @param array<mixed> $schema
	 * @param array<mixed> $directions packets the schema marks bidirectional that clients only send or only receive
	 */
	public function __construct(private array $schema, private array $directions){}

	public function generate() : string{
		$narrowed = [];
		foreach(["serverbound" => Protocol::SERVERBOUND, "clientbound" => Protocol::CLIENTBOUND] as $key => $direction){
			foreach(table($this->directions[$key] ?? null, "$key packets") as $packetName){
				$narrowed[str($packetName, "a packet name")] = $direction;
			}
		}

		$handlers = "";
		$clientbound = "";
		foreach(Utils::promoteKeys(table($this->schema["packets"] ?? null, "packets")) as $name => $packet){
			$name = (string) $name;
			$packet = table($packet, "packet $name");
			$pascal = pascal($name);
			$constant = strtoupper($name);
			$direction = $packet["direction"] ?? null;
			if(!is_int($direction)){
				throw new \RuntimeException("Expected the direction of $name to be an int");
			}
			if(isset($narrowed[$name])){
				if($direction !== Protocol::BIDIRECTIONAL){
					throw new \RuntimeException("$name is listed in packet-directions.json, but the schema already sends it one way");
				}
				$direction = $narrowed[$name];
				unset($narrowed[$name]);
			}
			if(($direction & Protocol::SERVERBOUND) !== 0){
				$handlers .= "\t\tPacketId::$constant => \"handle$pascal\",\n";
			}
			if(($direction & Protocol::CLIENTBOUND) !== 0){
				$clientbound .= "\t\tPacketId::$constant => true,\n";
			}
		}

		if(count($narrowed) > 0){
			throw new \RuntimeException("packet-directions.json lists unknown packets: " . implode(", ", array_keys($narrowed)));
		}

		$protocol = $this->schema["protocol"] ?? null;
		if(!is_int($protocol)){
			throw new \RuntimeException("Expected the protocol version to be an int");
		}
		$minecraft = str($this->schema["minecraft"] ?? null, "the Minecraft version");
		$hash = hash("sha256", json_encode($this->schema, JSON_THROW_ON_ERROR));
		return Filesystem::fileGetContents(__DIR__ . "/templates/header.php") . <<<CLASS

namespace quark\\network\\mcpe\\protocol;

use Bedwire\\PacketId;

/**
 * Packet directions of protocol $protocol (Minecraft $minecraft).
 * This class is generated from Bedwire\\Protocol::schema() by build/codegen/protocol-packets.php. Do not edit it manually.
 */
final class Packets{
	public const PROTOCOL = $protocol;
	public const SCHEMA_HASH = "$hash";

	/**
	 * Handler methods for packets clients may send.
	 */
	public const HANDLERS = [
$handlers	];

	/**
	 * Packets servers may send.
	 */
	public const CLIENTBOUND = [
$clientbound	];

	private function __construct(){
		//NOOP
	}
}

CLASS;
	}
}

if(!extension_loaded("bedwire")){
	throw new \RuntimeException("ext-bedwire is required to generate the packet directions");
}
$output = dirname(__DIR__, 2) . "/generated/network/mcpe/protocol/Packets.php";
if(!@mkdir(dirname($output), recursive: true) && !is_dir(dirname($output))){
	throw new \RuntimeException("Couldn't create directory: " . dirname($output));
}
$directions = json_decode(Filesystem::fileGetContents(__DIR__ . "/packet-directions.json"), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($output, (new Generator(Protocol::schema(), table($directions, "packet directions")))->generate());

echo "Done generating Packets.\n";
