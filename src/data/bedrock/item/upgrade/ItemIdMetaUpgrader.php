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

namespace quark\data\bedrock\item\upgrade;

use function ksort;
use const SORT_NUMERIC;

/**
 * Upgrades old item string IDs and metas to newer ones according to the given schemas.
 */
final class ItemIdMetaUpgrader{

	/**
	 * @var ItemIdMetaUpgradeSchema[]
	 * @phpstan-var array<int, ItemIdMetaUpgradeSchema>
	 */
	private array $idMetaUpgradeSchemas = [];

	/**
	 * @param ItemIdMetaUpgradeSchema[] $idMetaUpgradeSchemas
	 * @phpstan-param array<int, ItemIdMetaUpgradeSchema> $idMetaUpgradeSchemas
	 */
	public function __construct(
		array $idMetaUpgradeSchemas,
	){
		foreach($idMetaUpgradeSchemas as $schema){
			$this->addSchema($schema);
		}
	}

	public function addSchema(ItemIdMetaUpgradeSchema $schema) : void{
		if(isset($this->idMetaUpgradeSchemas[$schema->getSchemaId()])){
			throw new \InvalidArgumentException("Already have a schema with priority " . $schema->getSchemaId());
		}
		$this->idMetaUpgradeSchemas[$schema->getSchemaId()] = $schema;
		ksort($this->idMetaUpgradeSchemas, SORT_NUMERIC);
	}

	/**
	 * @return ItemIdMetaUpgradeSchema[]
	 * @phpstan-return array<int, ItemIdMetaUpgradeSchema>
	 */
	public function getSchemas() : array{ return $this->idMetaUpgradeSchemas; }

	/**
	 * @phpstan-return array{string, int}
	 */
	public function upgrade(string $id, int $meta) : array{
		$newId = $id;
		$newMeta = $meta;
		foreach($this->idMetaUpgradeSchemas as $schema){
			if(($remappedMetaId = $schema->remapMeta($newId, $newMeta)) !== null){
				$newId = $remappedMetaId;
				$newMeta = 0;
			}elseif(($renamedId = $schema->renameId($newId)) !== null){
				$newId = $renamedId;
			}
		}

		return [$newId, $newMeta];
	}
}
