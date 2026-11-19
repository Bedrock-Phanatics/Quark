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

namespace quark\world\metadata;

use quark\plugin\Plugin;
use function preg_match;
use function str_replace;
use function strlen;
use function strtolower;

/**
 * A plugin-owned name for metadata values of one type. Values are stored under "namespace:path", where the namespace is
 * the owning plugin's name, so plugins can't overwrite each other's data. Create keys once and keep them.
 *
 * @template T
 */
final class MetadataKey{
	public const FLAG_NOT_COPYABLE = 1 << 2;
	public const RETENTION_MASK = 0b11;
	/** Keeps every name, namespace:path, within the single length byte records give it. */
	public const MAX_NAMESPACE_LENGTH = 128;

	private const RESERVED_NAMESPACES = ["quark" => true, "minecraft" => true];

	/**
	 * Namespace => name of the plugin that owns it. Only names are kept, so a disabled plugin isn't held on to.
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private static array $owners = [];

	/**
	 * @phpstan-param MetadataCodec<T> $codec
	 */
	private function __construct(
		public readonly string $name,
		public readonly MetadataCodec $codec,
		public readonly Retention $retention,
		public readonly int $flags
	){}

	/**
	 * $copyable = false keeps values that must stay unique, such as an ownership ID, out of copies; moves still carry them.
	 *
	 * @phpstan-template TValue
	 * @phpstan-param MetadataCodec<TValue> $codec
	 * @phpstan-return self<TValue>
	 */
	public static function create(Plugin $plugin, string $path, MetadataCodec $codec, Retention $retention, bool $copyable = true) : self{
		$pluginName = $plugin->getName();
		$namespace = self::namespaceOf($pluginName);
		if(strlen($namespace) > self::MAX_NAMESPACE_LENGTH){
			throw new \InvalidArgumentException("Metadata namespace \"$namespace\" is longer than " . self::MAX_NAMESPACE_LENGTH . " bytes");
		}
		if(isset(self::RESERVED_NAMESPACES[$namespace])){
			throw new \InvalidArgumentException("Metadata namespace \"$namespace\" is reserved");
		}
		if(preg_match('/^[a-z0-9_.\/-]{1,64}$/D', $path) !== 1){
			throw new \InvalidArgumentException("Metadata key path \"$path\" must be 1-64 characters of a-z, 0-9, _ . / -");
		}
		$owner = self::$owners[$namespace] ??= $pluginName;
		if($owner !== $pluginName){
			throw new \InvalidArgumentException("Metadata namespace \"$namespace\" already belongs to plugin $owner");
		}
		return new self("$namespace:$path", $codec, $retention, $retention->value | ($copyable ? 0 : self::FLAG_NOT_COPYABLE));
	}

	/**
	 * The namespace a plugin's keys are stored under, which is also what {@see WorldMetadata::removeNamespace()} takes.
	 */
	public static function namespaceOf(string $pluginName) : string{
		return str_replace(" ", "_", strtolower($pluginName));
	}

	/**
	 * Retention stored with a value, which is all a world knows about a key whose plugin isn't loaded. Unknown kinds
	 * count as position-bound, so nothing is removed by a rule this version doesn't understand.
	 */
	public static function retentionOf(int $flags) : Retention{
		return Retention::tryFrom($flags & self::RETENTION_MASK) ?? Retention::POSITION;
	}
}
