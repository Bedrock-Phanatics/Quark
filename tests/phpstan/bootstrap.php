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

if(!defined('LEVELDB_ZLIB_RAW_COMPRESSION')){
	//leveldb might not be loaded
	define('LEVELDB_ZLIB_RAW_COMPRESSION', 4);
}
if(!extension_loaded('libdeflate')){
	function libdeflate_deflate_compress(string $data, int $level = 6) : string{}
}

//opcache breaks PHPStan when dynamic reflection is used - see https://github.com/phpstan/phpstan-src/pull/801#issuecomment-978431013
ini_set('opcache.enable', 'off');
