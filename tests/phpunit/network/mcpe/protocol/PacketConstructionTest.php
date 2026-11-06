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

use Bedwire\PacketId;
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;
use quark\network\mcpe\protocol\packet\ActorEventPacket;
use quark\network\mcpe\protocol\packet\BossEventPacket;
use quark\network\mcpe\protocol\packet\PlayerListPacket;
use quark\network\mcpe\protocol\packet\SetActorDataPacket;
use quark\network\mcpe\protocol\packet\SetDisplayObjectivePacket;
use quark\network\mcpe\protocol\packet\SetScorePacket;
use quark\network\mcpe\protocol\packet\SetTitlePacket;
use quark\network\mcpe\protocol\types\ChangeEntityScore;
use quark\network\mcpe\protocol\types\ChangeFakePlayerScore;
use quark\network\mcpe\protocol\types\ChangePlayerScore;
use quark\network\mcpe\protocol\types\DataItemBytePayload;
use quark\network\mcpe\protocol\types\DataItemCompoundTagPayload;
use quark\network\mcpe\protocol\types\DataItemEntry;
use quark\network\mcpe\protocol\types\DataItemFloatPayload;
use quark\network\mcpe\protocol\types\DataItemInt64Payload;
use quark\network\mcpe\protocol\types\DataItemIntPayload;
use quark\network\mcpe\protocol\types\DataItemPosPayload;
use quark\network\mcpe\protocol\types\DataItemShortPayload;
use quark\network\mcpe\protocol\types\DataItemStringPayload;
use quark\network\mcpe\protocol\types\DataItemVec3Payload;
use quark\network\mcpe\protocol\types\Downloading;
use quark\network\mcpe\protocol\types\FloatOverride;
use quark\network\mcpe\protocol\types\PlayerListAddEntry;
use quark\network\mcpe\protocol\types\PlayerListRemoveEntry;
use quark\network\mcpe\protocol\types\PropertySyncData;
use quark\network\mcpe\protocol\types\RemoveScore;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function bin2hex;
use function class_exists;
use function explode;
use function file;
use function get_object_vars;
use function hex2bin;
use function is_array;
use function is_object;
use function json_decode;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;

final class PacketConstructionTest extends TestCase{
	private const FIELDS = __DIR__ . "/../../../../../build/codegen/fields.json";

	protected function setUp() : void{
		ProtocolClasses::bind();
	}

