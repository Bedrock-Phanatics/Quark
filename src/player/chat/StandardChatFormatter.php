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

namespace quark\player\chat;

use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;

/**
 * Standard chat formatter, formats messages in the default Minecraft way.
 */
final class StandardChatFormatter implements ChatFormatter{

	public function format(string $username, string $message) : Translatable{
		return KnownTranslationFactory::chat_type_text($username, $message);
	}
}
