<?php

declare(strict_types=1);

namespace quark\item\custom;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use quark\inventory\CreativeCategory;
use quark\item\Armor;
use quark\item\ConsumableItem;
use quark\item\Durable;
use quark\item\FoodSourceItem;
use quark\item\Item;
use quark\item\ProjectileItem;
use quark\item\Releasable;
use quark\item\Sword;
use quark\item\Tool;
use function array_map;
use function array_unique;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function is_finite;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Immutable client configuration. Gameplay values are derived from the registered server prototype.
 */
final class ItemComponents{
	private CompoundTag $properties;
	private CompoundTag $components;

	public function __construct(){
		$this->properties = new CompoundTag();
		$this->components = new CompoundTag();
	}

	public function __clone(){
		$this->properties = clone $this->properties;
		$this->components = clone $this->components;
	}

	public function icon(string $texture) : self{
		self::text($texture, "icon texture");
		$result = clone $this;
		$result->properties->setTag("minecraft:icon", CompoundTag::create()->setString("texture", $texture)->setTag("textures", CompoundTag::create()->setString("default", $texture)));
		return $result;
	}

	public function glint(bool $enabled = true) : self{
		$result = clone $this;
		$result->properties->setByte("foil", $enabled ? 1 : 0);
		return $result;
	}

	/** @param "common"|"uncommon"|"rare"|"epic" $rarity */
	public function rarity(string $rarity) : self{
		if(!in_array($rarity, ["common", "uncommon", "rare", "epic"], true)){
			throw new \InvalidArgumentException("Unknown item rarity '$rarity'");
		}
		$result = clone $this;
		$result->properties->setString("rarity", $rarity);
		$result->components->setTag("minecraft:rarity", CompoundTag::create()->setString("value", $rarity));
		return $result;
	}

	public function tags(string ...$tags) : self{
		if(count($tags) > 64){
			throw new \InvalidArgumentException("At most 64 item tags are allowed");
		}
		foreach($tags as $tag){ ItemDefinition::validateIdentifier($tag); }
		$result = clone $this;
		$result->components->setTag("minecraft:tags", CompoundTag::create()->setTag("tags", new ListTag(array_map(static fn(string $tag) => new StringTag($tag), array_values(array_unique($tags))), NBT::TAG_String)));
		return $result;
	}

	public function useAnimation(UseAnimation $animation) : self{
		$result = clone $this;
		$result->properties->setInt("use_animation", $animation->value);
		return $result;
	}

	public function useModifiers(float $duration = 1.6, float $movementModifier = 0.35) : self{
		self::number($duration, 0.05, 3600, "use duration");
		self::number($movementModifier, 0, 1, "use movement modifier");
		$result = clone $this;
		$result->components->setTag("minecraft:use_modifiers", CompoundTag::create()->setFloat("use_duration", $duration)->setFloat("movement_modifier", $movementModifier));
		$result->properties->setInt("use_duration", (int) ceil($duration * 20));
		return $result;
	}

	public function dyeable(int $defaultColor = 0xffffff) : self{
		self::number($defaultColor, 0, 0xffffff, "default dye color");
		$result = clone $this;
		$result->components->setTag("minecraft:dyeable", CompoundTag::create()->setString("default_color", sprintf("#%06x", $defaultColor)));
		return $result;
	}

	/** @param list<string> $items Stable item identifiers, used as client repair hints. */
	public function repairable(array $items) : self{
		if(count($items) < 1 || count($items) > 64){
			throw new \InvalidArgumentException("Expected 1 - 64 repair ingredients");
		}
		foreach($items as $item){ ItemDefinition::validateIdentifier($item); }
		$result = clone $this;
		$result->components->setTag("minecraft:repairable", CompoundTag::create()->setTag("repair_items", new ListTag([
			CompoundTag::create()->setTag("items", new ListTag(array_map(static fn(string $id) => new StringTag($id), $items), NBT::TAG_String))->setString("repair_amount", "query.max_durability * 0.25")
		], NBT::TAG_Compound)));
		return $result;
	}

	/** Client targeting hints, only accepted for a server-side Tool. Speed is derived from that tool. */
	public function digger(string ...$blocks) : self{
		if(count($blocks) < 1 || count($blocks) > 64){
			throw new \InvalidArgumentException("Expected 1 - 64 digger blocks");
		}
		foreach($blocks as $block){ ItemDefinition::validateIdentifier($block); }
		$result = clone $this;
		$result->components->setTag("minecraft:digger", CompoundTag::create()->setTag("destroy_speeds", new ListTag(array_map(static fn(string $block) => CompoundTag::create()->setString("block", $block), $blocks), NBT::TAG_Compound))->setByte("use_efficiency", 1));
		return $result;
	}

