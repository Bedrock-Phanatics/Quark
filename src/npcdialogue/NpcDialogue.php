<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\npcdialogue;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use quark\entity\Entity;
use quark\entity\Skin;
use quark\math\Vector3;
use quark\player\Player;
use quark\plugin\Plugin;
use function count;
use function is_finite;
use function json_encode;
use function mb_check_encoding;
use function preg_match;
use function str_contains;
use function strlen;
use const JSON_THROW_ON_ERROR;

final class NpcDialogue{
	public const int MAX_BUTTONS = 6;

	private string $name = "NPC";
	private string $text = "";
	/** @var list<array{string, (\Closure(Player): void)|null}> */
	private array $buttons = [];
	/** @var (\Closure(Player): void)|null */
	private ?\Closure $onClose = null;
	private ?string $actionJson = null;
	private string $entityType = EntityIds::NPC;
	private int $variant = 0;
	private ?Skin $skin = null;
	private ?string $offsetJson = null;

	private function __construct(private readonly Plugin $owner){}

	public static function create(Plugin $owner) : self{
		return new self($owner);
	}

	public function setName(string $name) : self{
		self::validateText($name, 256);
		$this->name = $name;
		return $this;
	}

	public function setText(string $text) : self{
		self::validateText($text, 8192);
		$this->text = $text;
		return $this;
	}

	/** @param (\Closure(Player): void)|null $callback */
	public function addButton(string $label, ?\Closure $callback = null) : self{
		if(count($this->buttons) >= self::MAX_BUTTONS){
			throw new \InvalidArgumentException("NPC dialogues support at most " . self::MAX_BUTTONS . " buttons");
		}
		self::validateText($label, 256);
		$this->buttons[] = [$label, $callback];
		$this->actionJson = null;
		return $this;
	}

	public function clearButtons() : self{
		$this->buttons = [];
		$this->actionJson = null;
		return $this;
	}

	/** @param (\Closure(Player): void)|null $callback */
	public function onClose(?\Closure $callback) : self{
		$this->onClose = $callback;
		return $this;
	}

	public function setNpcTexture(int $variant = 0) : self{
		if($variant < 0 || $variant >= 60){
			throw new \InvalidArgumentException("NPC texture index must be between 0 and 59");
		}
		$this->entityType = EntityIds::NPC;
		$this->variant = $variant;
		$this->skin = null;
		return $this;
	}

	public function setEntityTexture(string $entityType) : self{
		if($entityType === EntityIds::PLAYER || strlen($entityType) > 128 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $entityType) !== 1){
			throw new \InvalidArgumentException("Expected a namespaced entity type other than minecraft:player");
		}
		$this->entityType = $entityType;
		$this->variant = 0;
		$this->skin = null;
		return $this;
	}

	public function setSkin(Skin $skin) : self{
		$this->entityType = EntityIds::PLAYER;
		$this->variant = 0;
		$this->skin = $skin;
		return $this;
	}

	public function setPortraitOffsets(Vector3 $translation, ?Vector3 $scale = null) : self{
		$translate = [$translation->x, $translation->y, $translation->z];
		$scaling = [$scale->x ?? 1, $scale->y ?? 1, $scale->z ?? 1];
		foreach([...$translate, ...$scaling] as $value){
			if(!is_finite($value) || $value < -1024 || $value > 1024){
				throw new \InvalidArgumentException("Portrait offsets must be finite and between -1024 and 1024");
			}
		}
		$this->offsetJson = json_encode([
			"picker_offsets" => ["scale" => [0, 0, 0], "translate" => [0, 0, 0]],
			"portrait_offsets" => ["scale" => $scaling, "translate" => $translate],
		], JSON_THROW_ON_ERROR);
		return $this;
	}

	/** Opens a dialogue and replaces any current dialogue for the player. */
	public function send(Player $player, ?Entity $npc = null) : ?NpcDialogueSession{
		if(!$player->isConnected()){
			return null;
		}
		return $player->getNetworkSession()->getNpcDialogueController()->open($this, $npc);
	}

	private static function validateText(string $text, int $limit) : void{
		if(strlen($text) > $limit || str_contains($text, "\0") || !mb_check_encoding($text, "UTF-8")){
			throw new \InvalidArgumentException("Expected valid UTF-8 without NUL bytes and at most $limit bytes");
		}
	}

	/** @internal */
	public function getOwner() : Plugin{ return $this->owner; }

	/** @internal */
	public function getName() : string{ return $this->name; }

	/** @internal */
	public function getText() : string{ return $this->text; }

	/** @internal */
	public function getEntityType() : string{ return $this->entityType; }

	/** @internal */
	public function getVariant() : int{ return $this->variant; }

	/** @internal */
	public function getSkin() : ?Skin{ return $this->skin; }

	/** @internal */
	public function getOffsetJson() : ?string{ return $this->offsetJson; }

	/** @internal */
	public function getActionJson() : string{
		if($this->actionJson === null){
			$actions = [];
			foreach($this->buttons as [$label]){
				$actions[] = ["button_name" => $label, "text" => "", "data" => null, "mode" => 0, "type" => 1];
			}
			$this->actionJson = json_encode($actions, JSON_THROW_ON_ERROR);
		}
		return $this->actionJson;
	}

	/** @internal */
	public function hasButton(int $index) : bool{ return isset($this->buttons[$index]); }

	/** @internal */
	public function invokeButton(Player $player, int $index) : void{
		$callback = $this->buttons[$index][1];
		if($callback !== null){
			$callback($player);
		}
	}

	/** @internal */
	public function invokeClose(Player $player) : void{
		if($this->onClose !== null){
			($this->onClose)($player);
		}
	}
}
