<?php

declare(strict_types=1);

namespace quark\item;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;
use quark\block\tile\dispenser\ArmorDispensableItem;
use quark\entity\animation\Animation;
use quark\entity\AttributeMap;
use quark\entity\effect\EffectManager;
use quark\entity\EntitySizeInfo;
use quark\entity\HungerManager;
use quark\entity\Living;
use quark\entity\Location;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\inventory\ArmorInventory;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\CreativeInventory;
use quark\inventory\Hotbar;
use quark\inventory\SimpleInventory;
use quark\inventory\transaction\action\CreateItemAction;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\custom\CustomSword;
use quark\item\enchantment\ItemEnchantmentTags;
use quark\item\enchantment\VanillaEnchantments;
use quark\player\GameMode;
use quark\player\Player;
use quark\player\PlayerInventoryType;
use quark\player\PlayerInventoryWindow;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\Server;
use quark\world\Position;
use quark\world\sound\Sound;
use quark\world\World;
use quark\world\WorldManager;
use const M_SQRT3;

final class CustomEquipmentTest extends TestCase{
	private EquipmentTestPlayer $attacker;
	private EquipmentTestVictim $victim;
	private World&MockObject $world;
	private Plugin $plugin;

	protected function setUp() : void{
		$server = $this->createMock(Server::class);
		$this->world = $this->createMock(World::class);
		$this->world->method("isLoaded")->willReturn(true);
		$this->world->method("getServer")->willReturn($server);
		$this->attacker = new \ReflectionClass(EquipmentTestPlayer::class)->newInstanceWithoutConstructor();
		$this->attacker->initialize($server, $this->world, $this->createMock(\Logger::class));
		$this->victim = new \ReflectionClass(EquipmentTestVictim::class)->newInstanceWithoutConstructor();
		$this->victim->initialize($this->world);
		$manager = $this->createMock(WorldManager::class);
		$manager->method("findEntity")->willReturn($this->attacker);
		$server->method("getWorldManager")->willReturn($manager);
		$this->plugin = $this->createMock(Plugin::class);
	}

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll($this->plugin);
	}

	/** @param \Closure(EntityDamageByEntityEvent) : void $callback */
	private function listen(\Closure $callback) : void{
		HandlerListManager::global()->getListFor(EntityDamageByEntityEvent::class)->register(new RegisteredListener($callback, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("custom-items.test")));
	}

	private function sword() : CustomSword{
		return new CustomSword(new ItemIdentifier(ItemTypeIds::newId()), "Ruby Sword", 9, 100);
	}

	private function helmet() : Armor{
		return new Armor(new ItemIdentifier(ItemTypeIds::newId()), "Ruby Helmet", new ArmorTypeInfo(3, 100, ArmorInventory::SLOT_HEAD, material: VanillaArmorMaterials::DIAMOND()), [ItemEnchantmentTags::HELMET]);
	}

	public function testAttackRunsOnceAndConsumesDurabilityOnce() : void{
		$this->attacker->setMainHandItem($this->sword());
		$calls = 0;
		$this->listen(static function(EntityDamageByEntityEvent $event) use (&$calls) : void{ ++$calls; });
		self::assertTrue($this->attacker->attackEntity($this->victim));
		self::assertSame(11.0, $this->victim->getHealth());
		self::assertSame(1, $calls);
		$item = $this->attacker->getMainHandItem();
		self::assertInstanceOf(CustomSword::class, $item);
		self::assertSame(1, $item->getDamage());
		self::assertSame(1, $this->victim->knockbacks);
	}

	public function testCancelledAttackPreservesBothInventoriesAndHealth() : void{
		$sword = $this->sword();
		$armor = $this->helmet();
		$this->attacker->setMainHandItem($sword);
		$this->victim->getArmorInventory()->setHelmet($armor);
		$this->listen(static function(EntityDamageByEntityEvent $event) : void{ $event->cancel(); });
		self::assertFalse($this->attacker->attackEntity($this->victim));
		self::assertSame(20.0, $this->victim->getHealth());
		self::assertTrue($sword->equalsExact($this->attacker->getMainHandItem()));
		self::assertTrue($armor->equalsExact($this->victim->getArmorInventory()->getHelmet()));
		self::assertSame(0, $this->victim->knockbacks);
	}

	public function testEquipmentChangedDuringEventIsNotOverwritten() : void{
		$this->attacker->setMainHandItem($this->sword());
		$replacement = VanillaItems::STICK();
		$this->listen(function(EntityDamageByEntityEvent $event) use ($replacement) : void{ $this->attacker->setMainHandItem($replacement); });
		self::assertTrue($this->attacker->attackEntity($this->victim));
		self::assertSame(11.0, $this->victim->getHealth());
		self::assertTrue($replacement->equalsExact($this->attacker->getMainHandItem()));
	}

	public function testCriticalHitAndSharpnessUseExistingModifiers() : void{
		$sword = $this->sword()->addEnchantment(VanillaEnchantments::SHARPNESS(), 2);
		$this->attacker->setMainHandItem($sword);
		$this->attacker->fallDistance = 1;
		$event = null;
		$this->listen(static function(EntityDamageByEntityEvent $source) use (&$event) : void{ $event = $source; });
		self::assertTrue($this->attacker->attackEntity($this->victim));
		self::assertInstanceOf(EntityDamageByEntityEvent::class, $event);
		self::assertGreaterThan(0.0, $event->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS));
		self::assertSame(($event->getBaseDamage() + $event->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS)) / 2, $event->getModifier(EntityDamageEvent::MODIFIER_CRITICAL));
	}

	public function testCustomAndVanillaArmorUseSameDamageAndDurabilityRules() : void{
		foreach([$this->helmet(), VanillaItems::DIAMOND_HELMET()] as $helmet){
			$victim = new \ReflectionClass(EquipmentTestVictim::class)->newInstanceWithoutConstructor();
			$victim->initialize($this->world);
			$victim->getArmorInventory()->setHelmet($helmet);
			$event = new EntityDamageEvent($victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 8);
			$victim->attack($event);
			self::assertEqualsWithDelta(12.96, $victim->getHealth(), 0.00001);
			$damaged = $victim->getArmorInventory()->getHelmet();
			self::assertInstanceOf(Armor::class, $damaged);
			self::assertSame(2, $damaged->getDamage());
		}
		$this->victim->getArmorInventory()->setHelmet($this->helmet());
		$this->victim->getArmorInventory()->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
		$event = new EntityDamageEvent($this->victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 8);
		$this->victim->attack($event);
		self::assertEqualsWithDelta(15.52, $this->victim->getHealth(), 0.00001);
	}

	public function testProtectionAndBreakageUseNativeArmorPathAndCallbacks() : void{
		$helmet = $this->helmet()->addEnchantment(VanillaEnchantments::PROTECTION(), 4);
		self::assertInstanceOf(Armor::class, $helmet);
		$helmet->setDamage(99);
		$this->victim->getArmorInventory()->setHelmet($helmet);
		$changes = 0;
		$this->victim->getArmorInventory()->getListeners()->add(CallbackInventoryListener::onAnyChange(static function() use (&$changes) : void{ ++$changes; }));
		$event = new EntityDamageEvent($this->victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 8);
		$this->victim->attack($event);
		self::assertLessThan(0.0, $event->getModifier(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS));
		self::assertTrue($this->victim->getArmorInventory()->getHelmet()->isNull());
		self::assertSame(1, $changes);
	}

	public function testDamageCooldownRejectsRepeatedHitWithoutDurabilityCost() : void{
		$this->attacker->setMainHandItem($this->sword());
		self::assertTrue($this->attacker->attackEntity($this->victim));
		self::assertFalse($this->attacker->attackEntity($this->victim));
		self::assertSame(11.0, $this->victim->getHealth());
		$held = $this->attacker->getMainHandItem();
		self::assertInstanceOf(CustomSword::class, $held);
		self::assertSame(1, $held->getDamage());
	}

	public function testWrongSlotAndOverstackedArmorFailTransactionValidation() : void{
		$armor = $this->helmet();
		$inventory = $this->victim->getArmorInventory();
		$window = new PlayerInventoryWindow($this->attacker, $inventory, PlayerInventoryType::ARMOR);
		new \ReflectionProperty(Player::class, "permanentWindows")->setValue($this->attacker, [PlayerInventoryType::ARMOR->name => $window]);
		foreach([[ArmorInventory::SLOT_CHEST, $armor], [ArmorInventory::SLOT_HEAD, (clone $armor)->setCount(2)]] as [$slot, $target]){
			try{
				(new SlotChangeAction($window, $slot, VanillaItems::AIR(), $target))->validate($this->attacker);
				self::fail("Invalid armor equip accepted");
			}catch(TransactionValidationException){ }
		}
		(new SlotChangeAction($window, ArmorInventory::SLOT_HEAD, VanillaItems::AIR(), $armor))->validate($this->attacker);
		self::assertTrue($inventory->getHelmet()->isNull());
	}

	public function testDispenserEquipsOneItemAndConsumesOne() : void{
		$this->world->method("getNearbyEntities")->willReturn([$this->victim]);
		$inventory = new SimpleInventory(9);
		$inventory->setItem(0, $this->helmet());
		self::assertTrue((new ArmorDispensableItem(ArmorInventory::SLOT_HEAD))->dispense(new Position(0, 64, 0, $this->world), $inventory, 0, new Vector3(0, 64, 1), 3));
		self::assertTrue($inventory->getItem(0)->isNull());
		self::assertInstanceOf(Armor::class, $this->victim->getArmorInventory()->getHelmet());
		self::assertSame(1, $this->victim->getArmorInventory()->getHelmet()->getCount());
	}

	public function testArmorEquipsFromHandWithNativeSound() : void{
		$helmet = $this->helmet();
		$this->attacker->setMainHandItem($helmet);
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $helmet->onClickAir($this->attacker, new Vector3(0, 0, 1), $returned));
		self::assertTrue($helmet->equalsExact($this->attacker->getArmorInventory()->getHelmet()));
		self::assertTrue($this->attacker->getMainHandItem()->isNull());
		self::assertSame(1, $this->attacker->sounds);
		self::assertSame([], $returned);
	}

	public function testWeaponBreaksOnceOnSuccessfulHit() : void{
		$weapon = $this->sword();
		$weapon->setDamage(99);
		$this->attacker->setMainHandItem($weapon);
		self::assertTrue($this->attacker->attackEntity($this->victim));
		self::assertTrue($this->attacker->getMainHandItem()->isNull());
		self::assertSame(11.0, $this->victim->getHealth());
	}

	public function testCreativeCannotForgeUnlistedItem() : void{
		$this->attacker->makeCreative();
		$this->expectException(TransactionValidationException::class);
		(new CreateItemAction($this->sword()))->validate($this->attacker);
	}
}

