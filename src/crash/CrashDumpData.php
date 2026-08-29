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

namespace quark\crash;

final class CrashDumpData implements \JsonSerializable{

	public int $format_version;

	public float $time;

	public float $uptime;

	/** @var mixed[] */
	public array $lastError = [];

	/** @var mixed[] */
	public array $error;

	public string $thread;

	public string $plugin_involvement;

	public string $plugin = "";

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	public array $code = [];

	/** @var string[] */
	public array $trace;

	/**
	 * @var CrashDumpDataPluginEntry[]
	 * @phpstan-var array<string, CrashDumpDataPluginEntry>
	 */
	public array $plugins = [];

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $parameters = [];

	public string $serverDotProperties = "";

	public string $quarkDotYml = "";

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	public array $extensions = [];

	public ?int $jit_mode = null;

	public string $phpinfo = "";

	public CrashDumpDataGeneral $general;

	/**
	 * @return mixed[]
	 */
	public function jsonSerialize() : array{
		$result = (array) $this;
		unset($result["serverDotProperties"]);
		unset($result["quarkDotYml"]);
		$result["quark.yml"] = $this->quarkDotYml;
		$result["server.properties"] = $this->serverDotProperties;
		return $result;
	}
}
