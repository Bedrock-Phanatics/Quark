<?php

declare(strict_types=1);

namespace quark\tools\benchmark_custom_items;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use quark\data\bedrock\item\ItemDeserializer;
use quark\data\bedrock\item\ItemSerializer;
use quark\inventory\CreativeInventory;
use quark\inventory\SimpleInventory;
use quark\item\ArmorTypeInfo;
use quark\item\custom\ItemDefinition;
use quark\item\custom\ItemRegistry;
use quark\item\EquipmentTestPlayer;
use quark\item\EquipmentTestVictim;
use quark\item\VanillaArmorMaterials;
use quark\item\VanillaItems;
use quark\network\mcpe\cache\CreativeInventoryCache;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\plugin\Plugin;
use quark\Server;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\world\World;
use quark\world\WorldManager;
use function dirname;
use function hrtime;
use function ini_get;
use function json_encode;
use function memory_get_peak_usage;
use function printf;
use function round;
use function sort;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tests/phpunit/item/CustomEquipmentTest.php';

/**
 * Requires development dependencies. World/socket output is stubbed equally for both attack workloads.
 */
final class CustomItemBenchmark extends TestCase{
	/** @param \Closure() : mixed $action
	 * @return array{median_us: float, iterations: int, rounds: int}
	 */
	private static function measure(\Closure $action, int $iterations = 5000) : array{
		for($i = 0; $i < 500; ++$i){ $action(); }
		$samples = [];
		for($round = 0; $round < 7; ++$round){
			$start = hrtime(true);
			for($i = 0; $i < $iterations; ++$i){ $action(); }
			$samples[] = (hrtime(true) - $start) / $iterations / 1000;
		}
		sort($samples);
		return ["median_us" => round($samples[3], 3), "iterations" => $iterations, "rounds" => 7];
	}

	public function runBenchmark() : void{
		$serializer = new ItemSerializer(GlobalBlockStateHandlers::getSerializer());
		$deserializer = new ItemDeserializer(GlobalBlockStateHandlers::getDeserializer());
		$converter = new TypeConverter($serializer, $deserializer);
		TypeConverter::setInstance($converter);
		$registry = new ItemRegistry($serializer, $deserializer, new \quark\item\StringToItemParser(), $converter->getItemTypeDictionary());
		$plugin = $this->createMock(Plugin::class);
		$plugin->method("getName")->willReturn("Benchmark");
		$registry->register(ItemDefinition::sword("benchmark:sword", "Sword", 8, 1562), $plugin);
		$registry->register(ItemDefinition::armor("benchmark:helmet", "Helmet", new ArmorTypeInfo(3, 364, 0, material: VanillaArmorMaterials::DIAMOND())), $plugin);
		$creative = CreativeInventory::getInstance();
		$registry->finalize($converter, $creative);
		$inventory = new SimpleInventory(36);
		$results = [];
		foreach(["vanilla" => VanillaItems::DIAMOND_SWORD(), "custom" => $registry->create("benchmark:sword")] as $kind => $item){
			$results[$kind . ".create"] = self::measure($kind === "vanilla" ? static fn() => VanillaItems::DIAMOND_SWORD() : static fn() => $registry->create("benchmark:sword"));
			$results[$kind . ".serialize"] = self::measure(static fn() => $serializer->serializeStack($item));
			$results[$kind . ".network"] = self::measure(static fn() => $converter->coreItemStackToNet($item));
			$results[$kind . ".inventory_set_get"] = self::measure(static function() use ($inventory, $item) : void{ $inventory->setItem(0, $item); $inventory->getItem(0); });
		}
		$results["registry_packet.cached"] = self::measure(static fn() => $converter->getItemRegistryPacket());
		$results["registry_packet.rebuild_reference"] = self::measure(static function() use ($converter) : EncodedPacket{
			return EncodedPacket::of(ItemRegistryPacket::create($converter->getItemTypeDictionary()->getEntries()));
		}, 500);
		$results["creative.prepare_cached"] = self::measure(static fn() => CreativeInventoryCache::getInstance()->prepare($creative));
		$server = $this->createMock(Server::class);
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getServer")->willReturn($server);
		$attacker = new \ReflectionClass(EquipmentTestPlayer::class)->newInstanceWithoutConstructor();
		$attacker->initialize($server, $world, $this->createMock(\Logger::class));
		$victim = new \ReflectionClass(EquipmentTestVictim::class)->newInstanceWithoutConstructor();
		$victim->initialize($world);
		$manager = $this->createMock(WorldManager::class);
		$manager->method("findEntity")->willReturn($attacker);
		$server->method("getWorldManager")->willReturn($manager);
		foreach(["vanilla" => [VanillaItems::DIAMOND_SWORD(), VanillaItems::DIAMOND_HELMET()], "custom" => [$registry->create("benchmark:sword"), $registry->create("benchmark:helmet")]] as $kind => [$weapon, $armor]){
			$results[$kind . ".attack_with_equipment_reset"] = self::measure(static function() use ($attacker, $victim, $weapon, $armor) : void{
				$victim->resetAttackState();
				$attacker->getInventory()->setItemInHand($weapon);
				$victim->getArmorInventory()->setHelmet($armor);
				$attacker->attackEntity($victim);
			}, 2000);
		}
		printf("%s\n", json_encode(["php" => PHP_VERSION, "assertions" => ini_get("zend.assertions"), "peak_bytes" => memory_get_peak_usage(true), "results" => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}
}

(new CustomItemBenchmark("runBenchmark"))->runBenchmark();