/**
 * Runs the real Player::attackEntity() with no socket or live world.
 */
final class EquipmentTestPlayer extends Player{
	public int $sounds = 0;
	public function initialize(Server $server, World $world, \Logger $logger) : void{
		$this->server = $server;
		$this->logger = $logger;
		$this->id = 1;
		$this->username = "Attacker";
		$this->gamemode = GameMode::SURVIVAL;
		$this->networkSession = null;
		$this->location = new Location(0, 64, 0, $world, 0, 0);
		$this->size = new EntitySizeInfo(1.8, 0.6);
		$this->attributeMap = new AttributeMap();
		$this->addAttributes();
		$this->effectManager = new EffectManager($this);
		$this->inventory = new SimpleInventory(36);
		$this->hotbar = new Hotbar();
		$this->armorInventory = new ArmorInventory();
		$this->hungerManager = new HungerManager($this);
		$this->creativeInventory = CreativeInventory::getInstance();
	}

	public function makeCreative() : void{ $this->gamemode = GameMode::CREATIVE; }

	public function canInteract(Vector3 $pos, float $maxDistance, float $maxDiff = M_SQRT3 / 2) : bool{ return true; }

	public function isUnderwater() : bool{ return false; }

	public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{}

	public function broadcastSound(Sound $sound, ?array $targets = null) : void{ ++$this->sounds; }

	public function __destruct(){}
}

final class EquipmentTestVictim extends Living{
	public int $knockbacks = 0;

	public function initialize(World $world) : void{
		$this->id = 2;
		$this->location = new Location(0, 64, 1, $world, 0, 0);
		$this->size = new EntitySizeInfo(1.8, 0.6);
		$this->attributeMap = new AttributeMap();
		$this->addAttributes();
		$this->effectManager = new EffectManager($this);
		$this->armorInventory = new ArmorInventory();
	}

	public function getName() : string{ return "Victim"; }

	public function resetAttackState() : void{
		$this->attackTime = 0;
		$this->lastDamageCause = null;
		$this->setHealth(20);
	}

	public function getNetworkTypeId() : string{ return "minecraft:zombie"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{ ++$this->knockbacks; }

	protected function doHitAnimation() : void{}

	public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{}

	public function broadcastSound(Sound $sound, ?array $targets = null) : void{}

	public function __destruct(){}
}
