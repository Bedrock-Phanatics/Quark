<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\camera\CameraPresetAimAssist;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEaseType;
use pocketmine\network\mcpe\protocol\types\ControlScheme;
use quark\math\Vector2;
use quark\math\Vector3;
use function array_map;
use function class_exists;
use const INF;
use const NAN;

final class CameraPresetTest extends TestCase{

	public function testRegistryFreezesDeterministicIdsAndPreservesInheritance() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:z", parent: "test:a", yaw: 45));
		$registry->register(new CameraPreset("test:a", yPosition: 64));
		$packet = $registry->createPacket();
		$names = array_map(static fn($preset) => $preset->getName(), $packet->getPresets());
		self::assertTrue($registry->isFrozen());
		self::assertSame("test:a", $names[$registry->getId("test:a")]);
		self::assertSame("test:z", $names[$registry->getId("test:z")]);
		self::assertSame("test:a", $registry->get("test:z")->parent);
		self::assertNull($registry->get("test:z")->yPosition);
		self::assertSame(64.0, $registry->get("test:a")->yPosition);
		$registry->freeze();
		self::assertSame($names, array_map(static fn($preset) => $preset->getName(), $registry->createPacket()->getPresets()));

		$other = new CameraPresetRegistry();
		$other->register(new CameraPreset("test:a", yPosition: 64));
		$other->register(new CameraPreset("test:z", parent: "test:a", yaw: 45));
		$other->freeze();
		self::assertSame($registry->getId("test:z"), $other->getId("test:z"));
	}

	public function testDuplicatePresetIsRejected() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:camera"));
		$this->expectException(\InvalidArgumentException::class);
		$registry->register(new CameraPreset("test:camera"));
	}

	public function testBuiltinsCannotBeReplaced() : void{
		$registry = new CameraPresetRegistry();
		$this->expectException(\InvalidArgumentException::class);
		$registry->register(new CameraPreset(CameraPreset::FREE));
	}

	public function testRegistrationAfterFreezeIsRejected() : void{
		$registry = new CameraPresetRegistry();
		$registry->freeze();
		$this->expectException(\LogicException::class);
		$registry->register(new CameraPreset("test:late"));
	}

	public function testNetworkIdRequiresFreeze() : void{
		$this->expectException(\LogicException::class);
		(new CameraPresetRegistry())->getId(CameraPreset::FREE);
	}

	public function testUnknownPresetIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		(new CameraPresetRegistry())->get("test:missing");
	}

	public function testMissingParentDoesNotPartiallyFreezeRegistry() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:child", parent: "test:missing"));
		try{
			$registry->freeze();
			self::fail("Missing parent was accepted");
		}catch(\InvalidArgumentException){
			self::assertFalse($registry->isFrozen());
		}
		$registry->register(new CameraPreset("test:missing"));
		$registry->freeze();
		self::assertTrue($registry->isFrozen());
	}

	public function testInheritanceCycleIsRejected() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:a", parent: "test:b"));
		$registry->register(new CameraPreset("test:b", parent: "test:a"));
		$this->expectException(\InvalidArgumentException::class);
		$registry->freeze();
	}

	public function testSelfInheritanceIsRejected() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:self", parent: "test:self"));
		$this->expectException(\InvalidArgumentException::class);
		$registry->freeze();
	}

	public function testPacketFieldsPreserveOmittedAndExplicitValues() : void{
		$preset = new CameraPreset(
			"test:defaults", xPosition: 0, pitch: -90, yaw: 180, rotationSpeed: 0,
			snapToTarget: false, continueTargeting: false, blockListeningRadius: 0,
			radius: 0.1, yawLimitMin: -180, yawLimitMax: 180, audioListenerType: 0,
			playerEffects: false, controlScheme: ControlScheme::CAMERA_RELATIVE,
			applyInheritedStartingRotation: true
		);
		$protocol = $preset->toProtocol();
		self::assertSame(0.0, $protocol->getXPosition());
		self::assertNull($protocol->getYPosition());
		self::assertFalse($protocol->getSnapToTarget());
		self::assertFalse($protocol->getContinueTargeting());
		self::assertFalse($protocol->getPlayerEffects());
		self::assertSame(0, $protocol->getAudioListenerType());
		self::assertSame(ControlScheme::CAMERA_RELATIVE, $protocol->getControlScheme());
		self::assertTrue($protocol->isApplyInheritedStartingRotation());
		self::assertNull($protocol->getStartingRotation());
		self::assertNotSame($protocol, $preset->toProtocol());
	}

	public function testVectorsAreDefensiveCopies() : void{
		if(!class_exists(Vector2::class) || !class_exists(Vector3::class)){
			self::markTestSkipped("qmath is required for camera vector tests");
		}
		$offset = new Vector3(1, 2, 3);
		$angle = new Vector2(20, 30);
		$preset = new CameraPreset("test:snapshot", entityOffset: $offset, startingRotation: $angle, aimAssist: new CameraPresetAimAssist(null, null, $angle, 5));
		$offset->x = 100;
		$angle->x = 80;
		self::assertSame(1.0, (float) $preset->entityOffset?->x);
		self::assertSame(20.0, $preset->startingRotation?->x);
		self::assertSame(20.0, $preset->aimAssist?->getViewAngle()?->x);
		$protocol = $preset->toProtocol();
		$protocolOffset = $protocol->getEntityOffset();
		$protocolAngle = $protocol->getAimAssist()?->getViewAngle();
		self::assertNotNull($protocolOffset);
		self::assertNotNull($protocolAngle);
		$protocolOffset->x = 99;
		$protocolAngle->x = 89;
		self::assertSame(1.0, (float) $preset->toProtocol()->getEntityOffset()?->x);
		self::assertSame(20.0, $preset->toProtocol()->getAimAssist()?->getViewAngle()?->x);
	}

	public function testAllEasesMatchTheProtocol() : void{
		self::assertSame(
			array_map(static fn($ease) => $ease->value, CameraSetInstructionEaseType::cases()),
			array_map(static fn($ease) => $ease->toProtocol()->value, CameraEase::cases())
		);
	}

	public function testContradictoryInheritedYawLimitsAreRejectedBeforeFreeze() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:parent", yawLimitMin: 100));
		$registry->register(new CameraPreset("test:child", parent: "test:parent", yawLimitMax: -100));
		try{
			$registry->freeze();
			self::fail("Contradictory inherited yaw limits were accepted");
		}catch(\InvalidArgumentException){
			self::assertFalse($registry->isFrozen());
		}
	}

	public function testChildCanOverrideBothInheritedYawLimits() : void{
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:child", parent: "test:parent", yawLimitMin: -120, yawLimitMax: -100));
		$registry->register(new CameraPreset("test:parent", yawLimitMin: 100, yawLimitMax: 120));
		$registry->freeze();
		self::assertTrue($registry->isFrozen());
	}

	/** @return \Generator<string, array{\Closure() : mixed}, void, void> */
	public static function invalidPresets() : \Generator{
		yield "unqualified identifier" => [static fn() => new CameraPreset("camera")];
		yield "invalid namespace" => [static fn() => new CameraPreset("Test:camera")];
		yield "invalid parent" => [static fn() => new CameraPreset("test:camera", parent: "bad parent")];
		yield "NaN position" => [static fn() => new CameraPreset("test:camera", xPosition: NAN)];
		yield "infinite yaw" => [static fn() => new CameraPreset("test:camera", yaw: INF)];
		yield "invalid pitch" => [static fn() => new CameraPreset("test:camera", pitch: 90.1)];
		yield "invalid yaw" => [static fn() => new CameraPreset("test:camera", yaw: 180.1)];
		yield "negative speed" => [static fn() => new CameraPreset("test:camera", rotationSpeed: -1)];
		yield "negative radius" => [static fn() => new CameraPreset("test:camera", radius: -1)];
		yield "oversized radius" => [static fn() => new CameraPreset("test:camera", radius: 101)];
		yield "reversed yaw limits" => [static fn() => new CameraPreset("test:camera", yawLimitMin: 20, yawLimitMax: 10)];
		yield "invalid audio listener" => [static fn() => new CameraPreset("test:camera", audioListenerType: 2)];
		yield "negative duration" => [static fn() => CameraValidation::duration(-1)];
		yield "infinite duration" => [static fn() => CameraValidation::duration(INF)];
		yield "NaN duration" => [static fn() => CameraValidation::duration(NAN)];
		yield "excessive duration" => [static fn() => CameraValidation::duration(3601)];
	}

	/** @param \Closure() : mixed $factory */
	#[DataProvider("invalidPresets")]
	public function testInvalidPresetsAreRejected(\Closure $factory) : void{
		$this->expectException(\InvalidArgumentException::class);
		$factory();
	}
}
