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

namespace quark\command\argument;

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use function array_flip;
use function array_is_list;
use function count;
use function implode;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * @phpstan-extends Argument<string>
 */
final class EnumArgument extends Argument{
	/** @phpstan-var array<int|string, int> */
	private array $lookup;

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 * @throws \InvalidArgumentException
	 */
	public function __construct(string $name, private array $values, bool $optional = false){
		if(count($values) === 0 || !array_is_list($values)){
			throw new \InvalidArgumentException("Enum values must be a non-empty list");
		}
		foreach($values as $value){
			if(!is_string($value) || preg_match('/\A[a-z0-9_-]+\z/', $value) !== 1){
				throw new \InvalidArgumentException("Enum values must be lowercase words");
			}
		}
		$this->lookup = array_flip($values);
		if(count($this->lookup) !== count($values)){
			throw new \InvalidArgumentException("Enum values must be unique");
		}
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : string{
		$value = strtolower($input);
		if(!isset($this->lookup[$value])){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidEnum($input, implode(", ", $this->values)));
		}
		return $value;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::ENUM;
	}

	public function getEnumValues() : array{
		return $this->values;
	}
}
