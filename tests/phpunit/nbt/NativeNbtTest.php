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

namespace quark\nbt;

use Bedwire\Nbt as NativeNbt;
use Bedwire\NbtException;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\BaseNbtSerializer;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\InvalidTagValueException;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use quark\data\bedrock\BedrockDataFiles;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function array_fill;
use function array_map;
use function chr;
use function count;
use function dirname;
use function glob;
use function mt_rand;
use function mt_srand;
use function pack;
use function range;
use function str_repeat;
use function strlen;
use function substr;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Checks Bedwire\Nbt against pocketmine/nbt's PHP serializers, which Quark used before.
 */
final class NativeNbtTest extends TestCase{

	/**
	 * @phpstan-return \Generator<string, array{int, BaseNbtSerializer}, void, void>
	 */
	public static function formatProvider() : \Generator{
		yield "java" => [NativeNbt::JAVA, new BigEndianNbtSerializer()];
		yield "bedrock" => [NativeNbt::BEDROCK, new LittleEndianNbtSerializer()];
		yield "network" => [NativeNbt::NETWORK, new ReferenceNetworkNbtSerializer()];
	}

	private static function varint(int $value) : string{
		$result = "";
		do{
			$byte = $value & 0x7f;
			$value >>= 7;
			$result .= chr($value !== 0 ? $byte | 0x80 : $byte);
		}while($value !== 0);
		return $result;
	}

	private static function encodeName(int $format, string $name) : string{
		return match($format){
			NativeNbt::JAVA => pack("n", strlen($name)),
			NativeNbt::BEDROCK => pack("v", strlen($name)),
			default => self::varint(strlen($name))
		} . $name;
	}

	private static function encodeInt(int $format, int $value) : string{
		return match($format){
			NativeNbt::JAVA => pack("N", $value),
			NativeNbt::BEDROCK => pack("V", $value),
			default => self::varint((($value << 1) ^ ($value >> 63)) & 0xffffffff)
		};
	}

	private static function bytes(int $length) : string{
		$result = "";
		for($i = 0; $i < $length; ++$i){
			$result .= chr(mt_rand(0, 255));
		}
		return $result;
	}

	private static function randomTag(int $type, int $depth) : Tag{
		switch($type){
			case NBT::TAG_Byte: return new ByteTag(mt_rand(-128, 127));
			case NBT::TAG_Short: return new ShortTag(mt_rand(-32768, 32767));
			case NBT::TAG_Int: return new IntTag(mt_rand(-2147483648, 2147483647));
			case NBT::TAG_Long: return new LongTag(mt_rand(PHP_INT_MIN, PHP_INT_MAX));
			case NBT::TAG_Float: return new FloatTag(mt_rand(-1000000, 1000000) / 64);
			case NBT::TAG_Double: return new DoubleTag(mt_rand() / 3);
			case NBT::TAG_ByteArray: return new ByteArrayTag(self::bytes(mt_rand(0, 16)));
			case NBT::TAG_String: return new StringTag(self::bytes(mt_rand(0, 12)));
			case NBT::TAG_IntArray: return new IntArrayTag(array_map(fn() => mt_rand(-2147483648, 2147483647), array_fill(0, mt_rand(0, 4), 0)));
			case NBT::TAG_List:
				$elementType = mt_rand(NBT::TAG_Byte, $depth > 0 ? NBT::TAG_IntArray : NBT::TAG_String);
				$items = [];
				for($i = mt_rand(0, 3); $i > 0; --$i){
					$items[] = self::randomTag($elementType, $depth - 1);
				}
				return new ListTag($items, count($items) === 0 && mt_rand(0, 1) === 0 ? NBT::TAG_End : $elementType);
			case NBT::TAG_Compound:
				$compound = new CompoundTag();
				for($i = mt_rand(0, 4); $i > 0; --$i){
					$name = ["a", "b", "0", "17", "name", self::bytes(2)][mt_rand(0, 5)];
					$compound->setTag($name, self::randomTag(mt_rand(NBT::TAG_Byte, $depth > 0 ? NBT::TAG_IntArray : NBT::TAG_String), $depth - 1));
				}
				return $compound;
			default:
				throw new \InvalidArgumentException("Unexpected tag type $type");
		}
	}

	private static function item() : CompoundTag{
		return CompoundTag::create()
			->setString("Name", "minecraft:diamond_sword")
			->setByte("Count", 1)
			->setShort("Damage", 12)
			->setTag("tag", CompoundTag::create()
				->setTag("display", CompoundTag::create()->setString("Name", "§bBlade")->setTag("Lore", new ListTag([new StringTag("line"), new StringTag("")])))
				->setTag("ench", new ListTag([CompoundTag::create()->setShort("id", 9)->setShort("lvl", 5)]))
				->setTag("custom", CompoundTag::create()->setTag("", new ListTag())->setTag("17", new IntArrayTag([1, 2]))));
	}

