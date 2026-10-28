<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\network\mcpe\protocol;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use quark\block\Block;
use quark\block\VanillaBlocks;
use quark\entity\animation\Animation;
use quark\entity\Entity;
use quark\entity\Zombie;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\utils\Filesystem;
use quark\world\particle\Particle;
use quark\world\sound\Sound;
use function array_map;
use function basename;
use function bin2hex;
use function class_exists;
use function count;
use function dirname;
use function file_put_contents;
use function getenv;
use function glob;
use function is_a;
use function json_decode;
use function json_encode;
use function ksort;
use function str_replace;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Compares what sounds, particles and animations send with bytes recorded from the BedrockProtocol encoders they
 * replaced. Set QUARK_WRITE_GOLDEN=1 to record them again.
 */
final class GameplayPacketGoldenTest extends TestCase{
	private const GOLDEN = __DIR__ . "/golden/gameplay.json";

	private const DIRECTORIES = [
		"world/sound" => Sound::class,
		"world/particle" => Particle::class,
		"entity/animation" => Animation::class,
	];

	private static function hex(Packet|ClientboundPacket $packet) : string{
		if($packet instanceof Packet){
			return bin2hex(EncodedPacket::of($packet)->getBytes());
		}
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		return bin2hex($writer->getData());
	}

	private function argument(\ReflectionParameter $parameter) : mixed{
		if($parameter->isDefaultValueAvailable()){
			return $parameter->getDefaultValue();
		}
		$type = $parameter->getType();
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : self::fail("Cannot construct an argument for " . $parameter->getName());
		return match(true){
			$name === "int" => 3,
			$name === "float" => 0.5,
			$name === "bool" => true,
			$name === "string" => "test",
			$name === Vector3::class => new Vector3(1, 2, 3),
			$name === Color::class => new Color(10, 20, 30),
			is_a($name, Block::class, true) => self::first(VanillaBlocks::getAll(), $name),
			is_a($name, Item::class, true) => self::first(VanillaItems::getAll(), $name),
			is_a($name, Entity::class, true) => $this->entity($name),
			is_a($name, \UnitEnum::class, true) => $name::cases()[0],
			default => self::fail("Cannot construct a $name for " . $parameter->getName())
		};
	}

	/**
	 * Returns the first registered member of the given class, so the choice does not depend on hash order.
	 *
	 * @param object[] $members
	 */
	private static function first(array $members, string $class) : object{
		ksort($members);
		foreach($members as $member){
			if($member instanceof $class){
				return $member;
			}
		}
		self::fail("No registered $class");
	}

	/**
	 * @phpstan-param class-string<Entity> $class
	 */
	private function entity(string $class) : Entity{
		if((new \ReflectionClass($class))->isAbstract()){
			$class = Zombie::class;
		}
		$entity = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(["getId"])->getMock();
		$entity->method("getId")->willReturn(7);
		//close() is final, and would despawn an entity that was never spawned
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($entity, true);
		return $entity;
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function encodeAll() : array{
		$entityCount = new \ReflectionProperty(Entity::class, "entityCount");
		$out = [];
		foreach(self::DIRECTORIES as $directory => $interface){
			$files = glob(dirname(__DIR__, 5) . "/src/$directory/*.php");
			self::assertNotFalse($files);
			foreach($files as $file){
				$class = "quark\\" . str_replace("/", "\\", $directory) . "\\" . basename($file, ".php");
				if(!class_exists($class)){
					continue;
				}
				$reflect = new \ReflectionClass($class);
				if(!$reflect->isInstantiable() || !$reflect->implementsInterface($interface)){
					continue;
				}
				$entityCount->setValue(null, 1000);
				$arguments = array_map($this->argument(...), $reflect->getConstructor()?->getParameters() ?? []);
				$instance = $reflect->newInstanceArgs($arguments);
				$packets = [];
				//encoding twice covers particles that change what they send once they have been shown
				for($i = 0; $i < 2; ++$i){
					$encoded = match(true){
						$instance instanceof Animation => $instance->encode(),
						$instance instanceof Sound, $instance instanceof Particle => $instance->encode(new Vector3(1.5, 64.0, -2.25)),
						default => self::fail("Unexpected $class")
					};
					foreach($encoded as $packet){
						$packets[] = self::hex($packet);
					}
				}
				$out[$class] = $packets;
			}
		}
		ksort($out);
		return $out;
	}

	public function testGameplayPacketsMatchTheirRecordedEncoding() : void{
		$actual = $this->encodeAll();
		self::assertGreaterThan(100, count($actual));
		if(getenv("QUARK_WRITE_GOLDEN") === "1"){
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
			self::markTestSkipped("Recorded " . self::GOLDEN);
		}
		self::assertSame(json_decode(Filesystem::fileGetContents(self::GOLDEN), true, flags: JSON_THROW_ON_ERROR), $actual);
	}
}
