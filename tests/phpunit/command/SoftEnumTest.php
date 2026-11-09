<?php

declare(strict_types=1);

namespace quark\command;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\SoftEnum;
use quark\command\argument\SoftEnumArgument;
use quark\lang\Translatable;
use quark\network\mcpe\command\SoftEnumUpdates;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function array_map;
use function range;
use function str_repeat;

final class SoftEnumTest extends TestCase{
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

	private function command(string $name, SoftEnum $enum) : Command{
		$command = new class($name) extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$command->registerArgument(new SoftEnumArgument("warp", $enum));
		return $command;
	}

	public function testChangesAreCoalescedIntoMinimalDeltas() : void{
		$enum = new SoftEnum("warps", ["spawn"]);
		self::assertSame([[SoftEnum::TYPE_ADD, ["spawn"]]], $enum->consumeChanges(), "the initial values count as pending until a map takes them");
		self::assertSame([], $enum->consumeChanges());

		$enum->add("Home", "mine", "home");
		$enum->remove("SPAWN", "unknown");
		self::assertSame([[SoftEnum::TYPE_REMOVE, ["spawn"]], [SoftEnum::TYPE_ADD, ["Home", "mine"]]], $enum->consumeChanges());
		self::assertSame(["Home", "mine"], $enum->getValues());

		$enum->add("tmp");
		$enum->remove("tmp");
		self::assertSame([], $enum->consumeChanges(), "an add that was removed again never reached clients");
		$enum->remove("mine");
		$enum->add("MINE");
		self::assertSame([], $enum->consumeChanges(), "a removal that was undone changes nothing for clients");

		$enum->add("a");
		$enum->set(["b", "c", "B"]);
		$enum->add("d");
		self::assertSame([[SoftEnum::TYPE_SET, ["b", "c", "d"]]], $enum->consumeChanges());
		self::assertSame("c", $enum->find("C"));
		self::assertTrue($enum->has("D"));
		self::assertFalse($enum->has("a"));
	}

	public function testInvalidValuesAreRejectedWithoutChangingTheEnum() : void{
		$enum = new SoftEnum("warps", ["ok"]);
		foreach(["", "two words", "tab\t", "quo\"te", str_repeat("x", SoftEnum::MAX_VALUE_LENGTH + 1)] as $value){
			try{
				$enum->add($value);
				self::fail("value accepted");
			}catch(\InvalidArgumentException){
				$this->addToAssertionCount(1);
			}
		}
		try{
			$enum->set(["fine", "not fine"]);
			self::fail("set accepted");
		}catch(\InvalidArgumentException){
			self::assertSame(["ok"], $enum->getValues());
		}
		$many = array_map(fn(int $i) => "v$i", range(1, SoftEnum::MAX_VALUES));
		$full = new SoftEnum("full", $many);
		$full->add("v1");
		$this->expectException(\InvalidArgumentException::class);
		$full->add("overflow");
	}

	public function testArgumentReturnsTheStoredSpellingAndTracksChanges() : void{
		$enum = new SoftEnum("warps", ["Spawn"]);
		$argument = new SoftEnumArgument("warp", $enum);
		$sender = self::createStub(CommandSender::class);
		self::assertSame("Spawn", $argument->parse("sPAWN", $sender));
		self::assertTrue($argument->matches("spawn"));
		$enum->remove("spawn");
		self::assertFalse($argument->matches("spawn"));
		$enum->set(array_map(fn(int $i) => "w$i", range(1, 40)));
		try{
			$argument->parse("nope", $sender);
			self::fail("unknown value accepted");
		}catch(ArgumentParseException $e){
			$user = $e->getUserMessage();
			$message = $user instanceof Translatable ? TestCommandSender::language()->translate($user) : $user;
			self::assertStringContainsString("w20", $message);
			self::assertStringNotContainsString("w21", $message);
		}
	}

	public function testRegisteredEnumsReportChangesOncePerBatchWithoutResendingCommands() : void{
		$enum = new SoftEnum("warps", ["spawn"]);
		$this->map->register("test", $this->command("warp", $enum));
		self::assertTrue($this->map->consumeClientMetadataChanges());
		self::assertSame([], $this->map->consumeSoftEnumChanges(), "registering must not report the values clients receive with the command list");

		$enum->add("home");
		$enum->add("mine");
		$enum->remove("spawn");
		self::assertFalse($this->map->consumeClientMetadataChanges());
		$changes = $this->map->consumeSoftEnumChanges();
		self::assertCount(1, $changes);
		[$reported, $pending, $since, $until] = $changes[0];
		self::assertSame($enum, $reported);
		self::assertSame([[SoftEnum::TYPE_REMOVE, ["spawn"]], [SoftEnum::TYPE_ADD, ["home", "mine"]]], $pending);
		self::assertSame([0, 3], [$since, $until]);
		self::assertSame([], $this->map->consumeSoftEnumChanges());
	}

