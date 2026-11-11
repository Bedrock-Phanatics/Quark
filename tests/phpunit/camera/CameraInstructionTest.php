<?php

declare(strict_types=1);

namespace quark\camera;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use quark\math\Vector3;
use function class_exists;
use const INF;
use const NAN;

final class CameraInstructionTest extends TestCase{
	public function testSetPreservesOmittedAndExplicitFalseFields() : void{
		$registry = new CameraPresetRegistry();
		$registry->freeze();
		$packet = CameraInstruction::set(pitch: 20, yaw: 40, ease: CameraEase::IN_OUT_SINE, duration: 2, default: false, ignoreStartingValues: true)->createPacket($registry);
		self::assertInstanceOf(CameraInstructionPacket::class, $packet);
		$set = $packet->getSet();
		self::assertNotNull($set);
		self::assertSame($registry->getId(CameraPreset::FREE), $set->getPreset());
		self::assertFalse($set->getDefault());
		self::assertTrue($set->isIgnoringStartingValuesComponent());
		self::assertNull($set->getCameraPosition());
		self::assertNull($packet->getClear());
		$rotation = $set->getRotation();
		$ease = $set->getEase();
		self::assertNotNull($rotation);
		self::assertNotNull($ease);
		self::assertSame(20.0, $rotation->getPitch());
		self::assertSame(40.0, $rotation->getYaw());
		self::assertSame(2.0, $ease->getDuration());
		self::assertSame(CameraEase::IN_OUT_SINE->toProtocol(), $ease->getType());
	}

	/** @return iterable<string, array{\Closure(): CameraInstruction}> */
	public static function invalidInstructions() : iterable{
		yield "NaN" => [static fn() => CameraInstruction::fov(NAN)];
		yield "infinite duration" => [static fn() => CameraInstruction::set(duration: INF)];
		yield "negative duration" => [static fn() => CameraInstruction::set(duration: -1)];
		yield "missing yaw" => [static fn() => CameraInstruction::set(pitch: 1)];
		yield "pitch range" => [static fn() => CameraInstruction::set(pitch: 91, yaw: 0)];
		yield "yaw range" => [static fn() => CameraInstruction::set(pitch: 0, yaw: 181)];
		yield "FOV zero" => [static fn() => CameraInstruction::fov(0)];
		yield "FOV range" => [static fn() => CameraInstruction::fov(180)];
		yield "fade limit" => [static fn() => CameraInstruction::fade(10.1)];
		yield "fade color" => [static fn() => CameraInstruction::fade(red: -0.1)];
		yield "shake range" => [static fn() => CameraInstruction::shake(4.1, 1)];
		yield "invalid fog" => [static fn() => CameraInstruction::fog(["bad"])];
	}

	/** @param \Closure(): CameraInstruction $create */
	#[DataProvider("invalidInstructions")]
	public function testInvalidInstructionsFail(\Closure $create) : void{
		$this->expectException(\InvalidArgumentException::class);
		$create();
	}

	public function testGameplayPerspectiveRejectsFreeCameraTransitions() : void{
		$registry = new CameraPresetRegistry();
		$registry->freeze();
		$this->expectException(\InvalidArgumentException::class);
		CameraInstruction::set(CameraPreset::FIRST_PERSON, duration: 1)->createPacket($registry);
	}

	public function testVectorDefinitionsSnapshotPlainValues() : void{
		if(!class_exists(Vector3::class)){
			self::markTestSkipped("Requires ext-qmath");
		}
		$registry = new CameraPresetRegistry();
		$registry->freeze();
		$position = new Vector3(1, 2, 3);
		$instruction = CameraInstruction::set(position: $position);
		$position->x = 99;
		$packet = $instruction->createPacket($registry);
		self::assertInstanceOf(CameraInstructionPacket::class, $packet);
		$set = $packet->getSet();
		self::assertNotNull($set);
		$vector = $set->getCameraPosition();
		self::assertNotNull($vector);
		self::assertSame(1.0, (float) $vector->x);
		$vector->x = 100;
		$next = $instruction->createPacket($registry);
		self::assertInstanceOf(CameraInstructionPacket::class, $next);
		self::assertEquals(new Vector3(1, 2, 3), $next->getSet()?->getCameraPosition());
	}

	public function testInstructionSerializationRoundTrip() : void{
		if(!class_exists(ByteBufferWriter::class)){
			self::markTestSkipped("Requires ext-encoding");
		}
		$registry = new CameraPresetRegistry();
		$registry->freeze();
		foreach([
			CameraInstruction::set(pitch: -20, yaw: 40, duration: 0.5, default: false),
			CameraInstruction::clear(), CameraInstruction::fade(), CameraInstruction::fov(90, 1),
			CameraInstruction::fov(null), CameraInstruction::stopTracking(), CameraInstruction::detach()
		] as $instruction){
			$packet = $instruction->createPacket($registry);
			$out = new ByteBufferWriter();
			$packet->encode($out);
			$decoded = new CameraInstructionPacket();
			$decoded->decode(new ByteBufferReader($out->getData()));
			self::assertEquals($packet, $decoded);
		}
	}

	public function testPresetSerializationRoundTrip() : void{
		if(!class_exists(ByteBufferWriter::class)){
			self::markTestSkipped("Requires ext-encoding");
		}
		$registry = new CameraPresetRegistry();
		$registry->register(new CameraPreset("test:free", xPosition: 1, playerEffects: false, applyInheritedStartingRotation: true));
		$packet = $registry->createPacket();
		$out = new ByteBufferWriter();
		$packet->encode($out);
		$decoded = new CameraPresetsPacket();
		$decoded->decode(new ByteBufferReader($out->getData()));
		self::assertEquals($packet, $decoded);
	}
}
