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

namespace quark\build\generate_protocol_classes;

use Bedwire\Protocol;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function array_flip;
use function array_key_last;
use function array_pop;
use function array_push;
use function count;
use function dirname;
use function extension_loaded;
use function file_put_contents;
use function glob;
use function hash;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function json_encode;
use function lcfirst;
use function mkdir;
use function sort;
use function str_replace;
use function strlen;
use function strrpos;
use function strtoupper;
use function substr;
use function ucwords;
use function unlink;
use const JSON_THROW_ON_ERROR;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const PACKET_NAMESPACE = "quark\\network\\mcpe\\protocol\\packet";
const TYPE_NAMESPACE = "quark\\network\\mcpe\\protocol\\types";
const PACKET_BASE = "quark\\network\\mcpe\\protocol\\Packet";

/** Structs that already have a math class with the same public properties. */
const MATH_CLASSES = [
	"Vec3f" => Vector3::class,
	"BlockPosition" => Vector3::class,
	"Vec2f" => Vector2::class,
];

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

function namespaceOf(string $class) : string{
	$separator = strrpos($class, "\\");
	if($separator === false){
		throw new \RuntimeException("$class has no namespace");
	}
	return substr($class, 0, $separator);
}

function shortName(string $class) : string{
	return substr($class, strlen(namespaceOf($class)) + 1);
}

/**
 * The PHP declaration of one property: the native type and, when PHPStan needs more, a PHPDoc type.
 */
final class PropertyType{
	public function __construct(
		public readonly string $native,
		public readonly ?string $doc = null
	){}

	public function docOrNative() : string{
		return $this->doc ?? $this->native;
	}
}

/**
 * Generates a class for every packet and struct of Bedwire\Protocol::schema(), which Bedwire\Protocol::bind() then
 * decodes into and encodes from by property slot. Unions and recursive structs stay arrays.
 */
final class Generator{
	/** @phpstan-var array<string, array<mixed>> */
	private array $types;
	/** @phpstan-var array<string, true> */
	private array $recursive = [];
	/** @phpstan-var array<string, string> struct names mapped to the packet a packet with only that struct is flattened into */
	private array $flattened = [];
	/** @phpstan-var array<string, string> imports of the file being generated, by short name */
	private array $imports = [];

