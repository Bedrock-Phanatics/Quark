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

namespace quark\form\element;

use ReturnTypeWillChange;

/**
 * Represents an icon which can be placed next to options on menus, or as the icon for the server-settings form type.
 */
class FormIcon implements \JsonSerializable{
	public const string IMAGE_TYPE_URL = "url";
	public const string IMAGE_TYPE_PATH = "path";

	/** @var string */
	private string $type;
	/** @var string */
	private string $data;

	/**
	 * @param string $data URL or path depending on the type chosen.
	 * @param string $type Can be one of the constants at the top of the file, but only "url" is known to work.
	 */
	public function __construct(string $data, string $type = self::IMAGE_TYPE_URL){
		$this->type = $type;
		$this->data = $data;
	}

	public function getType() : string{
		return $this->type;
	}

	public function getData() : string{
		return $this->data;
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() : mixed{
		return [
			"type" => $this->type,
			"data" => $this->data
		];
	}
}