	public function testEnumLifecycleFollowsRegisteredCommands() : void{
		$enum = new SoftEnum("warps", []);
		$first = $this->command("first", $enum);
		$second = $this->command("second", $enum);
		$this->map->register("test", $first);
		$this->map->register("test", $second);
		$this->map->consumeSoftEnumChanges();

		$this->map->unregister($first);
		$enum->add("a");
		self::assertCount(1, $this->map->consumeSoftEnumChanges(), "the second command still uses the enum");

		$this->map->unregister($second);
		$this->map->unregister($second);
		$enum->add("b");
		self::assertSame([], $this->map->consumeSoftEnumChanges(), "nothing is tracked once no command uses the enum");

		$this->map->register("test", $this->command("third", $enum));
		self::assertSame([], $this->map->consumeSoftEnumChanges(), "stale changes from while it was unused are dropped");
		$enum->add("c");
		self::assertCount(1, $this->map->consumeSoftEnumChanges());

		$this->map->clearCommands();
		$enum->add("d");
		self::assertSame([], $this->map->consumeSoftEnumChanges());
	}

	public function testDifferentEnumsCannotShareAName() : void{
		$this->map->register("test", $this->command("first", new SoftEnum("warps", [])));
		$before = $this->map->getCommands();
		try{
			$this->map->register("test", $this->command("second", new SoftEnum("warps", [])));
			self::fail("a second enum with the same name must be rejected");
		}catch(\InvalidArgumentException){
			self::assertSame($before, $this->map->getCommands());
		}

		$twice = $this->command("twice", new SoftEnum("one", []));
		$twice->registerArgument(new SoftEnumArgument("other", new SoftEnum("one", [])));
		$this->expectException(\InvalidArgumentException::class);
		$this->map->register("test", $twice);
	}

	public function testPluginUnloadReleasesItsEnums() : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		$enum = new SoftEnum("warps", []);
		$owned = new class("warp", $plugin) extends Command implements PluginOwned{
			public function __construct(string $name, private Plugin $plugin){
				parent::__construct($name);
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{}

			public function getOwningPlugin() : Plugin{
				return $this->plugin;
			}
		};
		$owned->setPermission("test.root");
		$owned->registerArgument(new SoftEnumArgument("warp", $enum));
		$this->map->register("test", $owned);
		$this->map->consumeSoftEnumChanges();

		$this->map->unregisterPluginSyntaxCommands($plugin);
		self::assertNull($this->map->getCommand("warp"));
		$enum->add("late");
		self::assertSame([], $this->map->consumeSoftEnumChanges());
	}

	public function testClientsOnlyReceiveWhatTheyAreMissing() : void{
		$enum = new SoftEnum("warps", ["a"]);
		$this->map->register("test", $this->command("warp", $enum));
		$this->map->consumeSoftEnumChanges();
		$known = ["warps" => $enum->getVersion()];

		$enum->add("b");
		$enum->remove("a");
		$changes = $this->map->consumeSoftEnumChanges();

		$late = ["warps" => $enum->getVersion()];
		self::assertSame([], SoftEnumUpdates::createPackets($late, $changes), "its command list was built after the change");
		$unknown = [];
		self::assertSame([], SoftEnumUpdates::createPackets($unknown, $changes), "it never received the enum");
		self::assertSame([], $unknown);

		$packets = SoftEnumUpdates::createPackets($known, $changes);
		self::assertSame(
			[["warps", UpdateSoftEnumPacket::TYPE_REMOVE, ["a"]], ["warps", UpdateSoftEnumPacket::TYPE_ADD, ["b"]]],
			array_map(fn(UpdateSoftEnumPacket $p) => [$p->enumName, $p->type, $p->values], $packets)
		);
		self::assertSame(["warps" => $enum->getVersion()], $known);

		//a client halfway through a batch gets the whole list
		$enum->add("c");
		$enum->add("d");
		$batch = $this->map->consumeSoftEnumChanges();
		$halfway = ["warps" => $batch[0][2] + 1];
		$packets = SoftEnumUpdates::createPackets($halfway, $batch);
		self::assertCount(1, $packets);
		self::assertSame([UpdateSoftEnumPacket::TYPE_SET, ["b", "c", "d"]], [$packets[0]->type, $packets[0]->values]);
	}
}
