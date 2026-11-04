<?php

declare(strict_types=1);

namespace quark\item\custom;

use pocketmine\nbt\tag\CompoundTag;
use quark\block\tile\dispenser\ArmorDispensableItem;
use quark\block\tile\dispenser\DispensableItemManager;
use quark\data\bedrock\item\ItemDeserializer;
use quark\data\bedrock\item\ItemSerializer;
use quark\data\bedrock\item\SavedItemData;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeGroup;
use quark\inventory\CreativeInventory;
use quark\item\Armor;
use quark\item\Durable;
use quark\item\Item;
use quark\item\ItemBlock;
use quark\item\ItemIdentifier;
use quark\item\ItemTypeIds;
use quark\item\StringToItemParser;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\serializer\ItemTypeDictionary;
use quark\network\mcpe\protocol\types\CacheableNbt;
use quark\network\mcpe\protocol\types\ItemTypeEntry;
use quark\plugin\Plugin;
use quark\utils\Utils;
use quark\world\format\io\GlobalItemDataHandlers;
use function array_keys;
use function count;
use function explode;
use function in_array;
use function ksort;
use function max;
use function morton2d_encode;
use function strlen;
use const SORT_STRING;

/**
 * Startup-only registration. Factories are consumed once and only detached prototypes are retained.
 */
final class ItemRegistry{
	public const int MAX_ITEMS = 4096;
	public const int MAX_COMPONENT_BYTES = 65536;
	public const int MAX_CUSTOM_COMPONENT_BYTES = 4 * 1024 * 1024;

	private static ?self $instance = null;
	/** @var array<string, Item> */
	private array $items = [];
	/** @var array<string, array{CompoundTag, ?CreativeCategory, ?CreativeGroup}> */
	private array $pending = [];
	/** @var array<string, string> */
	private array $namespaceOwners = [];
	private bool $finalized = false;
	private bool $registering = false;
	private int $componentBytes = 0;

	/** @internal Use getInstance() in plugins. Explicit dependencies allow isolated registries in tests. */
	public function __construct(private ItemSerializer $serializer, private ItemDeserializer $deserializer, private StringToItemParser $parser, private ItemTypeDictionary $vanillaDictionary){}

	public static function getInstance() : self{
		return self::$instance ??= new self(GlobalItemDataHandlers::getSerializer(), GlobalItemDataHandlers::getDeserializer(), StringToItemParser::getInstance(), TypeConverter::getInstance()->getItemTypeDictionary());
	}

