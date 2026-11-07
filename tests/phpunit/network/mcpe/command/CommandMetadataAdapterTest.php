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

namespace quark\network\mcpe\command;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandEnumRawData;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandOverloadRawData;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandParameterRawData;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandRawData;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentKind;
use quark\command\argument\BooleanArgument;
use quark\command\argument\EnumArgument;
use quark\command\argument\FloatArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\SimpleCommandMap;
use quark\command\Subcommand;
use quark\command\TestCommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function array_map;
use function array_values;
use function in_array;
use function str_repeat;
use function strlen;

final class CommandMetadataAdapterTest extends TestCase{
	private const int VALID = AvailableCommandsPacket::ARG_FLAG_VALID;
	private const int ENUM = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_FLAG_ENUM;
	private const int STRING = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_STRING;
	private const int INT = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_INT;
	private const int FLOAT = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_FLOAT;
	private const int TEXT = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_RAWTEXT;
	private const int TARGET = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_TARGET;

	private SimpleCommandMap $map;

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		foreach(["test.root", "test.admin"] as $name){
			if($manager->getPermission($name) === null){
				$manager->addPermission(new Permission($name));
			}
		}
		$this->map = new SimpleCommandMap(self::createStub(Server::class));
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	/**
	 * @param string[] $permissions
	 */
	private function player(array $permissions) : Player&Stub{
		$player = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$player->method("getLanguage")->willReturn(TestCommandSender::language());
		$player->method("hasPermission")->willReturnCallback(fn(Permission|string $name) : bool => in_array($name instanceof Permission ? $name->getName() : $name, $permissions, true));
		return $player;
	}

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	private function command(string $name, array $aliases = [], string $description = "") : Command{
		$command = new class($name, $description, null, $aliases) extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		return $command;
	}

	private function eco() : Command{
		$command = $this->command("eco", description: "Economy");
		$command->syntax("pay")->registerArguments([new PlayerArgument("target"), new IntegerArgument("amount", min: 1)]);
		$command->syntax("admin give")->permission("test.admin")->registerArguments([new PlayerArgument("target"), new IntegerArgument("amount", min: 1)]);
		$command->syntax("balance")->registerArgument(new PlayerArgument("target", optional: true));
		return $command;
	}

	private static function parameter(string $name, int $parseSymbol, bool $optional = false) : CommandParameterRawData{
		return new CommandParameterRawData($name, $parseSymbol, $optional, 0);
	}

	/**
	 * @param CommandParameterRawData[] ...$overloads
	 * @phpstan-param list<CommandParameterRawData> ...$overloads
	 */
	private static function data(string $name, string $description, int $aliasEnum, array ...$overloads) : CommandRawData{
		return new CommandRawData(
			name: $name,
			description: $description,
			flags: 0,
			permission: CommandPermissions::NORMAL->value,
			aliasEnumIndex: $aliasEnum,
			chainedSubCommandDataIndexes: [],
			overloads: array_values(array_map(fn(array $parameters) => new CommandOverloadRawData(false, $parameters), $overloads)),
		);
	}

	public function testOverloadsFollowSyntaxesAndPlayerPermissions() : void{
		$this->map->register("test", $this->eco());

		$user = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root"]));
		self::assertSame(["balance", "pay"], $user->enumValues);
		self::assertEquals([new CommandEnumRawData("literal_balance", [0]), new CommandEnumRawData("literal_pay", [1])], $user->enums);
		self::assertEquals([self::data("eco", "Economy", -1,
			[],
			[self::parameter("balance", self::ENUM | 0), self::parameter("target", self::TARGET, true)],
			[self::parameter("pay", self::ENUM | 1), self::parameter("target", self::TARGET), self::parameter("amount", self::INT)],
		)], $user->commandData);

		$admin = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root", "test.admin"]));
		self::assertSame(["admin", "give", "balance", "pay"], $admin->enumValues);
		self::assertEquals([new CommandEnumRawData("literal_admin", [0]), new CommandEnumRawData("literal_give", [1]), new CommandEnumRawData("literal_balance", [2]), new CommandEnumRawData("literal_pay", [3])], $admin->enums);
		self::assertEquals([
			[],
			[self::parameter("admin", self::ENUM | 0), self::parameter("give", self::ENUM | 1), self::parameter("target", self::TARGET), self::parameter("amount", self::INT)],
			[self::parameter("balance", self::ENUM | 2), self::parameter("target", self::TARGET, true)],
			[self::parameter("pay", self::ENUM | 3), self::parameter("target", self::TARGET), self::parameter("amount", self::INT)],
		], array_map(fn(CommandOverloadRawData $o) => $o->getParameters(), $admin->commandData[0]->getOverloads()));

		$none = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.admin"]));
		self::assertSame([], $none->commandData);
		self::assertSame([], $none->enumValues);
	}

