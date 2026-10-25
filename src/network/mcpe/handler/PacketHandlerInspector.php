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
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use quark\network\mcpe\protocol\Packets;
use quark\utils\AssumptionFailedError;
use function array_flip;
use function array_keys;
use function array_map;
use function constant;
use function implode;
use function is_a;
use function is_int;
use function sort;
use function str_starts_with;
use const SORT_STRING;

/**
 * Uses reflection to find out what packets a PacketHandler class type will actually process, so that decoding can be
 * skipped for packets that are never handled.
 * The reflected information is cached for subsequent use.
 */
final class PacketHandlerInspector{

	/** @phpstan-var array<class-string<PacketHandler>, array{array<int, PacketHandlerAction>, array<int, array{string, bool}>}> */
	private static array $cache = [];

	/**
	 * Returns what to do with each packet ID, and for each handled one, the method handling it and whether that method
	 * takes decoded fields rather than a BedrockProtocol packet object.
	 *
	 * @phpstan-return array{array<int, PacketHandlerAction>, array<int, array{string, bool}>}
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
			$class = $type->getName();
			if($class === "array"){
				$id = $ids[$name] ?? throw new AssumptionFailedError($handler::class . "::$name() does not handle a packet clients send");
				$methods[$id] = [$name, true];
			}elseif(is_a($class, ServerboundPacket::class, true)){
				//TODO: remove once every handler takes decoded fields
				$id = constant($class . "::NETWORK_ID");
				if(!is_int($id)){
					throw new AssumptionFailedError("$class has no packet ID");
				}
				$methods[$id] = [$name, false];
			}else{
				throw new AssumptionFailedError($handler::class . "::$name() must take an array of packet fields");
			}
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
