<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\npcdialogue;

use quark\network\mcpe\NpcDialogueController;

final class NpcDialogueSession{
	/** @var \WeakReference<NpcDialogueController> */
	private readonly \WeakReference $controller;

	/** @internal */
	public function __construct(
		NpcDialogueController $controller,
		private ?NpcDialogue $dialogue,
		/** @internal */
		public readonly int $actorId,
		/** @internal */
		public readonly string $scene,
		/** @internal */
		public readonly bool $virtualActor
	){
		$this->controller = \WeakReference::create($controller);
	}

	private function __clone(){}

	public function isOpen() : bool{
		return $this->controller->get()?->getActive() === $this;
	}

	/** Updates the active dialogue and returns its new session. */
	public function update(NpcDialogue $dialogue) : ?self{
		return $this->controller->get()?->update($this, $dialogue);
	}

	public function close() : bool{
		return $this->controller->get()?->close($this) ?? false;
	}

	/** @internal */
	public function getDefinition() : ?NpcDialogue{
		return $this->dialogue;
	}

	/** @internal */
	public function retire() : void{
		$this->dialogue = null;
	}
}
