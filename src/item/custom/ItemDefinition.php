<?php

declare(strict_types=1);

namespace quark\item\custom;

use quark\block\BlockToolType;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeGroup;
use quark\item\Armor;
use quark\item\ArmorTypeInfo;
use quark\item\Axe;
use quark\item\enchantment\ItemEnchantmentTags;
use quark\item\Hoe;
use quark\item\Item;
use quark\item\ItemIdentifier;
use quark\item\Pickaxe;
use quark\item\Shovel;
use quark\item\ToolTier;
use quark\utils\Utils;
use function preg_match;
use function str_contains;
use function strlen;

final class ItemDefinition{
	private ItemComponents $components;
	private ?CreativeCategory $category = null;
	private ?CreativeGroup $group = null;

	/** @param \Closure(ItemIdentifier) : Item $factory */
	private function __construct(private readonly string $identifier, private readonly string $name, private readonly \Closure $factory){
		self::validateIdentifier($identifier);
		Utils::checkUTF8($name);
		if($name === "" || strlen($name) > 256){
			throw new \InvalidArgumentException("Item display name must contain 1 - 256 UTF-8 bytes");
		}
		$this->components = new ItemComponents();
	}

	public static function validateIdentifier(string $identifier) : void{
		if(strlen($identifier) > 128 || preg_match('/^[a-z0-9_][a-z0-9_.-]{0,63}:[a-z0-9_][a-z0-9_.\/-]*$/D', $identifier) !== 1 || str_contains($identifier, "..")){
			throw new \InvalidArgumentException("Invalid namespaced item identifier '$identifier'");
		}
	}

	public static function item(string $identifier, string $name, int $maxStackSize = 64) : self{
		return new self($identifier, $name, static fn(ItemIdentifier $id) => new CustomItem($id, $name, $maxStackSize));
	}

	public static function sword(string $identifier, string $name, int $damage, int $durability, ToolTier $tier = ToolTier::DIAMOND) : self{
		return new self($identifier, $name, static fn(ItemIdentifier $id) => new CustomSword($id, $name, $damage, $durability, $tier));
	}

	public static function armor(string $identifier, string $name, ArmorTypeInfo $info) : self{
		$tag = match($info->getArmorSlot()){
			0 => ItemEnchantmentTags::HELMET,
			1 => ItemEnchantmentTags::CHESTPLATE,
			2 => ItemEnchantmentTags::LEGGINGS,
			3 => ItemEnchantmentTags::BOOTS,
			default => throw new \InvalidArgumentException("Invalid armor slot")
		};
		$info = clone $info;
		return new self($identifier, $name, static fn(ItemIdentifier $id) => new Armor($id, $name, $info, [$tag]));
	}

	public static function food(string $identifier, string $name, int $nutrition, float $saturation, bool $alwaysEdible = false, int $maxStackSize = 64) : self{
		return new self($identifier, $name, static fn(ItemIdentifier $id) => new CustomFood($id, $name, $nutrition, $saturation, $alwaysEdible, $maxStackSize));
	}

	public static function tool(string $identifier, string $name, int $toolType, ToolTier $tier) : self{
		$factory = match($toolType){
			BlockToolType::AXE => static fn(ItemIdentifier $id) => new Axe($id, $name, $tier, [ItemEnchantmentTags::AXE]),
			BlockToolType::PICKAXE => static fn(ItemIdentifier $id) => new Pickaxe($id, $name, $tier, [ItemEnchantmentTags::PICKAXE]),
			BlockToolType::SHOVEL => static fn(ItemIdentifier $id) => new Shovel($id, $name, $tier, [ItemEnchantmentTags::SHOVEL]),
			BlockToolType::HOE => static fn(ItemIdentifier $id) => new Hoe($id, $name, $tier, [ItemEnchantmentTags::HOE]),
			default => throw new \InvalidArgumentException("Expected an axe, pickaxe, shovel or hoe tool type")
		};
		return new self($identifier, $name, $factory);
	}

	/** @param \Closure(ItemIdentifier) : Item $factory Invoked once, then discarded by the registry. Subclasses must clone their own mutable fields. */
	public static function fromFactory(string $identifier, string $name, \Closure $factory) : self{
		return new self($identifier, $name, $factory);
	}

	public function withComponents(ItemComponents $components) : self{
		$result = clone $this;
		$result->components = $components;
		return $result;
	}

	public function withCreativeCategory(CreativeCategory $category = CreativeCategory::ITEMS, ?CreativeGroup $group = null) : self{
		$result = clone $this;
		$result->category = $category;
		$result->group = $group;
		return $result;
	}

	public function getIdentifier() : string{ return $this->identifier; }

	public function getName() : string{ return $this->name; }

	public function getComponents() : ItemComponents{ return $this->components; }

	public function getCreativeCategory() : ?CreativeCategory{ return $this->category; }

	public function getCreativeGroup() : ?CreativeGroup{ return $this->group; }

	/** @internal */
	public function createPrototype(ItemIdentifier $id) : Item{ return ($this->factory)($id); }
}