	/**
	 * @param array<mixed> $schema
	 */
	public function __construct(private array $schema){
		$this->types = [];
		$references = [];
		$uses = [];
		foreach(Utils::promoteKeys(table($schema["types"] ?? null, "types")) as $name => $type){
			$name = (string) $name;
			$type = table($type, "type $name");
			$this->types[$name] = $type;
			$references[$name] = [];
			foreach(table($type["fields"] ?? $type["variants"] ?? [], "members of $name") as $member){
				self::collect($member, $references[$name]);
			}
			foreach($references[$name] as $used){
				$uses[$used] = ($uses[$used] ?? 0) + 1;
			}
		}
		$packetFields = [];
		foreach(Utils::promoteKeys(table($schema["packets"] ?? null, "packets")) as $name => $packet){
			$fields = table(table($packet, "packet $name")["fields"] ?? null, "fields of $name");
			$packetFields[(string) $name] = $fields;
			$direct = [];
			foreach($fields as $field){
				self::collect($field, $direct);
			}
			foreach($direct as $used){
				$uses[$used] = ($uses[$used] ?? 0) + 1;
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

		foreach(Utils::stringifyKeys($packetFields) as $name => $fields){
			if(count($fields) !== 1){
				continue;
			}
			$field = $fields[array_key_last($fields)];
			if(!is_array($field) || ($field[0] ?? null) !== "type"){
				continue;
			}
			$inner = str($field[1] ?? null, "type name");
			if($this->kind($inner) === "struct" && ($uses[$inner] ?? 0) === 1 && !isset($this->recursive[$inner]) && !isset(MATH_CLASSES[$inner])){
				$this->flattened[$inner] = $name;
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

	private function kind(string $type) : string{
		return str(($this->types[$type] ?? throw new \RuntimeException("Unknown type $type"))["kind"] ?? null, "kind of $type");
	}

	public static function packetClass(string $packet) : string{
		return PACKET_NAMESPACE . "\\" . pascal($packet) . "Packet";
	}

	private function structClass(string $name) : string{
		if(isset(MATH_CLASSES[$name])){
			return MATH_CLASSES[$name];
		}
		if(isset($this->flattened[$name])){
			return self::packetClass($this->flattened[$name]);
		}
		return TYPE_NAMESPACE . "\\" . $name;
	}

	private function import(string $class) : string{
		$short = shortName($class);
		if(isset($this->imports[$short]) && $this->imports[$short] !== $class){
			return "\\" . $class;
		}
		$this->imports[$short] = $class;
		return $short;
	}

	private function uses(string $namespace) : string{
		$imports = [];
		foreach($this->imports as $import){
			if(namespaceOf($import) !== $namespace){
				$imports[] = $import;
			}
		}
		sort($imports);
		$uses = "";
		foreach($imports as $import){
			$uses .= "use $import;\n";
		}
		return $uses !== "" ? "\n" . $uses : "";
	}

	private function type(mixed $type) : PropertyType{
		if(is_string($type)){
			return new PropertyType($type);
		}
		$type = table($type, "a field type");
		$kind = str($type[0] ?? null, "type kind");
		switch($kind){
			case "optional":
				$inner = $this->type($type[1] ?? null);
				if($inner->native === "null" || $inner->native[0] === "?"){
					return $inner;
				}
				return new PropertyType("?" . $inner->native, $inner->doc !== null ? $inner->doc . "|null" : null);
			case "list":
				return new PropertyType("array", "list<" . $this->type($type[1] ?? null)->docOrNative() . ">");
			case "bytes":
			case "bits":
				return new PropertyType("string");
			case "type":
				$name = str($type[1] ?? null, "type name");
				if(isset($this->recursive[$name])){
					return new PropertyType("array", "array<mixed>");
				}
				return match($this->kind($name)){
					"struct" => new PropertyType($this->import($this->structClass($name))),
					"union" => new PropertyType("array", $this->union($name)),
					"enum" => new PropertyType("int"),
					default => throw new \RuntimeException("Unknown kind of $name")
				};
			default:
				throw new \RuntimeException("Unknown schema type $kind");
		}
	}

	private function union(string $name) : string{
		$parts = [];
		foreach(Utils::promoteKeys(table($this->types[$name]["variants"] ?? null, "variants of $name")) as $variant => $type){
			$parts[] = "array{" . $variant . ": " . $this->type($type)->docOrNative() . "}";
		}
		return implode("|", $parts);
	}

	/**
	 * @param array<mixed> $fields
	 */
	private function classFile(string $class, array $fields, ?string $packetConstant) : string{
		$this->imports = [];
		$namespace = namespaceOf($class);
		$short = shortName($class);
		$this->imports[$short] = $class;

		$properties = [];
		foreach(Utils::promoteKeys($fields) as $field => $type){
			$properties[lcfirst(pascal((string) $field))] = $this->type($type);
		}
		$required = 0;
		$i = 0;
		foreach($properties as $property){
			++$i;
			if($property->native[0] !== "?" && $property->native !== "null"){
				$required = $i;
			}
		}

		$docs = [];
		$parameters = [];
		$i = 0;
		foreach(Utils::stringifyKeys($properties) as $name => $property){
			++$i;
			if($property->doc !== null){
				$docs[] = "\t * @param {$property->doc} \$$name";
			}
			$default = $i > $required ? " = null" : "";
			$parameters[] = "\t\tpublic {$property->native} \$$name$default,";
		}

		$body = "";
		$extends = "";
		if($packetConstant !== null){
			$this->import("Bedwire\\PacketId");
			$extends = " extends " . $this->import(PACKET_BASE);
			$body .= "\tpublic const int ID = PacketId::$packetConstant;\n";
		}
		if(count($parameters) > 0){
			$body .= ($body !== "" ? "\n" : "") . (count($docs) > 0 ? "\t/**\n" . implode("\n", $docs) . "\n\t */\n" : "");
			$body .= "\tpublic function __construct(\n" . implode("\n", $parameters) . "\n\t){}\n";
		}
		if($packetConstant !== null){
			$body .= "\n\tpublic function getId() : int{\n\t\treturn self::ID;\n\t}\n";
		}

		$uses = $this->uses($namespace);

		return Filesystem::fileGetContents(__DIR__ . "/templates/header.php") . <<<CLASS

namespace $namespace;
{$uses}
/**
 * This class is generated from Bedwire\\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class $short$extends{
$body}

CLASS;
	}

	/**
	 * @return array<string, string> file contents by path relative to the generated namespace root
	 */
	public function generate() : array{
		$files = [];
		$bindings = [];
		$flatInner = array_flip($this->flattened);
		foreach(Utils::promoteKeys(table($this->schema["packets"] ?? null, "packets")) as $name => $packet){
			$name = (string) $name;
			$fields = table(table($packet, "packet $name")["fields"] ?? null, "fields of $name");
			$inner = $flatInner[$name] ?? null;
			if($inner !== null){
				$fields = table($this->types[$inner]["fields"] ?? null, "fields of $inner");
			}
			$class = self::packetClass($name);
			$files["packet/" . pascal($name) . "Packet.php"] = $this->classFile($class, $fields, strtoupper($name));
			$bindings[$name] = $class;
		}
		foreach(Utils::stringifyKeys($this->types) as $name => $type){
			if(($type["kind"] ?? null) !== "struct" || isset($this->recursive[$name]) || isset($this->flattened[$name])){
				continue;
			}
			if(isset(MATH_CLASSES[$name])){
				$bindings[$name] = MATH_CLASSES[$name];
				continue;
			}
			$class = $this->structClass($name);
			$files["types/$name.php"] = $this->classFile($class, table($type["fields"] ?? null, "fields of $name"), null);
			$bindings[$name] = $class;
		}

		$entries = "";
		foreach(Utils::stringifyKeys($bindings) as $name => $class){
			$entries .= "\t\t\"$name\" => \\$class::class,\n";
		}
		$hash = hash("sha256", json_encode($this->schema, JSON_THROW_ON_ERROR));
		$files["ProtocolClasses.php"] = Filesystem::fileGetContents(__DIR__ . "/templates/header.php") . <<<CLASS

namespace quark\\network\\mcpe\\protocol;

use Bedwire\\Protocol;

/**
 * The classes Bedwire\\Protocol::bind() is given, by schema name.
 * This class is generated from Bedwire\\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ProtocolClasses{
	public const string SCHEMA_HASH = "$hash";

	/** @phpstan-var array<string, class-string> */
	public const array BINDINGS = [
$entries	];

	private static bool \$bound = false;

	private function __construct(){
		//NOOP
	}

	/**
	 * Binds the classes on this thread, which every thread must do before it encodes or decodes packet classes.
	 */
	public static function bind() : void{
		if(!self::\$bound){
			Protocol::bind(self::BINDINGS);
			self::\$bound = true;
		}
	}
}

CLASS;
		return $files;
	}
}

if(!extension_loaded("bedwire")){
	throw new \RuntimeException("ext-bedwire is required to generate the packet classes");
}
$root = dirname(__DIR__, 2) . "/generated/network/mcpe/protocol";
foreach(["packet", "types"] as $directory){
	if(!@mkdir("$root/$directory", recursive: true) && !is_dir("$root/$directory")){
		throw new \RuntimeException("Couldn't create directory: $root/$directory");
	}
	$stale = glob("$root/$directory/*.php");
	if($stale === false){
		throw new \RuntimeException("Couldn't list $root/$directory");
	}
	foreach($stale as $file){
		unlink($file);
	}
}
foreach(Utils::stringifyKeys((new Generator(Protocol::schema()))->generate()) as $path => $contents){
	file_put_contents("$root/$path", $contents);
}

echo "Done generating packet classes.\n";
