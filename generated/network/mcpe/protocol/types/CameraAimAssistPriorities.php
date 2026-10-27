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

namespace quark\network\mcpe\protocol\types;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class CameraAimAssistPriorities{
	/**
	 * @param list<CameraAimAssistPrioritiesEntitiesEntry> $entities
	 * @param list<CameraAimAssistPrioritiesBlocksEntry> $blocks
	 * @param list<CameraAimAssistPrioritiesBlockTagsEntry> $blockTags
	 * @param list<CameraAimAssistPrioritiesEntityTypeFamiliesEntry> $entityTypeFamilies
	 */
	public function __construct(
		public array $entities,
		public array $blocks,
		public array $blockTags,
		public array $entityTypeFamilies,
		public ?int $entityDefault = null,
		public ?int $blockDefault = null,
	){}
}