	private static function edgeCases() : CompoundTag{
		return CompoundTag::create()
			->setTag("long", new ListTag([new LongTag(PHP_INT_MIN), new LongTag(PHP_INT_MAX), new LongTag(0)]))
			->setTag("int", new ListTag([new IntTag(-2147483648), new IntTag(2147483647)]))
			->setTag("ints", new IntArrayTag([0, 1, -1, 2147483647, -2147483648]))
			->setTag("bigInts", new IntArrayTag(range(0, 65535)))
			->setTag("bigBytes", new ByteArrayTag(str_repeat("\x00\xff", 512 * 1024)))
			->setTag("raw", new StringTag("\xff\xfe\x00 not utf-8"))
			->setTag("longest", new StringTag(str_repeat("s", 32767)))
			->setTag("emptyEnd", new ListTag())
			->setTag("emptyTyped", new ListTag([], NBT::TAG_Compound))
			->setTag("lists", new ListTag([new ListTag([new StringTag("a")]), new ListTag()]))
			->setTag("12", CompoundTag::create()->setTag("", CompoundTag::create()->setByte("deep", 1)))
			->setTag("items", new ListTag([self::item(), new CompoundTag()]));
	}

	private static function assertSameTree(TreeRoot $expected, TreeRoot $actual) : void{
		self::assertSame($expected->getName(), $actual->getName());
		self::assertTrue($expected->getTag()->equals($actual->getTag()), "Trees differ");
	}

