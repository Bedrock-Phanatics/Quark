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

namespace quark\world\particle;

use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\EntityMetadata;
use quark\network\mcpe\protocol\packet\AddActorPacket;
use quark\network\mcpe\protocol\packet\RemoveActorPacket;
use quark\network\mcpe\protocol\types\PropertySyncData;

class FloatingTextParticle implements Particle{
	//TODO: HACK!

	protected ?int $entityId = null;
	protected bool $invisible = false;

	public function __construct(
		protected string $text,
		protected string $title = ""
	){}

	public function getText() : string{
		return $this->text;
	}

	public function setText(string $text) : void{
		$this->text = $text;
	}

	public function getTitle() : string{
		return $this->title;
	}

	public function setTitle(string $title) : void{
		$this->title = $title;
	}

	public function isInvisible() : bool{
		return $this->invisible;
	}

	public function setInvisible(bool $value = true) : void{
		$this->invisible = $value;
	}

	public function encode(Vector3 $pos) : array{
		$p = [];

		if($this->entityId === null){
			$this->entityId = Entity::nextRuntimeId();
		}else{
			$p[] = new RemoveActorPacket(targetActorId: $this->entityId);
		}

		if(!$this->invisible){
			$name = $this->title . ($this->text !== "" ? "\n" . $this->text : "");

			$actorFlags = (
				1 << EntityMetadataFlags::NO_AI
			);
			$actorMetadata = new EntityMetadata();
			$actorMetadata->setLong(EntityMetadataProperties::FLAGS, $actorFlags);
			$actorMetadata->setFloat(EntityMetadataProperties::SCALE, 0.01); //zero causes problems on debug builds
			$actorMetadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.0);
			$actorMetadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 0.0);
			$actorMetadata->setString(EntityMetadataProperties::NAMETAG, $name);
			$actorMetadata->setInt(EntityMetadataProperties::VARIANT, TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId()));
			$actorMetadata->setByte(EntityMetadataProperties::ALWAYS_SHOW_NAMETAG, 1);
			$p[] = new AddActorPacket(
				targetActorId: $this->entityId, //TODO: actor unique ID
				targetRuntimeId: $this->entityId,
				actorType: EntityIds::FALLING_BLOCK,
				position: $pos, //TODO: check offset (0.49?)
				velocity: new Vector3(0.0, 0.0, 0.0),
				rotation: new Vector2(0.0, 0.0),
				yHeadRotation: 0.0,
				yBodyRotation: 0.0,
				attributesList: [],
				actorData: $actorMetadata->getAll(),
				synchedProperties: new PropertySyncData(intEntriesList: [], floatEntriesList: []),
				actorLinks: [],
			);
		}

		return $p;
	}
}
