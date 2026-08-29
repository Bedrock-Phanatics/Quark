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

namespace quark\data\bedrock\block\convert\property;

use quark\data\bedrock\block\convert\BlockStateReader;
use quark\data\bedrock\block\convert\BlockStateWriter;
use quark\utils\AssumptionFailedError;
use function is_bool;
use function is_int;
use function is_string;

/**
 * @phpstan-implements Property<object>
 */
final class DummyProperty implements Property{
	public function __construct(
		private string $name,
		private bool|int|string $value
	){}

	public function getName() : string{
		return $this->name;
	}

	public function deserialize(object $block, BlockStateReader $in) : void{
		$in->ignored($this->name);
	}

	public function serialize(object $block, BlockStateWriter $out) : void{
		if(is_bool($this->value)){
			$out->writeBool($this->name, $this->value);
		}elseif(is_int($this->value)){
			$out->writeInt($this->name, $this->value);
		}elseif(is_string($this->value)){
			$out->writeString($this->name, $this->value);
		}else{
			throw new AssumptionFailedError();
		}
	}
}