	/** Requires a ProjectileItem subclass which creates the corresponding server entity. */
	public function projectile(string $entity, float $minimumCriticalPower = 1.25) : self{
		ItemDefinition::validateIdentifier($entity);
		self::number($minimumCriticalPower, 0, 100, "minimum critical power");
		$result = clone $this;
		$result->components->setTag("minecraft:projectile", CompoundTag::create()->setString("projectile_entity", $entity)->setFloat("minimum_critical_power", $minimumCriticalPower));
		return $result;
	}

	public function throwable(bool $swingAnimation = true) : self{
		$result = clone $this;
		$result->components->setTag("minecraft:throwable", CompoundTag::create()->setByte("do_swing_animation", $swingAnimation ? 1 : 0));
		return $result;
	}

	public function swingDuration(float $seconds) : self{
		self::number($seconds, 0.05, 10, "swing duration");
		$result = clone $this;
		$result->components->setTag("minecraft:swing_duration", CompoundTag::create()->setFloat("value", $seconds));
		return $result;
	}

	/** @internal Validates and builds static wire NBT once during registration. */
	public function build(Item $item, string $identifier, string $name, ?CreativeCategory $category) : CompoundTag{
		self::number($item->getMaxStackSize(), 1, 64, "maximum stack size");
		self::number($item->getAttackPoints(), 1, 32767, "attack damage");
		self::number($item->getEnchantability(), 0, 255, "enchantability");
		self::number($item->getFuelTime(), 0, 72000, "fuel time");
		self::number($item->getCooldownTicks(), 0, 72000, "cooldown ticks");
		$properties = clone $this->properties;
		$components = clone $this->components;
		if($properties->getTag("minecraft:icon") === null){
			$properties->setTag("minecraft:icon", CompoundTag::create()->setString("texture", $identifier)->setTag("textures", CompoundTag::create()->setString("default", $identifier)));
		}
		$properties->setInt("max_stack_size", $item->getMaxStackSize());
		// Bedrock's component damage is the bonus above the player's base damage of one.
		$properties->setInt("damage", $item->getAttackPoints() - 1);
		$properties->setByte("hand_equipped", $item instanceof Tool ? 1 : 0);
		$properties->setByte("can_destroy_in_creative", $item instanceof Sword ? 0 : 1);
		$properties->setInt("creative_category", match($category){
			CreativeCategory::CONSTRUCTION => 1, CreativeCategory::NATURE => 2, CreativeCategory::EQUIPMENT => 3, CreativeCategory::ITEMS => 4, null => 0
		});
		$components->setTag("minecraft:display_name", CompoundTag::create()->setString("value", $name));
		$components->setTag("minecraft:fire_resistant", CompoundTag::create()->setByte("value", $item->isFireProof() ? 1 : 0));
		$enchantSlot = self::enchantSlot($item);
		$properties->setString("enchantable_slot", $enchantSlot)->setInt("enchantable_value", $item->getEnchantability());
		$components->setTag("minecraft:enchantable", CompoundTag::create()->setString("slot", $enchantSlot)->setInt("value", $item->getEnchantability()));
		if($item instanceof Durable){
			self::number($item->getMaxDurability(), 1, 32767, "durability");
			if($item->getMaxStackSize() !== 1){ throw new \InvalidArgumentException("Durable custom items must have a stack size of one"); }
			$components->setTag("minecraft:durability", CompoundTag::create()->setInt("max_durability", $item->getMaxDurability())->setTag("damage_chance", CompoundTag::create()->setInt("min", 100)->setInt("max", 100)));
		}elseif($components->getTag("minecraft:repairable") !== null){
			throw new \InvalidArgumentException("Repairable items must extend Durable");
		}
		if($item instanceof Armor){
			self::number($item->getDefensePoints(), 0, 20, "armor protection");
			$slot = match($item->getArmorSlot()){
				0 => "slot.armor.head", 1 => "slot.armor.chest", 2 => "slot.armor.legs", 3 => "slot.armor.feet",
				default => throw new \InvalidArgumentException("Invalid armor slot")
			};
			$components->setTag("minecraft:wearable", CompoundTag::create()->setString("slot", $slot)->setInt("protection", $item->getDefensePoints()));
		}elseif($components->getTag("minecraft:dyeable") !== null){
			throw new \InvalidArgumentException("Dyeable custom items must extend Armor");
		}
		if($item->getFuelTime() > 0){
			$components->setTag("minecraft:fuel", CompoundTag::create()->setFloat("duration", $item->getFuelTime() / 20));
		}
		if($item->getCooldownTicks() > 0){
			$cooldown = $item->getCooldownTag() ?? $identifier;
			self::text($cooldown, "cooldown category");
			$components->setTag("minecraft:cooldown", CompoundTag::create()->setString("category", $cooldown)->setFloat("duration", $item->getCooldownTicks() / 20));
		}
		if($item instanceof FoodSourceItem){
			self::number($item->getFoodRestore(), 0, 20, "nutrition");
			self::number($item->getSaturationRestore(), 0, 40, "saturation");
			if($item->getFoodRestore() === 0 && $item->getSaturationRestore() !== 0.0){ throw new \InvalidArgumentException("Saturation requires positive nutrition"); }
			$components->setTag("minecraft:food", CompoundTag::create()->setInt("nutrition", $item->getFoodRestore())->setFloat("saturation_modifier", $item->getFoodRestore() > 0 ? $item->getSaturationRestore() / (2 * $item->getFoodRestore()) : 0.0)->setByte("can_always_eat", $item->requiresHunger() ? 0 : 1));
			if($properties->getTag("use_animation") === null){ $properties->setInt("use_animation", UseAnimation::EAT->value); }
			if($components->getTag("minecraft:use_modifiers") === null){
				$components->setTag("minecraft:use_modifiers", CompoundTag::create()->setFloat("use_duration", 1.6)->setFloat("movement_modifier", 0.35));
				$properties->setInt("use_duration", 32);
			}
		}
		if($components->getTag("minecraft:use_modifiers") !== null && !$item instanceof Releasable){
			throw new \InvalidArgumentException("Use modifiers require a Releasable server item");
		}
		$animation = $properties->getInt("use_animation", UseAnimation::NONE->value);
		if($animation !== UseAnimation::NONE->value && !$item instanceof Releasable){
			throw new \InvalidArgumentException("Use animations require a Releasable server item");
		}
		if(($animation === UseAnimation::EAT->value || $animation === UseAnimation::DRINK->value) && !$item instanceof ConsumableItem){
			throw new \InvalidArgumentException("Eating and drinking animations require a ConsumableItem");
		}
		if(($digger = $components->getCompoundTag("minecraft:digger")) !== null){
			if(!$item instanceof Tool){ throw new \InvalidArgumentException("Digger requires a server-side Tool"); }
			$speed = $item->getMiningEfficiency(true);
			self::number($speed, 0, 65535, "mining speed");
			foreach($digger->getListTag("destroy_speeds", CompoundTag::class) ?? [] as $block){ $block->setFloat("speed", $speed); }
		}
		if($item instanceof ProjectileItem || $components->getTag("minecraft:projectile") !== null || $components->getTag("minecraft:throwable") !== null){
			if(!$item instanceof ProjectileItem || $components->getTag("minecraft:projectile") === null){
				throw new \InvalidArgumentException("Projectiles and throwables require a ProjectileItem and projectile definition");
			}
			if($components->getTag("minecraft:throwable") === null){
				$components->setTag("minecraft:throwable", CompoundTag::create()->setByte("do_swing_animation", 1));
			}
			if(($throwable = $components->getCompoundTag("minecraft:throwable")) !== null){
				self::number($item->getThrowForce(), 0, 100, "throw force");
				$throwable->setFloat("launch_power_scale", $item->getThrowForce())->setFloat("max_launch_power", $item->getThrowForce());
			}
		}
		$components->setTag("item_properties", $properties);
		return CompoundTag::create()->setString("name", $identifier)->setTag("components", $components);
	}

	private static function enchantSlot(Item $item) : string{
		foreach($item->getEnchantmentTags() as $tag){
			$slot = match($tag){
				"helmet" => "armor_head", "chestplate" => "armor_torso", "leggings" => "armor_legs", "boots" => "armor_feet",
				"sword" => "sword", "axe" => "axe", "pickaxe" => "pickaxe", "shovel" => "shovel", "hoe" => "hoe",
				"bow" => "bow", "crossbow" => "crossbow", "trident" => "trident", "fishing_rod" => "fishing_rod", "shears" => "shears",
				default => null
			};
			if($slot !== null){ return $slot; }
		}
		return "none";
	}

	private static function text(string $value, string $field) : void{
		if($value === "" || strlen($value) > 256 || preg_match('/^[a-zA-Z0-9_.:\/-]+$/D', $value) !== 1){
			throw new \InvalidArgumentException("Invalid $field '$value'");
		}
	}

	private static function number(int|float $value, int|float $min, int|float $max, string $field) : void{
		if(!is_finite((float) $value) || $value < $min || $value > $max){
			throw new \InvalidArgumentException("Item $field must be between $min and $max");
		}
	}
}