	public function register(ItemDefinition $definition, Plugin $owner) : void{
		if(\pmmp\thread\Thread::getCurrentThread() !== null){ throw new \LogicException("Custom items must be registered on the main thread"); }
		if($this->finalized){ throw new \LogicException("Custom item registration is closed. Register during STARTUP or POSTWORLD plugin enable"); }
		if($this->registering){ throw new \LogicException("Item factories must not register other items"); }
		$id = $definition->getIdentifier();
		[$namespace] = explode(":", $id, 2);
		$ownerName = $owner->getName();
		if(in_array($namespace, ["minecraft", "quark"], true)){ throw new \InvalidArgumentException("Namespace '$namespace' is reserved"); }
		if(isset($this->namespaceOwners[$namespace]) && $this->namespaceOwners[$namespace] !== $ownerName){
			throw new \InvalidArgumentException("Namespace '$namespace' belongs to plugin " . $this->namespaceOwners[$namespace]);
		}
		if(isset($this->items[$id]) || $this->vanillaDictionary->hasStringId($id) || $this->deserializer->getDeserializerForId($id) !== null || $this->parser->hasAlias($id)){
			throw new \InvalidArgumentException("Item '$id' is already registered");
		}
		if(count($this->items) >= self::MAX_ITEMS){ throw new \InvalidArgumentException("Custom item limit exceeded"); }

		$this->registering = true;
		try{
			$typeId = ItemTypeIds::newId();
			$item = $definition->createPrototype(new ItemIdentifier($typeId));
			if($item instanceof ItemBlock || $item->getTypeId() !== $typeId || $item->isNull() || $item->getCount() !== 1){
				throw new \InvalidArgumentException("Factory for '$id' must return a new non-block Item with the supplied identifier and count one");
			}
			if($item->getStateId() !== morton2d_encode($typeId, 0)){
				throw new \InvalidArgumentException("Custom item '$id' must persist its state through named NBT, not runtime state bits");
			}
			if($item instanceof Durable && ($item->getDamage() !== 0 || $item->isUnbreakable())){
				throw new \InvalidArgumentException("Custom item prototypes must have full, breakable durability");
			}
			if($item->hasCustomName() || $item->getLore() !== [] || $item->getNamedTag()->count() !== 0){
				throw new \InvalidArgumentException("Custom item prototypes must not contain per-stack NBT, names, lore or enchantments");
			}
			$item = clone $item;
			if($item->getVanillaName() !== $definition->getName()){
				throw new \InvalidArgumentException("Factory item name must match the definition display name");
			}
			$nbt = $definition->getComponents()->build($item, $id, $definition->getName(), $definition->getCreativeCategory());
			$bytes = strlen((new CacheableNbt($nbt))->getEncodedNbt());
			if($bytes > self::MAX_COMPONENT_BYTES || $this->componentBytes + $bytes > self::MAX_CUSTOM_COMPONENT_BYTES){
				throw new \InvalidArgumentException("Custom item component byte limit exceeded for '$id'");
			}
			if($this->deserializer->getDeserializerForId($id) !== null || $this->parser->hasAlias($id)){
				throw new \InvalidArgumentException("Factory changed existing registrations for '$id'");
			}
			$this->serializer->map($item, static fn() => new SavedItemData($id));
			$this->deserializer->mapCustom($id, $item);
			$this->parser->register($id, static fn() => clone $item);
			if($item instanceof Armor){ DispensableItemManager::register($item, new ArmorDispensableItem($item->getArmorSlot())); }
			$this->items[$id] = $item;
			$this->pending[$id] = [$nbt, $definition->getCreativeCategory(), $definition->getCreativeGroup()];
			$this->namespaceOwners[$namespace] = $ownerName;
			$this->componentBytes += $bytes;
		}catch(\Throwable $e){
			throw new \InvalidArgumentException("Cannot register custom item '$id' for plugin '$ownerName': " . $e->getMessage(), 0, $e);
		}finally{
			$this->registering = false;
		}
	}

	public function create(string $identifier, int $count = 1) : Item{
		$prototype = $this->items[$identifier] ?? throw new \InvalidArgumentException("Unknown custom item '$identifier'");
		if($count < 1 || $count > $prototype->getMaxStackSize()){
			throw new \InvalidArgumentException("Invalid count $count for custom item '$identifier'");
		}
		return (clone $prototype)->setCount($count);
	}

	public function isRegistered(string $identifier) : bool{ return isset($this->items[$identifier]); }

	public function isFinalized() : bool{ return $this->finalized; }

	/** @return list<string> */
	public function getIdentifiers() : array{ return array_keys($this->items); }

	/**
	 * @internal Called after POSTWORLD enable and before network listeners start.
	 * @param list<ItemTypeEntry> $blockItemEntries custom block items, which keep their block-derived IDs
	 */
	public function finalize(TypeConverter $converter, CreativeInventory $creative, array $blockItemEntries = []) : void{
		if(\pmmp\thread\Thread::getCurrentThread() !== null){ throw new \LogicException("Custom items must be finalized on the main thread"); }
		if($this->registering || $this->finalized){ throw new \LogicException("Item registry cannot be finalized in its current state"); }
		$entries = $blockItemEntries;
		$runtimeId = 0;
		foreach($converter->getItemTypeDictionary()->getEntries() as $entry){ $runtimeId = max($runtimeId, $entry->getNumericId()); }
		$runtimeId = max(10000, $runtimeId + 1);
		$items = $this->pending;
		ksort($items, SORT_STRING);
		foreach(Utils::stringifyKeys($items) as $identifier => [$nbt]){
			if($runtimeId > 0x7fff){ throw new \OverflowException("Custom item runtime IDs exhausted"); }
			$nbt = clone $nbt;
			$nbt->setInt("id", $runtimeId);
			$entries[] = new ItemTypeEntry($identifier, $runtimeId++, true, 1, new CacheableNbt($nbt));
		}
		$converter->finalizeItemRegistry($entries);
		$this->finalized = true;
		$this->pending = [];
		foreach(Utils::stringifyKeys($items) as $identifier => [$nbt, $category, $group]){
			if($category !== null){ $creative->add($this->items[$identifier], $category, $group); }
		}
	}
}
