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

namespace quark\network\mcpe\command;

use pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket;
use quark\command\argument\SoftEnum;

/**
 * Turns pending soft enum changes into update packets for one client.
 *
 * @internal
 */
final class SoftEnumUpdates{

	private function __construct(){
		//NOOP
	}

	/**
	 * Only enums the client already received are updated. A client that is not exactly at the start of a batch, for
	 * example because its command list was built after the change, gets the whole list instead of a delta.
	 *
	 * @param int[]     $known   soft enum versions the client has, updated in place
	 * @param mixed[][] $changes
	 * @phpstan-param array<string, int> $known
	 * @phpstan-param list<array{SoftEnum, list<array{int, list<string>}>, int, int}> $changes
	 * @return UpdateSoftEnumPacket[]
	 * @phpstan-return list<UpdateSoftEnumPacket>
	 */
	public static function createPackets(array &$known, array $changes) : array{
		$packets = [];
		foreach($changes as [$softEnum, $pending, $since, $until]){
			$name = $softEnum->getName();
			$version = $known[$name] ?? null;
			if($version === null || $version >= $until){
				continue;
			}
			if($version !== $since){
				$pending = [[SoftEnum::TYPE_SET, $softEnum->getValues()]];
			}
			foreach($pending as [$type, $values]){
				$packets[] = UpdateSoftEnumPacket::create($name, $values, $type);
			}
			$known[$name] = $until;
		}
		return $packets;
	}
}