	public function testArgumentKindsMapToClientTypes() : void{
		$custom = new /**
		 * @phpstan-extends Argument<string>
		 */ class("ratio") extends Argument{
			public function parse(string $input, CommandSender $sender) : string{
				return $input;
			}

			public function getKind() : ArgumentKind{
				return ArgumentKind::FLOAT;
			}
		};
		$plain = new /**
		 * @phpstan-extends Argument<string>
		 */ class("word") extends Argument{
			public function parse(string $input, CommandSender $sender) : string{
				return $input;
			}
		};
		$command = $this->command("types");
		$command->syntax("int")->registerArgument(new IntegerArgument("n", min: 1));
		$command->syntax("float")->registerArgument(new FloatArgument("f", 0.0, 1.0));
		$command->syntax("text")->registerArgument(new StringArgument("message", greedy: true));
		$command->syntax("flag")->registerArgument(new BooleanArgument("enabled", optional: true));
		$command->syntax("mode")->registerArgument(new EnumArgument("mode", ["a", "b"]));
		$command->syntax("true");
		$command->syntax("custom")->registerArguments([$custom, $plain]);
		$command->syntax("who")->registerArguments([new PlayerArgument("target"), new PlayerArgument("name", optional: true, suggest: false)]);
		$this->map->register("test", $command);

		$packet = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root"]));
		self::assertSame(["custom", "flag", "true", "false", "float", "int", "mode", "a", "b", "text", "who"], $packet->enumValues);
		self::assertEquals([
			new CommandEnumRawData("literal_custom", [0]),
			new CommandEnumRawData("literal_flag", [1]),
			new CommandEnumRawData("enabled", [2, 3]),
			new CommandEnumRawData("literal_float", [4]),
			new CommandEnumRawData("literal_int", [5]),
			new CommandEnumRawData("literal_mode", [6]),
			new CommandEnumRawData("mode", [7, 8]),
			new CommandEnumRawData("literal_text", [9]),
			new CommandEnumRawData("literal_true", [2]),
			new CommandEnumRawData("literal_who", [10]),
		], $packet->enums);
		self::assertEquals([
			[],
			[self::parameter("custom", self::ENUM | 0), self::parameter("ratio", self::FLOAT), self::parameter("word", self::STRING)],
			[self::parameter("flag", self::ENUM | 1), self::parameter("enabled", self::ENUM | 2, true)],
			[self::parameter("float", self::ENUM | 3), self::parameter("f", self::FLOAT)],
			[self::parameter("int", self::ENUM | 4), self::parameter("n", self::INT)],
			[self::parameter("mode", self::ENUM | 5), self::parameter("mode", self::ENUM | 6)],
			[self::parameter("text", self::ENUM | 7), self::parameter("message", self::TEXT)],
			[self::parameter("true", self::ENUM | 8)],
			[self::parameter("who", self::ENUM | 9), self::parameter("target", self::TARGET), self::parameter("name", self::STRING, true)],
		], array_map(fn(CommandOverloadRawData $o) => $o->getParameters(), $packet->commandData[0]->getOverloads()));
	}

	public function testSubcommandClassesAreAdvertisedLikeSyntaxes() : void{
		$set = new class("set") extends Subcommand{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$set->setPermission("test.admin");
		$set->registerArgument(new StringArgument("name"));
		$command = new class("warp") extends Command{};
		$command->setPermission("test.root");
		$command->registerSubcommand($set);
		$this->map->register("test", $command);

		$overloads = fn(array $permissions) => CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player($permissions))->commandData;
		self::assertSame([], $overloads(["test.root"]));
		$admin = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root", "test.admin"]));
		self::assertEquals([self::parameter("set", self::ENUM | 0), self::parameter("name", self::STRING)], $admin->commandData[0]->getOverloads()[0]->getParameters());
	}

