<?php

declare(strict_types=1);

namespace quark\command\defaults;

use PHPUnit\Framework\MockObject\MockObject;
use quark\permission\DefaultPermissions;
use quark\world\World;
use quark\world\WorldManager;
use function array_filter;
use function implode;
use function str_starts_with;

final class HelpAndWorldCommandsTest extends DefaultCommandTestCase{

	public function testHelpListsPagesAndSingleCommands() : void{
		$console = $this->console();
		$first = $this->dispatch("help", $console);
		self::assertStringStartsWith("--- Showing help page 1 of ", $first[0]);
		self::assertCount(8, $first, "one header and one page of commands");

		$second = $this->dispatch("help 2", $console);
		self::assertStringStartsWith("--- Showing help page 2 of ", $second[0]);
		self::assertNotSame($first, $second);
		self::assertSame($this->dispatch("help 1", $console), $first);
		self::assertSame($this->dispatch("?", $console), $first, "the alias runs the same command");
		self::assertStringStartsWith("--- Showing help page 1 of ", $this->dispatch("help 0", $console)[0]);
		self::assertStringContainsString("page 1 of", $this->dispatch("help -5", $console)[0]);
		$last = $this->dispatch("help 99999", $console);
		self::assertStringNotContainsString("page 99999", $last[0]);

		$tp = implode("\n", $this->dispatch("help tp", $console));
		self::assertStringContainsString("/tp", $tp);
		self::assertStringContainsString("Usage:", $tp);
		self::assertStringContainsString("/tp <destination>", $tp);
		self::assertStringContainsString("/tp <victim> <x y z> [yaw pitch]", $tp);
		self::assertStringContainsString("teleport", $tp, "aliases are listed");
	}

	public function testHelpRejectsUnknownAndHiddenCommands() : void{
		$console = $this->console();
		self::assertSame(["Unknown command: nope. Use /help for a list of available commands."], $this->dispatch("help nope", $console));
		$guest = $this->console([DefaultPermissions::ROOT_USER => true]);
		self::assertSame(["Unknown command: stop. Use /help for a list of available commands."], $this->dispatch("help stop", $guest));
		self::assertNotSame([], array_filter($this->dispatch("help", $guest), fn(string $line) => str_starts_with($line, "/")));
		foreach(["help a b", "help tp 2", "help 1 2", "help 1.5"] as $line){
			$messages = $this->dispatch($line, $console);
			self::assertStringContainsString("Usage: /help", implode("
", $messages), $line);
		}
	}

	private function worlds() : WorldManager&MockObject{
		$manager = self::createMock(WorldManager::class);
		$this->server->method("getWorldManager")->willReturn($manager);
		return $manager;
	}

	public function testWorldListAndLoadedWorldArguments() : void{
		$manager = $this->worlds();
		$manager->method("getWorldNames")->willReturn(["world", "nether"]);
		$loaded = self::createStub(World::class);
		$loaded->method("getPlayers")->willReturn([]);
		$loaded->method("getFolderName")->willReturn("world");
		$manager->method("getWorldByName")->willReturnCallback(fn(string $name) : ?World => $name === "world" ? $loaded : null);
		$console = $this->console();

		self::assertSame(["Worlds: world (0), nether"], $this->dispatch("world", $console));
		self::assertSame(["Worlds: world (0), nether"], $this->dispatch("world LIST", $console));
		self::assertSame(
			["Invalid value for <world>: World \"nether\" is not loaded", "Usage: /world tp <world>"],
			$this->dispatch("world tp nether", $console)
		);
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("world tp world", $console));
		self::assertSame(["Invalid value for <world>: World \"nether\" is not loaded", "Usage: /world unload <world>"], $this->dispatch("world unload nether", $console));
	}

	public function testWorldValidatesArgumentsBeforeTouchingAnything() : void{
		$manager = $this->worlds();
		$manager->expects(self::never())->method("generateWorld");
		$manager->expects(self::never())->method("copyWorld");
		$manager->expects(self::never())->method("convertWorld");
		$console = $this->console();

		foreach([
			"world convert w fat32" => "Invalid value for <format>",
			"world convert w" => "Usage: /world convert <name> <format>",
			"world copy only" => "Usage: /world copy <source> <target>",
			"world create" => "Usage: /world create <name> [generator] [seed]",
			"world create a b c d" => "Usage: /world create <name> [generator] [seed]",
			"world create a nogenerator" => "Invalid value for <generator>",
			"world delete" => "Usage: /world delete <name>",
			"world bogus" => "Usage: /world",
		] as $line => $expected){
			self::assertStringContainsString($expected, implode("\n", $this->dispatch($line, $console)), $line);
		}
	}
}
