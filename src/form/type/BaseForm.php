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

namespace quark\form\type;

use quark\form\Form;

/**
 * Base class for a custom form. Forms are serialized to JSON data to be sent to clients.
 */
abstract class BaseForm implements Form{

	/** @var string */
	protected string $title;

	public function __construct(string $title){
		$this->title = $title;
	}

	/**
	 * Returns the text shown on the form title-bar.
	 */
	public function getTitle() : string{
		return $this->title;
	}

	/**
	 * Serializes the form to JSON for sending to clients.
	 * @return mixed[]
	 */
	final public function jsonSerialize() : array{
		$ret = $this->serializeFormData();
		$ret["type"] = $this->getType();
		$ret["title"] = $this->getTitle();

		return $ret;
	}

	/**
	 * Returns the type used to show this form to clients
	 */
	abstract protected function getType() : string;

	/**
	 * Serializes additional data needed to show this form to clients.
	 * @return mixed[]
	 */
	abstract protected function serializeFormData() : array;
}
