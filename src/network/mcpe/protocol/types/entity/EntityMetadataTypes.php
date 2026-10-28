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

namespace quark\network\mcpe\protocol\types\entity;

final class EntityMetadataTypes{

	private function __construct(){
		//NOOP
	}

	public const BYTE = 0;
	public const SHORT = 1;
	public const INT = 2;
	public const FLOAT = 3;
	public const STRING = 4;
	public const COMPOUND_TAG = 5;
	public const POS = 6;
	public const LONG = 7;
	public const VECTOR3F = 8;
}