	private static function rejects(BaseNbtSerializer $reference, string $data, int $maxDepth = 0) : bool{
		try{
			$reference->read($data, maxDepth: $maxDepth);
			return false;
		}catch(NbtDataException){
			return true;
		}
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testWritesMatchPocketMine(int $format, BaseNbtSerializer $reference) : void{
		mt_srand(0x5eed + $format);
		$roots = [new TreeRoot(self::edgeCases(), "edge"), new TreeRoot(self::item())];
		for($i = 0; $i < 300; ++$i){
			$roots[] = new TreeRoot(self::randomTag(NBT::TAG_Compound, 4), self::bytes(mt_rand(0, 3)));
		}
		foreach($roots as $root){
			$expected = $reference->write($root);
			self::assertSame($expected, NativeNbt::write($root, $format));
			self::assertSameTree($reference->read($expected), NativeNbt::read($expected, $format));
		}
		self::assertSame($reference->writeMultiple($roots), NativeNbt::writeMultiple($roots, $format));
		self::assertSame($reference->writeHeadless(self::item()), NativeNbt::writeHeadless(self::item(), $format));
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testOffsetsMatchPocketMine(int $format, BaseNbtSerializer $reference) : void{
		$first = $reference->write(new TreeRoot(self::edgeCases(), "first"));
		$second = $reference->write(new TreeRoot(self::item(), "second"));
		$buffer = "pad" . $first . $second . "tail";

		$offset = 3;
		$expected = $reference->read($buffer, $offset);
		[$root, $end] = NativeNbt::readAt($buffer, $format, 3);
		self::assertSameTree($expected, $root);
		self::assertSame($offset, $end);
		self::assertSame(strlen($first), NativeNbt::length($buffer, $format, 3));

		$expected = $reference->read($buffer, $offset);
		[$root, $end] = NativeNbt::readAt($buffer, $format, $end);
		self::assertSameTree($expected, $root);
		self::assertSame($offset, $end);

		$headless = "xx" . $reference->writeHeadless(self::item()) . "yy";
		$offset = 2;
		$expectedTag = $reference->readHeadless($headless, NBT::TAG_Compound, $offset);
		[$tag, $end] = NativeNbt::readHeadlessAt($headless, $format, NBT::TAG_Compound, 2);
		self::assertTrue($expectedTag->equals($tag));
		self::assertSame($offset, $end);

		$native = NativeNbt::readMultiple($first . $second, $format);
		self::assertCount(2, $native);
		foreach($reference->readMultiple($first . $second) as $i => $expectedRoot){
			self::assertSameTree($expectedRoot, $native[$i]);
		}
		self::assertSame([], NativeNbt::readMultiple("", $format));
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testDuplicateNamesKeepTheFirstValue(int $format, BaseNbtSerializer $reference) : void{
		$entry = fn(int $value) => "\x03" . self::encodeName($format, "x") . self::encodeInt($format, $value);
		$data = "\x0a" . self::encodeName($format, "") . $entry(1) . $entry(2) . "\x00";

		$actual = NativeNbt::read($data, $format);
		self::assertSameTree($reference->read($data), $actual);
		self::assertSame(1, $actual->mustGetCompoundTag()->getInt("x"));
	}

	private static function nested(Tag $innermost) : TreeRoot{
		$tag = $innermost;
		for($i = 0; $i < 8; ++$i){
			$tag = $i % 2 === 0 ? CompoundTag::create()->setTag("c", $tag) : new ListTag([$tag]);
		}
		return new TreeRoot($tag);
	}

	private static function acceptsDepth(int $format, BaseNbtSerializer $reference, string $data, int $maxDepth) : bool{
		try{
			$reference->read($data, maxDepth: $maxDepth);
			$expected = true;
		}catch(NbtDataException){
			$expected = false;
		}
		try{
			NativeNbt::read($data, $format, 0, $maxDepth);
			$actual = true;
		}catch(NbtException){
			$actual = false;
		}
		self::assertSame($expected, $actual, "maxDepth $maxDepth");
		return $actual;
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testDepthLimitsMatchPocketMine(int $format, BaseNbtSerializer $reference) : void{
		foreach([CompoundTag::create()->setByte("b", 1), new ListTag([new IntTag(1)]), new ListTag(), new IntTag(1)] as $innermost){
			$data = $reference->write(self::nested($innermost));
			for($maxDepth = 0; $maxDepth <= 12; ++$maxDepth){
				self::acceptsDepth($format, $reference, $data, $maxDepth);
			}
		}
	}

	/**
	 * pocketmine/nbt counts entering an empty compound as a level, while the native reader only counts levels that
	 * hold tags, so one more empty compound is accepted at the limit.
	 *
	 * @dataProvider formatProvider
	 */
	public function testEmptyCompoundsAtTheDepthLimit(int $format, BaseNbtSerializer $reference) : void{
		$data = $reference->write(self::nested(new CompoundTag()));
		self::assertTrue(self::rejects($reference, $data, 8));
		NativeNbt::read($data, $format, 0, 8);
		self::assertTrue(self::rejects($reference, $data, 7));
		$this->expectException(NbtException::class);
		NativeNbt::read($data, $format, 0, 7);
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testMalformedInputIsRefused(int $format, BaseNbtSerializer $reference) : void{
		$noName = self::encodeName($format, "");
		$stringLength = match($format){
			NativeNbt::JAVA => pack("n", 32768),
			NativeNbt::BEDROCK => pack("v", 32768),
			default => self::varint(32768)
		};
		$cases = [
			"empty" => "",
			"end root" => "\x00",
			"unknown type" => "\x63" . $noName,
			"long array" => "\x0c" . $noName . self::encodeInt($format, 0),
			"non-empty end list" => "\x09" . $noName . "\x00" . self::encodeInt($format, 1),
			"negative byte array" => "\x07" . $noName . self::encodeInt($format, -1),
			"oversized string" => "\x08" . $noName . $stringLength . str_repeat("a", 32768),
			"unterminated compound" => "\x0a" . $noName . "\x01" . self::encodeName($format, "b") . "\x01",
		];
		$valid = $reference->write(new TreeRoot(self::item()));
		for($length = 0; $length < strlen($valid); ++$length){
			$cases["truncated to $length"] = substr($valid, 0, $length);
		}

		foreach(Utils::stringifyKeys($cases) as $name => $data){
			self::assertTrue(self::rejects($reference, $data), "pocketmine/nbt accepted $name");
			try{
				NativeNbt::read($data, $format);
				self::fail("Bedwire\\Nbt accepted $name");
			}catch(NbtException){
				self::addToAssertionCount(1);
			}
		}
	}

	/**
	 * pocketmine/nbt reads a negative list length as an empty list and keeps unknown element types of empty lists.
	 * Neither is ever written by Minecraft or Quark, so the native reader refuses them.
	 *
	 * @dataProvider formatProvider
	 */
	public function testInvalidListHeadersAreRefused(int $format, BaseNbtSerializer $reference) : void{
		$noName = self::encodeName($format, "");
		foreach([
			"\x09" . $noName . "\x01" . self::encodeInt($format, -1),
			"\x09" . $noName . "\x63" . self::encodeInt($format, 0),
		] as $data){
			$list = $reference->read($data)->getTag();
			self::assertInstanceOf(ListTag::class, $list);
			self::assertCount(0, $list->getValue());
			try{
				NativeNbt::read($data, $format);
				self::fail("Bedwire\\Nbt accepted an invalid list header");
			}catch(NbtException){
				self::addToAssertionCount(1);
			}
		}
	}

	/**
	 * Random corruption must either be refused by both readers or read identically, apart from the invalid encodings
	 * pocketmine/nbt tolerates.
	 *
	 * @dataProvider formatProvider
	 */
	public function testCorruptInputNeverDiverges(int $format, BaseNbtSerializer $reference) : void{
		mt_srand(0xc0ffee + $format);
		for($document = 0; $document < 150; ++$document){
			$bytes = $reference->write(new TreeRoot(self::randomTag(NBT::TAG_Compound, 4)));
			for($mutation = 0; $mutation < 30; ++$mutation){
				$data = $bytes;
				$length = strlen($data);
				switch(mt_rand(0, 2)){
					case 0:
						$data[mt_rand(0, $length - 1)] = chr(mt_rand(0, 255));
						break;
					case 1:
						$data = substr($data, 0, mt_rand(0, $length - 1));
						break;
					default:
						$at = mt_rand(0, $length);
						$data = substr($data, 0, $at) . self::bytes(mt_rand(1, 4)) . substr($data, $at);
				}

				$expected = null;
				$expectedEnd = 0;
				try{
					$expected = $reference->read($data, $expectedEnd);
				}catch(NbtDataException|InvalidTagValueException){ // @phpstan-ignore-line - pocketmine/nbt leaks InvalidTagValueException for out of range network varints
				}
				try{
					[$actual, $actualEnd] = NativeNbt::readAt($data, $format);
				}catch(NbtException $e){
					if($expected !== null){
						self::assertMatchesRegularExpression('/InvalidLength|InvalidTag|VarIntOverflow/', $e->getMessage());
					}
					continue;
				}
				self::assertNotNull($expected, "Bedwire\\Nbt accepted input pocketmine/nbt refused");
				self::assertSameTree($expected, $actual);
				self::assertSame($expectedEnd, $actualEnd);
			}
		}
	}

	public function testBedrockDataFixturesMatchPocketMine() : void{
		$reference = new ReferenceNetworkNbtSerializer();
		$files = glob(dirname(BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT) . "/*.nbt");
		self::assertNotFalse($files);
		self::assertNotEmpty($files);
		foreach($files as $file){
			$data = Filesystem::fileGetContents($file);
			$expected = $reference->readMultiple($data);
			$actual = NativeNbt::readMultiple($data, NativeNbt::NETWORK);
			self::assertCount(count($expected), $actual, $file);
			foreach($expected as $i => $root){
				self::assertSameTree($root, $actual[$i]);
			}
			self::assertSame($reference->writeMultiple($expected), NativeNbt::writeMultiple($actual, NativeNbt::NETWORK), $file);
			self::assertSame((new LittleEndianNbtSerializer())->writeMultiple($expected), NativeNbt::writeMultiple($actual, NativeNbt::BEDROCK), $file);
		}
	}

	public function testTranscodingMatchesPocketMine() : void{
		$root = new TreeRoot(self::edgeCases(), "level");
		$java = (new BigEndianNbtSerializer())->write($root);
		$bedrock = (new LittleEndianNbtSerializer())->write($root);
		$network = (new ReferenceNetworkNbtSerializer())->write($root);
		self::assertSame($bedrock, NativeNbt::transcode($java, NativeNbt::JAVA, NativeNbt::BEDROCK));
		self::assertSame($network, NativeNbt::transcode($bedrock, NativeNbt::BEDROCK, NativeNbt::NETWORK));
		self::assertSame($java, NativeNbt::transcode($network, NativeNbt::NETWORK, NativeNbt::JAVA));
	}

	public function testFixedWidthIntArraysReadUnsigned() : void{
		$root = new TreeRoot(CompoundTag::create()->setTag("a", new IntArrayTag([-1, -2147483648])));
		foreach([NativeNbt::JAVA => new BigEndianNbtSerializer(), NativeNbt::BEDROCK => new LittleEndianNbtSerializer()] as $format => $reference){
			$data = NativeNbt::write($root, $format);
			self::assertSame([4294967295, 2147483648], NativeNbt::read($data, $format)->mustGetCompoundTag()->getIntArray("a"));
			self::assertSame($reference->read($data)->mustGetCompoundTag()->getIntArray("a"), NativeNbt::read($data, $format)->mustGetCompoundTag()->getIntArray("a"));
		}
		self::assertSame([-1, -2147483648], NativeNbt::read(NativeNbt::write($root, NativeNbt::NETWORK), NativeNbt::NETWORK)->mustGetCompoundTag()->getIntArray("a"));
	}
}
