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

namespace quark\item;

use quark\block\utils\RecordType;

class Record extends Item{
	private RecordType $recordType;

	//TODO: inconsistent parameter order
	public function __construct(ItemIdentifier $identifier, RecordType $recordType, string $name){
		$this->recordType = $recordType;
		parent::__construct($identifier, $name);
	}

	public function getRecordType() : RecordType{
		return $this->recordType;
	}

	public function getMaxStackSize() : int{
		return 1;
	}
}
