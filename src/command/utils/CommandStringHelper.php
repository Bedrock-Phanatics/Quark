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

namespace quark\command\utils;

use quark\utils\AssumptionFailedError;
use function preg_last_error_msg;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function strlen;
use const PREG_OFFSET_CAPTURE;

final class CommandStringHelper{

	private function __construct(){
		//NOOP
	}

	/**
	 * Parses a command string into its component parts. Parts of the string which are inside unescaped quotes are
	 * considered as one argument.
	 *
	 * Examples:
	 * - `give "steve jobs" apple` -> ['give', 'steve jobs', 'apple']
	 * - `say "This is a \"string containing quotes\""` -> ['say', 'This is a "string containing quotes"']
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function parseQuoteAware(string $commandLine) : array{
		$args = [];
		preg_match_all('/"((?:\\\\.|[^\\\\"])*)"|(\S+)/u', $commandLine, $matches);
		foreach($matches[0] as $k => $_){
			for($i = 1; $i <= 2; ++$i){
				if($matches[$i][$k] !== ""){
					$match = $matches[$i][$k];
					$args[] = preg_replace('/\\\\([\\\\"])/u', '$1', $match) ?? throw new AssumptionFailedError(preg_last_error_msg());
					break;
				}
			}
		}

		return $args;
	}

	/**
	 * Finds where the token at $index starts in the raw command line, counting tokens the same way as
	 * {@link CommandStringHelper::parseQuoteAware()}. Returns null if there is no such token.
	 */
	public static function tokenOffset(string $commandLine, int $index) : ?int{
		$position = 0;
		$seen = 0;
		while(preg_match('/"((?:\\\\.|[^\\\\"])*)"|(\S+)/u', $commandLine, $match, PREG_OFFSET_CAPTURE, $position) === 1){
			$position = $match[0][1] + strlen($match[0][0]);
			if($match[1][0] === "" && ($match[2][0] ?? "") === ""){
				continue;
			}
			if($seen === $index){
				return $match[0][1];
			}
			++$seen;
		}
		return null;
	}
}
