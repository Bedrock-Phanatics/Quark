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

namespace quark\network\mcpe\handler;

use Bedwire\PacketException;
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\entity\Location;
use quark\network\FilterNoisyPacketException;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\Packets;
use quark\network\mcpe\protocol\ProtocolClasses;
use quark\network\PacketHandlingException;
use quark\player\GameMode;
use quark\player\Player;
use quark\world\World;
use function bin2hex;
use function chr;
use function explode;
use function file;
use function get_class;
use function getenv;
use function hex2bin;
use function is_numeric;
use function method_exists;
use function mt_rand;
use function mt_srand;
use function ord;
use function strlen;
use function substr;
use const FILE_IGNORE_NEW_LINES;
use const MT_RAND_MT19937;

/**
 * Hands seeded mutations of protocol-zig's client packets to the in-game handler, which may only reject them with a
 * PacketHandlingException, or drop them as noise. Set QUARK_FUZZ_ROUNDS for a longer run and QUARK_FUZZ_SEED for other mutations.
 */
final class InGamePacketHandlerFuzzTest extends TestCase{

	/**
	 * @return list<string> the corpus packets a client sends
	 */
	private static function clientPackets() : array{
		$lines = file(__DIR__ . "/../protocol/golden/corpus.txt", FILE_IGNORE_NEW_LINES);
		self::assertNotFalse($lines);
		$packets = [];
		foreach($lines as $line){
			if($line === "" || $line[0] === "#"){
				continue;
			}
			[$sender, , $hex] = explode(" ", $line, 3);
			$bytes = hex2bin($hex);
			if($sender === "client" && $bytes !== false){
				$packets[] = $bytes;
			}
		}
		return $packets;
	}

	private static function mutate(string $bytes) : string{
		$length = strlen($bytes);
		$at = mt_rand(1, $length - 1);
		return match(mt_rand(0, 3)){
			0 => substr($bytes, 0, $at) . chr(ord($bytes[$at]) ^ (1 << mt_rand(0, 7))) . substr($bytes, $at + 1),
			1 => substr($bytes, 0, $at) . chr(mt_rand(0, 255)) . substr($bytes, $at + 1),
			2 => substr($bytes, 0, $at) . chr(mt_rand(0, 255)) . substr($bytes, $at),
			default => substr($bytes, 0, $at) . substr($bytes, $at + 1),
		};
	}

	private function handler() : InGamePacketHandler{
		//a player standing at the origin of a world of air, so position checks see real values
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getBlock")->willReturn(VanillaBlocks::AIR());
		$world->method("getBlockAt")->willReturn(VanillaBlocks::AIR());
		$location = new Location(0.5, 64.0, 0.5, $world, 0.0, 0.0);
		$player = $this->createMock(Player::class);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturn(new Vector3(0.5, 65.62, 0.5));
		$player->method("getWorld")->willReturn($world);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		return new InGamePacketHandler($player, $this->createMock(NetworkSession::class), $this->createMock(InventoryManager::class));
	}

	public function testMutatedClientPacketsOnlyFailAsHandlingErrors() : void{
		ProtocolClasses::bind();
		$rounds = getenv("QUARK_FUZZ_ROUNDS");
		$rounds = is_numeric($rounds) ? (int) $rounds : 20;
		$seed = getenv("QUARK_FUZZ_SEED");
		mt_srand(is_numeric($seed) ? (int) $seed : 0x5eed, MT_RAND_MT19937);
		$handler = $this->handler();
		$handled = 0;
		foreach(self::clientPackets() as $original){
			$method = Packets::HANDLERS[Protocol::packetId($original)] ?? null;
			if($method === null || !method_exists($handler, $method)){
				continue;
			}
			for($i = 0; $i < $rounds; ++$i){
				$bytes = $i === 0 ? $original : self::mutate($original);
				try{
					$packet = EncodedPacket::fromBytes($bytes)->getPacket();
				}catch(PacketException){
					continue;
				}
				if(!isset(Packets::HANDLERS[$packet->getId()]) || Packets::HANDLERS[$packet->getId()] !== $method){
					continue;
				}
				self::handle($handler, $method, $packet, $bytes);
				++$handled;
			}
		}
		self::assertGreaterThan(0, $handled);
	}

	private static function handle(InGamePacketHandler $handler, string $method, Packet $packet, string $bytes) : void{
		try{
			(new \ReflectionMethod($handler, $method))->invoke($handler, $packet);
		}catch(PacketHandlingException|FilterNoisyPacketException){
			//rejected, or dropped as noise, both of which the session handles
		}catch(\Throwable $e){
			self::fail("$method threw " . get_class($e) . ": " . $e->getMessage() . " for " . bin2hex($bytes) . "\n" . $e->getTraceAsString());
		}
	}
}
