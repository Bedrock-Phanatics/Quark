<?php

declare(strict_types=1);

namespace quark\network\mcpe\command;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandOverloadRawData;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandParameterRawData;
use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativeFloatArgument;
use quark\command\argument\RotationArgument;
use quark\command\argument\SoftEnum;
use quark\command\argument\SoftEnumArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\SimpleCommandMap;
use quark\command\TestCommandSender;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function array_map;
use function count;

final class CommandMetadataTypesTest extends TestCase{
	private const int VALID = AvailableCommandsPacket::ARG_FLAG_VALID;

	private SimpleCommandMap $map;

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		if($manager->getPermission("test.root") === null){
			$manager->addPermission(new Permission("test.root"));
		}
		$this->map = new SimpleCommandMap(self::createStub(Server::class));
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function player() : Player&Stub{
		$player = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$player->method("getLanguage")->willReturn(TestCommandSender::language());
		$player->method("hasPermission")->willReturnCallback(fn(Permission|string $name) : bool => ($name instanceof Permission ? $name->getName() : $name) === "test.root");
		return $player;
	}

	private function command(string $name) : Command{
		$command = new class($name) extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		return $command;
	}

	/**
	 * @param int[] $versions
	 * @phpstan-param array<string, int> $versions
	 */
	private function packet(array &$versions = []) : AvailableCommandsPacket{
		return CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(), $versions);
	}

	/**
	 * @return CommandParameterRawData[][]
	 * @phpstan-return list<list<CommandParameterRawData>>
	 */
	private static function overloads(AvailableCommandsPacket $packet, int $command = 0) : array{
		return array_map(fn(CommandOverloadRawData $o) => $o->getParameters(), $packet->commandData[$command]->getOverloads());
	}

	public function testPositionRotationAndRelativeValueTypes() : void{
		$command = $this->command("tpish");
		$command->registerArguments([new PositionArgument("pos"), new RotationArgument("rot", optional: true)]);
		$command->syntax("one")->registerArgument(new RelativeFloatArgument("amount"));
		$this->map->register("test", $command);

		$overloads = self::overloads($this->packet());
		self::assertEquals([
			new CommandParameterRawData("pos", self::VALID | AvailableCommandsPacket::ARG_TYPE_POSITION, false, 0),
			new CommandParameterRawData("yaw", self::VALID | AvailableCommandsPacket::ARG_TYPE_VALUE, true, 0),
			new CommandParameterRawData("pitch", self::VALID | AvailableCommandsPacket::ARG_TYPE_VALUE, true, 0),
		], $overloads[0]);
		self::assertSame("amount", $overloads[1][1]->getName());
		self::assertSame(self::VALID | AvailableCommandsPacket::ARG_TYPE_VALUE, $overloads[1][1]->getTypeInfo());
	}

	public function testSharedEnumDefinitionsAreAdvertisedOnceAndClashingNamesAreSeparated() : void{
		$shared = CommandEnum::fromValues("Mode", ["fast", "slow"]);
		$first = $this->command("first");
		$first->registerArgument(new EnumArgument("a", $shared));
		$second = $this->command("second");
		$second->registerArgument(new EnumArgument("b", $shared));
		$third = $this->command("third");
		$third->registerArgument(new EnumArgument("c", CommandEnum::fromValues("Mode", ["other"])));
		foreach([$first, $second, $third] as $command){
			$this->map->register("test", $command);
		}

		$packet = $this->packet();
		self::assertSame(["Mode", "Mode#2"], array_map(fn($e) => $e->getName(), $packet->enums));
		$indexes = array_map(fn(int $i) => self::overloads($packet, $i)[0][0]->getTypeInfo(), [0, 1, 2]);
		self::assertSame($indexes[0], $indexes[1]);
		self::assertNotSame($indexes[0], $indexes[2]);
	}

	public function testSoftEnumsCarryTheirCurrentValuesAndVersions() : void{
		$warps = new SoftEnum("warps", ["spawn", "home"]);
		$command = $this->command("warp");
		$command->syntax()->registerArgument(new SoftEnumArgument("name", $warps));
		$command->syntax("delete")->registerArgument(new SoftEnumArgument("name", $warps));
		$this->map->register("test", $command);
		$warps->add("mine");

		$versions = [];
		$packet = $this->packet($versions);
		self::assertCount(1, $packet->softEnums, "one enum object is shared by both overloads");
		self::assertSame(["spawn", "home", "mine"], $packet->softEnums[0]->getValues());
		self::assertSame(["warps" => $warps->getVersion()], $versions);
		[$plain, $delete] = self::overloads($packet);
		self::assertSame(AvailableCommandsPacket::ARG_FLAG_SOFT_ENUM | self::VALID | 0, $plain[0]->getTypeInfo());
		self::assertSame(AvailableCommandsPacket::ARG_FLAG_SOFT_ENUM | self::VALID | 0, $delete[1]->getTypeInfo());

		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new AvailableCommandsPacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		self::assertEquals($packet->softEnums, $decoded->softEnums);
		self::assertEquals($packet->commandData, $decoded->commandData);
	}

	public function testHiddenOverloadsDoNotAdvertiseTheirSoftEnums() : void{
		$secret = new SoftEnum("secret", ["x"]);
		$command = $this->command("warp");
		$command->syntax("list")->registerArgument(new IntegerArgument("page", optional: true));
		$command->syntax("hidden")->permission("test.admin")->registerArgument(new SoftEnumArgument("name", $secret));
		$manager = PermissionManager::getInstance();
		if($manager->getPermission("test.admin") === null){
			$manager->addPermission(new Permission("test.admin"));
		}
		$this->map->register("test", $command);

		$versions = [];
		$packet = $this->packet($versions);
		self::assertSame([], $packet->softEnums);
		self::assertSame([], $versions);
	}

	public function testPlayersWithTheSameCommandsShareOnePacketPerResync() : void{
		$manager = PermissionManager::getInstance();
		if($manager->getPermission("test.admin") === null){
			$manager->addPermission(new Permission("test.admin"));
		}
		$command = $this->command("warp");
		$command->syntax("list");
		$command->syntax("admin")->permission("test.admin");
		$this->map->register("test", $command);

		$cache = new CommandMetadataCache();
		$commands = $this->map->getCommands();
		$versions = [];
		$first = $cache->get($commands, $this->player(), $versions);
		self::assertSame($first, $cache->get($commands, $this->player(), $versions));

		$admin = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$admin->method("getLanguage")->willReturn(TestCommandSender::language());
		$admin->method("hasPermission")->willReturn(true);
		$other = $cache->get($commands, $admin, $versions);
		self::assertNotSame($first, $other, "a player who may use more syntaxes needs their own packet");
		$overloadCount = static function(AvailableCommandsPacket $packet) : int{
			foreach($packet->commandData as $data){
				if($data->getName() === "warp"){
					return count($data->getOverloads());
				}
			}
			return 0;
		};
		self::assertSame([2, 3], [$overloadCount($first), $overloadCount($other)]);
	}
}
