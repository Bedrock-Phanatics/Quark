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
use function array_pop;
use function array_push;
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
use function json_encode;
use function lcfirst;
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

/**
 * Renders the types of Bedwire\Protocol::schema() as PHPStan types, one alias per struct, union and enum.
 */
final class Generator{
	/** @phpstan-var array<string, true> */
	private array $aliases = [];
	/** @phpstan-var array<string, true> types that contain themselves, which PHPStan aliases cannot express */
	private array $recursive = [];

	/**
	 * @param array<mixed> $schema
	 */
	public function __construct(private array $schema){
		$references = [];
		foreach(Utils::promoteKeys(table($schema["types"] ?? null, "types")) as $name => $type){
			$name = (string) $name;
			$references[$name] = [];
			$type = table($type, "type $name");
			foreach(table($type["fields"] ?? $type["variants"] ?? [], "members of $name") as $member){
				self::collect($member, $references[$name]);
			}
		}
		foreach(Utils::stringifyKeys($references) as $name => $direct){
			$seen = [];
			$pending = $direct;
			while(count($pending) > 0){
				$next = array_pop($pending);
				if($next === $name){
					$this->recursive[$name] = true;
					break;
				}
				if(!isset($seen[$next])){
					$seen[$next] = true;
					array_push($pending, ...($references[$next] ?? []));
				}
			}
		}
	}

	/**
	 * @phpstan-param list<string> $out
	 */
	private static function collect(mixed $type, array &$out) : void{
		if(is_array($type)){
			$kind = str($type[0] ?? null, "type kind");
			if($kind === "type"){
				$out[] = str($type[1] ?? null, "type name");
			}elseif($kind === "optional" || $kind === "list"){
				self::collect($type[1] ?? null, $out);
			}
		}
	}

	private function render(mixed $type) : string{
		if(is_string($type)){
			return $type;
		}
		$type = table($type, "a field type");
		$kind = str($type[0] ?? null, "type kind");
		return match($kind){
			"optional" => $this->render($type[1] ?? null) . "|null",
			"list" => "list<" . $this->render($type[1] ?? null) . ">",
			"bytes", "bits" => "string",
			"type" => isset($this->recursive[$name = str($type[1] ?? null, "type name")]) ? "array<mixed>" : $name,
			default => throw new \RuntimeException("Unknown schema type $kind")
		};
	}

	private function shape(mixed $fields) : string{
		$parts = [];
		foreach(Utils::promoteKeys(table($fields, "fields")) as $name => $type){
			$parts[] = "$name: " . $this->render($type);
		}
		return "array{" . implode(", ", $parts) . "}";
	}

	private function union(mixed $variants) : string{
		$parts = [];
		foreach(Utils::promoteKeys(table($variants, "variants")) as $name => $type){
			$parts[] = "array{" . $name . ": " . $this->render($type) . "}";
		}
		return implode("|", $parts);
	}

	private function alias(string $name, string $type) : string{
		if(isset($this->aliases[$name])){
			throw new \RuntimeException("Duplicate type alias $name");
		}
		$this->aliases[$name] = true;
		return " * @phpstan-type $name $type\n";
	}

	public function generate() : string{
		$aliases = "";
		foreach(Utils::promoteKeys(table($this->schema["types"] ?? null, "types")) as $name => $type){
			$name = (string) $name;
			if(isset($this->recursive[$name])){
				continue;
			}
			$type = table($type, "type $name");
			$aliases .= $this->alias($name, match(str($type["kind"] ?? null, "kind of $name")){
				"struct" => $this->shape($type["fields"] ?? null),
				"union" => $this->union($type["variants"] ?? null),
				"enum" => "int",
				default => throw new \RuntimeException("Unknown kind of $name")
			});
		}

		$factories = "";
		$handlers = "";
		foreach(Utils::promoteKeys(table($this->schema["packets"] ?? null, "packets")) as $name => $packet){
			$name = (string) $name;
			$packet = table($packet, "packet $name");
			$pascal = pascal($name);
			$method = lcfirst($pascal);
			$constant = strtoupper($name);
			$aliases .= $this->alias($pascal . "Fields", $this->shape($packet["fields"] ?? null));
			$factories .= <<<FACTORY

	/**
	 * @phpstan-param {$pascal}Fields \$fields
	 */
	public static function $method(array \$fields) : Packet{
		return Packet::fromFields(PacketId::$constant, \$fields);
	}

FACTORY;
			$direction = $packet["direction"] ?? null;
			if(!is_int($direction)){
				throw new \RuntimeException("Expected the direction of $name to be an int");
			}
			if(($direction & Protocol::SERVERBOUND) !== 0){
				$handlers .= "\t\tPacketId::$constant => \"handle$pascal\",\n";
			}
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
 * Factories for every packet of protocol $protocol (Minecraft $minecraft), with the field shapes Bedwire\\Protocol decodes
 * to and encodes from.
 * This class is generated from Bedwire\\Protocol::schema() by build/codegen/protocol-packets.php. Do not edit it manually.
 *
$aliases */
final class Packets{
	public const PROTOCOL = $protocol;
	public const SCHEMA_HASH = "$hash";

	/**
	 * Handler methods for packets clients may send.
	 */
	public const HANDLERS = [
$handlers	];

	private function __construct(){
		//NOOP
	}
$factories}

CLASS;
	}
}

if(!extension_loaded("bedwire")){
	throw new \RuntimeException("ext-bedwire is required to generate the packet factories");
}
$output = dirname(__DIR__, 2) . "/generated/network/mcpe/protocol/Packets.php";
if(!@mkdir(dirname($output), recursive: true) && !is_dir(dirname($output))){
	throw new \RuntimeException("Couldn't create directory: " . dirname($output));
}
file_put_contents($output, (new Generator(Protocol::schema()))->generate());

echo "Done generating Packets.\n";
