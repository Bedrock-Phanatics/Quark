<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistCategory;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistCategoryPriorities;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistCategoryPriority;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPreset;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPresetExclusionDefinition;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPresetItemSettings;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistPresetsPacketOperation;
use function array_map;

final class CameraAimAssistRegistryTest extends TestCase{
	public function testPacketContainsSortedDefinitionsAndFreezesRegistry() : void{
		$registry = new CameraAimAssistRegistry();
		$registry->registerCategory(self::category("z:category"));
		$registry->registerCategory(self::category("a:category"));
		$registry->registerPreset(self::preset("z:preset", "z:category"));
		$registry->registerPreset(self::preset("a:preset", "a:category"));

		self::assertTrue($registry->hasPreset("a:preset"));
		$packet = $registry->createPacket();
		self::assertSame(["a:category", "z:category"], array_map(fn(CameraAimAssistCategory $category) => $category->getName(), $packet->getCategories()));
		self::assertSame(["a:preset", "z:preset"], array_map(fn(CameraAimAssistPreset $preset) => $preset->getIdentifier(), $packet->getPresets()));
		self::assertSame(CameraAimAssistPresetsPacketOperation::SET, $packet->getOperation());

		$this->expectException(\LogicException::class);
		$registry->registerCategory(self::category("new:category"));
	}

	public function testRejectsDuplicateDefinitions() : void{
		$registry = new CameraAimAssistRegistry();
		$registry->registerCategory(self::category("test:category"));

		$this->expectException(\InvalidArgumentException::class);
		$registry->registerCategory(self::category("test:category"));
	}

	public function testRejectsUnknownCategoryReferences() : void{
		$this->expectException(\InvalidArgumentException::class);
		(new CameraAimAssistRegistry())->registerPreset(self::preset("test:preset", "test:missing"));
	}

	public function testRejectsInvalidCategoryIdentifier() : void{
		$this->expectException(\InvalidArgumentException::class);
		(new CameraAimAssistRegistry())->registerCategory(self::category("Invalid:category"));
	}

	public function testRejectsPriorityBeyondProtocolIntegerBounds() : void{
		$registry = new CameraAimAssistRegistry();
		$priorities = new CameraAimAssistCategoryPriorities(
			[],
			[new CameraAimAssistCategoryPriority("minecraft:stone", 0x80000000)],
			[],
			[],
			null,
			null
		);

		$this->expectException(\InvalidArgumentException::class);
		$registry->registerCategory(new CameraAimAssistCategory("test:category", $priorities));
	}

	public function testRejectsMoreThan1024EntriesInNestedArrays() : void{
		$registry = new CameraAimAssistRegistry();
		$blocks = [];
		for($i = 0; $i <= 1024; ++$i){
			$blocks[] = new CameraAimAssistCategoryPriority("minecraft:block_$i", 1);
		}
		$priorities = new CameraAimAssistCategoryPriorities([], $blocks, [], [], null, null);

		$this->expectException(\InvalidArgumentException::class);
		$registry->registerCategory(new CameraAimAssistCategory("test:category", $priorities));
	}

	public function testFreezeRejectsRegistrationBeforePacketCreation() : void{
		$registry = new CameraAimAssistRegistry();
		$registry->freeze();

		$this->expectException(\LogicException::class);
		$registry->registerPreset(self::preset("test:preset", "test:category"));
	}

	public function testAggregateEntryLimitDoesNotPartiallyRegisterDefinitions() : void{
		$registry = new CameraAimAssistRegistry();
		$entries = [];
		for($i = 0; $i < 1024; ++$i){
			$entries[] = new CameraAimAssistCategoryPriority("minecraft:block_$i", 1);
		}
		$priorities = new CameraAimAssistCategoryPriorities([], $entries, [], [], null, null);
		for($i = 0; $i < 4; ++$i){
			$registry->registerCategory(new CameraAimAssistCategory("test:category_$i", $priorities));
		}
		try{
			$registry->registerCategory(new CameraAimAssistCategory("test:overflow", $priorities));
			self::fail("Oversized aggregate definitions were accepted");
		}catch(\InvalidArgumentException){
			$registry->registerCategory(self::category("test:overflow"));
			self::assertCount(5, $registry->createPacket()->getCategories());
		}
	}

	private static function category(string $name) : CameraAimAssistCategory{
		return new CameraAimAssistCategory(
			$name,
			new CameraAimAssistCategoryPriorities([], [], [], [], null, null)
		);
	}

	private static function preset(string $identifier, string $category) : CameraAimAssistPreset{
		return new CameraAimAssistPreset(
			$identifier,
			new CameraAimAssistPresetExclusionDefinition([], [], [], []),
			[],
			[new CameraAimAssistPresetItemSettings("minecraft:stick", $category)],
			$category,
			null
		);
	}
}
