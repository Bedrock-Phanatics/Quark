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

namespace quark\permission;

final class PermissibleBase implements Permissible{
	use PermissibleDelegateTrait;

	private PermissibleInternal $permissibleBase;

	/**
	 * @param bool[] $basePermissions
	 * @phpstan-param array<string, bool> $basePermissions
	 */
	public function __construct(array $basePermissions){
		$this->permissibleBase = new PermissibleInternal($basePermissions);
		$this->perm = $this->permissibleBase;
	}

	public function __destruct(){
		//permission subscriptions need to be cleaned up explicitly
		$this->permissibleBase->destroyCycles();
	}
}