	public function testLegacyCommandsAliasesDescriptionsAndOwnersKeepExistingBehavior() : void{
		$legacy = new class("legacy", str_repeat("é", 600), null, ["lg", "other"]) extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$legacy->setPermission("test.root");
		$this->map->register("test", $legacy);
		$takeover = new class("lg", "") extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$takeover->setPermission("test.root");
		$takeover->setDescription(KnownTranslationFactory::quark_command_userDefined_description());
		$this->map->register("test", $takeover);

		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturn(false);
		$owned = new class("owned", $plugin) extends Command implements PluginOwned{
			public function __construct(string $name, private Plugin $plugin){
				parent::__construct($name);
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{}

			public function getOwningPlugin() : Plugin{
				return $this->plugin;
			}
		};
		$owned->setPermission("test.root");
		$this->map->register("test", $owned);

		$packet = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root", DefaultPermissionNames::COMMAND_HELP]));
		$rawText = [self::parameter("args", self::VALID | AvailableCommandsPacket::ARG_TYPE_RAWTEXT, true)];
		self::assertSame(["other", "legacy"], $packet->enumValues);
		self::assertEquals([new CommandEnumRawData("LegacyAliases", [0, 1])], $packet->enums);
		self::assertCount(2, $packet->commandData);
		[$legacyData, $takeoverData] = $packet->commandData;
		self::assertSame(1000, strlen($legacyData->getDescription()));
		self::assertEquals(self::data("legacy", $legacyData->getDescription(), 0, $rawText), $legacyData);
		self::assertEquals(self::data("lg", "User-defined custom command", -1, $rawText), $takeoverData);
	}

	public function testAliasesAreOnlyAdvertisedWhenATargetSyntaxIsPermitted() : void{
		$server = self::createStub(Server::class);
		$server->method("getCommandAliases")->willReturn(["grant" => ["eco admin give \$1 \$2"], "send" => ["eco pay \$1 \$2"]]);
		$map = new SimpleCommandMap($server);
		$server->method("getCommandMap")->willReturn($map);
		$map->register("test", $this->eco());
		$map->registerServerAliases();

		$names = function(array $permissions) use ($map, $server) : array{
			$player = $this->player($permissions);
			$player->method("getServer")->willReturn($server);
			return array_map(fn(CommandRawData $data) => $data->getName(), CommandMetadataAdapter::createPacket($map->getCommands(), $player)->commandData);
		};
		self::assertSame(["eco", "send"], $names(["test.root"]));
		self::assertSame(["eco", "grant", "send"], $names(["test.root", "test.admin"]));
		self::assertSame([], $names(["test.admin"]));

		$packet = function(array $permissions) use ($map, $server) : AvailableCommandsPacket{
			$player = $this->player($permissions);
			$player->method("getServer")->willReturn($server);
			return CommandMetadataAdapter::createPacket($map->getCommands(), $player);
		};
		$send = $packet(["test.root"])->commandData[1];
		self::assertSame("send", $send->getName());
		self::assertEquals([self::parameter("args", self::TEXT, true)], $send->getOverloads()[0]->getParameters());
	}

	public function testServerAliasesReplaceTheCommandTheyShadow() : void{
		$server = self::createStub(Server::class);
		$server->method("getCommandAliases")->willReturn(["eco" => ["legacy \$1"]]);
		$map = new SimpleCommandMap($server);
		$server->method("getCommandMap")->willReturn($map);
		$map->register("test", $this->eco());
		$legacy = new class("legacy") extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$legacy->setPermission("test.admin");
		$map->register("test", $legacy);
		$map->registerServerAliases();

		$player = $this->player(["test.root", "test.admin"]);
		$player->method("getServer")->willReturn($server);
		$data = [];
		foreach(CommandMetadataAdapter::createPacket($map->getCommands(), $player)->commandData as $command){
			$data[$command->getName()] = $command;
		}
		self::assertEquals([self::parameter("args", self::TEXT, true)], $data["eco"]->getOverloads()[0]->getParameters());
		self::assertCount(1, $data["eco"]->getOverloads());

		//the shadowed command must not be advertised to someone who can't run the alias
		$user = $this->player(["test.root"]);
		$user->method("getServer")->willReturn($server);
		$names = array_map(fn(CommandRawData $command) => $command->getName(), CommandMetadataAdapter::createPacket($map->getCommands(), $user)->commandData);
		self::assertNotContains("eco", $names);
	}

	public function testPacketRoundTripsThroughTheProtocolSerializer() : void{
		$this->map->register("test", $this->eco());
		$command = $this->command("faction", ["f"]);
		$command->syntax("home");
		$command->syntax("home set");
		$command->syntax("claim")->registerArgument(new IntegerArgument("radius", min: 1, max: 8, optional: true));
		$command->syntax("invite")->registerArguments([new PlayerArgument("target"), new StringArgument("message", optional: true, greedy: true)]);
		$command->syntax("toggle")->registerArguments([new BooleanArgument("on"), new FloatArgument("speed", optional: true)]);
		$this->map->register("test", $command);

		$packet = CommandMetadataAdapter::createPacket($this->map->getCommands(), $this->player(["test.root", "test.admin"]));
		self::assertCount(2, $packet->commandData);
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = new AvailableCommandsPacket();
		$decoded->decode(new ByteBufferReader($writer->getData()));
		self::assertEquals($packet->commandData, $decoded->commandData);
		self::assertSame($packet->enumValues, $decoded->enumValues);
		self::assertEquals($packet->enums, $decoded->enums);
	}
}
