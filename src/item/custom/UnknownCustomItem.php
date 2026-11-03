<?php

declare(strict_types=1);

namespace quark\item\custom;

use pocketmine\nbt\tag\CompoundTag;
use quark\item\Item;
use quark\item\ItemIdentifier;

/**
 * Inert disk-only placeholder. Restoring the defining plugin restores the original item on the next load.
 */
final class UnknownCustomItem extends Item{
	public const string IDENTITY_TAG = "___QuarkMissingItem___";

	/** @internal */
	public function __construct(ItemIdentifier $id, private readonly string $savedIdentifier, private readonly int $savedMeta){
		parent::__construct($id, "Unavailable item ($savedIdentifier)");
	}

	public function getSavedIdentifier() : string{ return $this->savedIdentifier; }

	public function getSavedMeta() : int{ return $this->savedMeta; }

	protected function deserializeCompoundTag(CompoundTag $tag) : void{}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		$tag->setString(self::IDENTITY_TAG, $this->savedIdentifier . ":" . $this->savedMeta);
	}
}
