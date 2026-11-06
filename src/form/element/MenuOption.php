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

use JsonSerializable;

/**
 * Represents an option on a MenuForm. The option is shown as a button and may optionally have an image next to it.
 */
class MenuOption implements JsonSerializable{

	private string $text;
	private ?FormIcon $image;

	public function __construct(string $text, ?FormIcon $image = null){
		$this->text = $text;
		$this->image = $image;
	}

	public function getText() : string{
		return $this->text;
	}

	public function hasImage() : bool{
		return $this->image !== null;
	}

	public function getImage() : ?FormIcon{
		return $this->image;
	}

	/** @return array{text: string, image?: FormIcon} */
	public function jsonSerialize() : array{
		$json = [
			"text" => $this->text
		];

		if($this->image !== null){
			$json["image"] = $this->image;
		}

		return $json;
	}
}
