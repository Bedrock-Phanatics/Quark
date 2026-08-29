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

namespace quark\plugin;

final class PluginDescriptionCommandEntry{

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	public function __construct(
		private ?string $description,
		private ?string $usageMessage,
		private array $aliases,
		private string $permission,
		private ?string $permissionDeniedMessage,
	){}

	public function getDescription() : ?string{ return $this->description; }

	public function getUsageMessage() : ?string{ return $this->usageMessage; }

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getAliases() : array{ return $this->aliases; }

	public function getPermission() : string{ return $this->permission; }

	public function getPermissionDeniedMessage() : ?string{ return $this->permissionDeniedMessage; }
}
