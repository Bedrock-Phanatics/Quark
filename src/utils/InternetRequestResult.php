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

namespace quark\utils;

final class InternetRequestResult{
	/**
	 * @param string[][] $headers
	 * @phpstan-param list<array<string, string>> $headers
	 */
	public function __construct(
		private array $headers,
		private string $body,
		private int $code
	){}

	/**
	 * @return string[][]
	 * @phpstan-return list<array<string, string>>
	 */
	public function getHeaders() : array{ return $this->headers; }

	public function getBody() : string{ return $this->body; }

	public function getCode() : int{ return $this->code; }
}