	/**
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function fields() : array{
		$fields = json_decode(Filesystem::fileGetContents(self::FIELDS), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($fields);
		/** @phpstan-var array<string, array<string, array<string, mixed>>> $fields */
		return $fields;
	}

	/**
	 * @phpstan-return class-string
	 */
	private static function className(string $file) : string{
		$class = "quark\\network\\mcpe\\protocol\\" . str_replace("/", "\\", $file);
		self::assertTrue(class_exists($class), $class);
		return $class;
	}

	/**
	 * Returns the packet once it decodes to an equal copy.
	 *
	 * @phpstan-template T of Packet
	 * @phpstan-param T $packet
	 * @phpstan-return T
	 */
	private static function roundTrip(Packet $packet) : Packet{
		self::assertEquals($packet, EncodedPacket::of($packet)->getPacket());
		return $packet;
	}

	public function testFixedDiscriminatorsAreNotParameters() : void{
		foreach(Utils::stringifyKeys(self::fields()) as $file => $config){
			$class = new \ReflectionClass(self::className($file));
			foreach(Utils::stringifyKeys($config["fixed"] ?? []) as $name => $value){
				$property = $class->getProperty($name);
				self::assertFalse($property->isPromoted(), "$file::\$$name");
				self::assertTrue($property->isPrivateSet(), "$file::\$$name");
				self::assertSame($value, $property->getDefaultValue(), "$file::\$$name");
			}
		}
	}

	/**
	 * Every discriminator the recorded corpus decodes must be the value its class always sends.
	 */
	public function testFixedDiscriminatorsMatchTheCorpus() : void{
		$fixed = [];
		foreach(Utils::stringifyKeys(self::fields()) as $file => $config){
			foreach(Utils::stringifyKeys($config["fixed"] ?? []) as $name => $value){
				$fixed[self::className($file)][$name] = $value;
			}
		}
		$seen = [];
		$walk = function(mixed $value) use (&$walk, &$seen, $fixed) : void{
			if(is_object($value)){
				$class = $value::class;
				$value = get_object_vars($value);
				foreach(Utils::stringifyKeys($fixed[$class] ?? []) as $name => $expected){
					self::assertSame($expected, $value[$name] ?? null, "$class::\$$name");
					$seen[$class] = true;
				}
			}
			if(is_array($value)){
				foreach($value as $inner){
					$walk($inner);
				}
			}
		};
		$lines = file(__DIR__ . "/golden/corpus.txt", FILE_IGNORE_NEW_LINES);
		self::assertNotFalse($lines);
		foreach($lines as $line){
			if($line === "" || str_starts_with($line, "#")){
				continue;
			}
			$bytes = hex2bin(explode(" ", $line, 3)[2]);
			self::assertNotFalse($bytes);
			$walk(Protocol::decode($bytes, EncodedPacket::MAX_DECODED_VALUES));
		}
		foreach([PlayerListAddEntry::class, PlayerListRemoveEntry::class, Downloading::class, FloatOverride::class] as $class){
			self::assertArrayHasKey($class, $seen, "the corpus no longer covers $class");
		}
	}

	public function testScoreEntriesSendTheirVariantName() : void{
		$fake = new ChangeFakePlayerScore(scoreboardId: 1, objectiveName: "sidebar", scoreValue: 5, fakePlayerName: "Hello");
		$player = new ChangePlayerScore(scoreboardId: 2, objectiveName: "sidebar", scoreValue: 6, playerUniqueId: 7);
		$entity = new ChangeEntityScore(scoreboardId: 3, objectiveName: "sidebar", scoreValue: 8, actorId: 9);
		$remove = new RemoveScore(scoreboardId: 4);
		self::roundTrip(new SetScorePacket([$fake, $player, $entity, $remove]));
		self::assertSame(["changefakeplayer", "changeplayer", "changeentity", "remove"], [$fake->action, $player->action, $entity->action, $remove->action]);
		self::assertNull($remove->objectiveName);

		//variant 3, the action string, then the entry
		self::assertSame(
			"6c0103" . "10" . bin2hex("changefakeplayer") . "02" . "07" . bin2hex("sidebar") . "05000000" . "05" . bin2hex("Hello"),
			bin2hex(EncodedPacket::of(new SetScorePacket([new ChangeFakePlayerScore(1, "sidebar", 5, "Hello")]))->getBytes())
		);
	}

	public function testDiscriminatorsCannotBeChanged() : void{
		$score = new ChangeFakePlayerScore(1, "sidebar", 5, "Hello");
		$this->expectException(\Error::class);
		/** @phpstan-ignore-next-line the point of the test */
		$score->action = "remove";
	}

	public function testMetadataPayloadsSendTheirType() : void{
		$entries = [
			new DataItemEntry(0, new DataItemBytePayload(1)),
			new DataItemEntry(1, new DataItemShortPayload(2)),
			new DataItemEntry(2, new DataItemIntPayload(3)),
			new DataItemEntry(3, new DataItemFloatPayload(0.5)),
			new DataItemEntry(4, new DataItemStringPayload("a")),
			new DataItemEntry(5, new DataItemCompoundTagPayload("\x0a\x00\x00")),
			new DataItemEntry(6, new DataItemPosPayload(new Vector3(1, 2, 3))),
			new DataItemEntry(7, new DataItemInt64Payload(4)),
			new DataItemEntry(8, new DataItemVec3Payload(new Vector3(0.5, 1.5, 2.5))),
		];
		//protocol-zig rejects a payload whose type is not its variant's, so encoding proves the fixed values
		self::roundTrip(new SetActorDataPacket(1, $entries, new PropertySyncData([], []), 0));
	}

	public function testPlayerListRemovalsSendTheirAction() : void{
		$removal = new PlayerListRemoveEntry(str_repeat("\x01", 16));
		self::roundTrip(new PlayerListPacket([$removal]));
		self::assertSame(1, $removal->action);
	}

	public function testTitleFactories() : void{
		$title = self::roundTrip(SetTitlePacket::title("Hello!"));
		self::assertSame([SetTitlePacket::TYPE_SET_TITLE, "Hello!", 0, 0, 0, "", "", ""], [
			$title->titleType, $title->titleText, $title->fadeInTime, $title->stayTime, $title->fadeOutTime,
			$title->xuid, $title->platformOnlineId, $title->filteredTitleMessage,
		]);
		self::assertSame([SetTitlePacket::TYPE_SET_SUBTITLE, "Welcome!"], [SetTitlePacket::subtitle("Welcome!")->titleType, SetTitlePacket::subtitle("Welcome!")->titleText]);
		self::assertSame(SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE, SetTitlePacket::actionBar("x")->titleType);
		self::assertEquals(new SetTitlePacket(SetTitlePacket::TYPE_CLEAR_TITLE), self::roundTrip(SetTitlePacket::clearTitle()));
		self::assertSame(SetTitlePacket::TYPE_RESET_TITLE, SetTitlePacket::resetTitle()->titleType);

		$times = self::roundTrip(SetTitlePacket::animationTimes(10, 70, 20));
		self::assertSame([SetTitlePacket::TYPE_SET_ANIMATION_TIMES, "", 10, 70, 20], [$times->titleType, $times->titleText, $times->fadeInTime, $times->stayTime, $times->fadeOutTime]);
	}

	public function testTitleTypesMatchTheProtocol() : void{
		$types = Protocol::schema()["types"] ?? null;
		self::assertIsArray($types);
		$type = $types["SetTitleTitleType"] ?? null;
		self::assertIsArray($type);
		self::assertSame([
			"clear" => SetTitlePacket::TYPE_CLEAR_TITLE,
			"reset" => SetTitlePacket::TYPE_RESET_TITLE,
			"title" => SetTitlePacket::TYPE_SET_TITLE,
			"subtitle" => SetTitlePacket::TYPE_SET_SUBTITLE,
			"actionbar" => SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE,
			"times" => SetTitlePacket::TYPE_SET_ANIMATION_TIMES,
			"titletextobject" => SetTitlePacket::TYPE_SET_TITLE_JSON,
			"subtitletextobject" => SetTitlePacket::TYPE_SET_SUBTITLE_JSON,
			"actionbartextobject" => SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE_JSON,
		], $type["values"] ?? null);
	}

	public function testObjectiveFactories() : void{
		$sidebar = self::roundTrip(SetDisplayObjectivePacket::sidebar("obj", "Title"));
		self::assertSame(["sidebar", "obj", "Title", "dummy", SetDisplayObjectivePacket::SORT_ORDER_ASCENDING], [
			$sidebar->displaySlotName, $sidebar->objectiveName, $sidebar->objectiveDisplayName, $sidebar->criteriaName, $sidebar->sortOrder,
		]);
		self::assertSame(SetDisplayObjectivePacket::SORT_ORDER_DESCENDING, SetDisplayObjectivePacket::list("obj", "Title", SetDisplayObjectivePacket::SORT_ORDER_DESCENDING)->sortOrder);
		self::assertSame("list", SetDisplayObjectivePacket::list("obj", "Title")->displaySlotName);
		self::assertSame("belowname", SetDisplayObjectivePacket::belowName("obj", "Title")->displaySlotName);
	}

	public function testBossEventFactories() : void{
		$show = self::roundTrip(BossEventPacket::show(5, "Boss", 0.5, color: 2));
		self::assertSame([5, BossEventPacket::TYPE_SHOW, "Boss", "", 0.5, 2, 0], [
			$show->targetActorId, $show->eventType, $show->name, $show->filteredName, $show->healthPercent, $show->color, $show->overlay,
		]);
		self::assertSame([BossEventPacket::TYPE_HIDE, ""], [BossEventPacket::hide(5)->eventType, BossEventPacket::hide(5)->name]);
		self::assertSame(0.25, self::roundTrip(BossEventPacket::healthPercent(5, 0.25))->healthPercent);
		self::assertSame([BossEventPacket::TYPE_TITLE, "New"], [BossEventPacket::title(5, "New")->eventType, BossEventPacket::title(5, "New")->name]);
	}

	public function testDefaultsLeaveTheWireUnchanged() : void{
		self::assertSame(
			EncodedPacket::of(new ActorEventPacket(1, 2, 0, null))->getBytes(),
			EncodedPacket::of(new ActorEventPacket(1, 2))->getBytes()
		);
		self::assertSame(PacketId::ACTOR_EVENT, (new ActorEventPacket(1, 2))->getId());
	}
}
