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

namespace quark\resourcepacks\json;

final class ManifestHeader{

	public string $description;

	/** @required */
	public string $name;

	/** @required */
	public string $uuid;

	/**
	 * @var int[]
	 * @phpstan-var array{int, int, int}
	 * @required
	 */
	public array $version;

	/**
	 * @var int[]
	 * @phpstan-var array{int, int, int}
	 */
	public array $min_engine_version;
}
