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

namespace quark\event;

/**
 * @internal
 * @phpstan-template TEvent of Event
 */
final class RegisteredListenerCache{

	/**
	 * List of all handlers that will be called for a particular event, ordered by execution order.
	 *
	 * @var RegisteredListener[]
	 * @phpstan-var list<RegisteredListener<TEvent>>
	 */
	public ?array $list = null;
}
