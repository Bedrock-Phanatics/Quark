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

namespace quark\network\mcpe\handler;

use Bedwire\Protocol;
use quark\network\mcpe\protocol\Packets;
use quark\utils\AssumptionFailedError;
use function array_flip;
use function array_keys;
use function array_map;
use function implode;
use function sort;
use function str_starts_with;
use const SORT_STRING;

/**
 * Uses reflection to find out what packets a PacketHandler class type will actually process, so that decoding can be
 * skipped for packets that are never handled.
 * The reflected information is cached for subsequent use.
 */
final class PacketHandlerInspector{

	/** @phpstan-var array<class-string<PacketHandler>, array{array<int, PacketHandlerAction>, array<int, string>}> */
	private static array $cache = [];

	/**
	 * Returns what to do with each packet ID, and for each handled one, the name of the method handling it.
	 *
	 * @phpstan-return array{array<int, PacketHandlerAction>, array<int, string>}
	 */
	public static function inspect(PacketHandler $handler) : array{
		if(isset(self::$cache[$handler::class])){
			return self::$cache[$handler::class];
		}

		$ids = array_flip(Packets::HANDLERS);
		$actions = [];
		$methods = [];
		$reflect = new \ReflectionClass($handler);
		foreach($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method){
			$name = $method->getName();
			if(!str_starts_with($name, "handle") || $method->isStatic()){
				continue;
			}
			$type = ($method->getParameters()[0] ?? null)?->getType();
			if(!$type instanceof \ReflectionNamedType){
				throw new AssumptionFailedError($handler::class . "::$name() must take the packet as its first parameter");
			}
			if($type->getName() !== "array"){
				throw new AssumptionFailedError($handler::class . "::$name() must take an array of packet fields");
			}
			$id = $ids[$name] ?? throw new AssumptionFailedError($handler::class . "::$name() does not handle a packet clients send");
			$methods[$id] = $name;
			$actions[$id] = PacketHandlerAction::HANDLED;
		}

		foreach($reflect->getAttributes(SilentDiscard::class) as $attribute){
			$id = $attribute->newInstance()->packetId;
			if(isset($actions[$id])){
				\GlobalLogger::get()->warning("#[SilentDiscard(" . Protocol::name($id) . ")] has no effect on " . $handler::class . ", as the handler for it is implemented");
				continue;
			}
			$actions[$id] = PacketHandlerAction::DISCARD_SILENT;
		}

		$handled = array_map(fn(int $id) => Protocol::name($id) ?? "unknown_$id", array_keys($methods));
		sort($handled, SORT_STRING);
		\GlobalLogger::get()->debug("Packets handled by " . $handler::class . ": " . implode(', ', $handled));

		return self::$cache[$handler::class] = [$actions, $methods];
	}
}
