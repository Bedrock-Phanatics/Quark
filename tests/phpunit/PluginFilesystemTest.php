<?php

declare(strict_types=1);

namespace quark\plugin;

use PHPUnit\Framework\TestCase;
use quark\FilesystemPaths;
use quark\lang\Language;
use quark\permission\Permission;
use quark\Server;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use quark\utils\Utils;
use function array_keys;
use function array_map;
use function assert;
use function bin2hex;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_dir;
use function is_link;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use const PHP_BINARY;
use const PHP_OS_FAMILY;

final class PluginFilesystemTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;
	private MainLogger $logger;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . "/quark-plugins-" . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->paths = new FilesystemPaths($this->root, $this->root . "/plugins");
		mkdir($this->paths->plugins);
		mkdir($this->paths->pluginData(), 0777, true);
		$this->logger = new MainLogger(null, false, "Plugin filesystem test", new \DateTimeZone("UTC"));
	}

	protected function tearDown() : void{
		$this->logger->shutdownLogWriterThread();
		$remove = static function(string $path) use (&$remove) : void{
			if(is_dir($path) && !is_link($path)){
				foreach(new \FilesystemIterator($path) as $child){
					assert($child instanceof \SplFileInfo);
					$remove($child->getPathname());
				}
				rmdir($path);
			}elseif(PHP_OS_FAMILY === "Windows" && is_dir($path)){
				//windows removes a directory symlink with rmdir
				rmdir($path);
			}else{
				unlink($path);
			}
		};
		$remove($this->root);
	}

	private function script(string $file, string $name) : void{
		$namespace = "PluginFilesystem" . bin2hex(random_bytes(8));
		file_put_contents($this->paths->plugins . "/$file", "<?php\nnamespace $namespace;\n/**\n * @name $name\n * @main $namespace\\Main\n * @version 1.0.0\n * @api 6.0.0\n */\nfinal class Main extends \\quark\\plugin\\PluginBase {}\n");
	}

	private function manager(?PluginGraylist $graylist = null) : PluginManager{
		$server = $this->createMock(Server::class);
		$server->method("getApiVersion")->willReturn("6.0.0");
		$server->method("getLogger")->willReturn($this->logger);
		$server->method("getFilesystemPaths")->willReturn($this->paths);
		$language = $this->createMock(Language::class);
		$language->method("translate")->willReturn("plugin status");
		$server->method("getLanguage")->willReturn($language);
		$manager = new PluginManager($server, $this->paths->pluginData(), $graylist);
		$manager->registerInterface(new ScriptPluginLoader());
		return $manager;
	}

	public function testDeterministicLoadLifecycleAndDataPersistence() : void{
		$this->script("z-beta.php", "Beta");
		$this->script("a-alpha.php", "Alpha");
		$manager = $this->manager();
		$errors = 0;
		$plugins = $manager->loadPlugins($this->paths->plugins, $errors);
		self::assertSame(0, $errors);
		self::assertSame(["Alpha", "Beta"], array_keys($plugins));
		$alpha = $plugins["Alpha"];
		self::assertInstanceOf(PluginBase::class, $alpha);
		self::assertSame($this->paths->pluginData() . "/Alpha/", $alpha->getDataFolder());
		$alpha->getConfig()->set("saved", "yes");
		$alpha->saveConfig();
		self::assertTrue($manager->enablePlugin($alpha));
		self::assertTrue($alpha->isEnabled());
		$manager->disablePlugin($alpha);
		self::assertFalse($alpha->isEnabled());
		self::assertFileExists($this->paths->pluginData() . "/Alpha/config.toml");
		$manager->clearPlugins();
		$manager->registerInterface(new ScriptPluginLoader());
		$reloaded = $manager->loadPlugins($this->paths->plugins, $errors);
		self::assertInstanceOf(PluginBase::class, $reloaded["Alpha"]);
		self::assertSame("yes", $reloaded["Alpha"]->getConfig()->get("saved"));
	}

	public function testDisabledPluginKeepsItsData() : void{
		$this->script("a-alpha.php", "Alpha");
		$this->script("z-beta.php", "Beta");
		mkdir($this->paths->pluginData() . "/Beta");
		file_put_contents($this->paths->pluginData() . "/Beta/keep.bin", "persistent");
		$manager = $this->manager(new PluginGraylist(["Alpha"], true));
		$errors = 0;
		self::assertSame(["Alpha"], array_keys($manager->loadPlugins($this->paths->plugins, $errors)));
		self::assertSame(0, $errors);
		self::assertSame("persistent", file_get_contents($this->paths->pluginData() . "/Beta/keep.bin"));
	}

	public function testCaseOnlyPluginNameCollisionCannotShareData() : void{
		$this->script("a-alpha.php", "Alpha");
		$this->script("b-alpha.php", "alpha");
		$manager = $this->manager();
		$errors = 0;
		self::assertSame(["Alpha"], array_keys($manager->loadPlugins($this->paths->plugins, $errors)));
		self::assertSame(1, $errors);
		self::assertCount(1, iterator_to_array(new \FilesystemIterator($this->paths->pluginData())));
	}

	public function testUnsafePluginNamesAreRejected() : void{
		$rejected = 0;
		foreach([".", "..", "CON", "Folder.", "Folder\n"] as $name){
			try{
				new PluginDescription(["name" => $name, "main" => "Example\\Main", "version" => "1.0.0", "api" => ["6.0.0"]]);
				self::fail("Unsafe plugin name $name was accepted");
			}catch(PluginDescriptionParseException){
				++$rejected;
			}
		}
		self::assertSame(5, $rejected);
		self::assertSame("My_Plugin", (new PluginDescription(["name" => "My Plugin", "main" => "Example\\Main", "version" => "1.0.0", "api" => ["6.0.0"]]))->getName());
		$this->expectException(PluginDescriptionParseException::class);
		new PluginDescription(["name" => 42, "main" => "Example\\Main", "version" => "1.0.0", "api" => ["6.0.0"]]);
	}

	public function testTomlManifestAndDeprecatedYamlDescribeTheSamePlugin() : void{
		$toml = new PluginDescription(<<<'TOML'
			name = "Example"
			main = "Example\\Main"
			version = "1.2.0"
			api = "6.0.0"
			depend = ["Core"]

			[commands.hello]
			description = "Says hello"
			aliases = ["hi"]
			permission = "example.hello"

			[permissions."example.hello"]
			default = true
			TOML);
		$yaml = PluginDescription::fromYaml(<<<'YAML'
			name: Example
			main: Example\Main
			version: 1.2.0
			api: 6.0.0
			depend: [Core]
			commands:
			  hello:
			    description: Says hello
			    aliases: [hi]
			    permission: example.hello
			permissions:
			  example.hello:
			    default: true
			YAML);
		foreach([$toml, $yaml] as $description){
			self::assertSame("Example", $description->getName());
			self::assertSame("Example\\Main", $description->getMain());
			self::assertSame("1.2.0", $description->getVersion());
			self::assertSame(["6.0.0"], $description->getCompatibleApis());
			self::assertSame(["Core"], $description->getDepend());
			self::assertSame(["hi"], $description->getCommands()["hello"]->getAliases());
			self::assertSame(["example.hello"], array_map(fn(Permission $permission) => $permission->getName(), $description->getPermissions()["true"] ?? []));
		}
		self::assertFalse($toml->isYamlManifest());
		self::assertTrue($yaml->isYamlManifest());
		$this->expectException(PluginDescriptionParseException::class);
		new PluginDescription("name = ");
	}

	public function testUnquotedDottedPermissionTablesKeepTheirNames() : void{
		$manifest = "name = \"Example\"\nmain = \"Example\\\\Main\"\nversion = \"1.0.0\"\napi = \"6.0.0\"\n";
		$description = new PluginDescription($manifest . "[permissions.example]\ndefault = \"op\"\n[permissions.example.hello]\ndefault = true\n");
		$names = fn(string $default) => array_map(fn(Permission $permission) => $permission->getName(), $description->getPermissions()[$default] ?? []);
		self::assertSame(["example.hello"], $names("true"));
		self::assertSame(["example"], $names("op"));
		$this->expectExceptionMessage("declared twice");
		new PluginDescription($manifest . "[permissions.\"a.b\"]\ndefault = true\n[permissions.a.b]\ndefault = false\n");
	}

	public function testPluginConfigYamlConvertsToToml() : void{
		$this->script("alpha.php", "Alpha");
		mkdir($this->paths->pluginData() . "/Alpha");
		$yaml = "# edited by the owner\nmotd: Welcome\nlimits:\n  players: 20\n  ratio: 0.5\nworlds:\n  - lobby\n  - arena\nunset: ~\n";
		file_put_contents($this->paths->pluginData() . "/Alpha/config.yml", $yaml);
		$errors = 0;
		$alpha = $this->manager()->loadPlugins($this->paths->plugins, $errors)["Alpha"];
		self::assertInstanceOf(PluginBase::class, $alpha);
		$config = $alpha->getConfig();
		self::assertSame("Welcome", $config->get("motd"));
		self::assertSame(["players" => 20, "ratio" => 0.5], $config->get("limits"));
		self::assertSame(["lobby", "arena"], $config->get("worlds"));
		self::assertArrayHasKey("unset", $config->getAll());
		self::assertNull($config->getAll()["unset"]);
		self::assertFileExists($this->paths->pluginData() . "/Alpha/config.toml");
		self::assertFileDoesNotExist($this->paths->pluginData() . "/Alpha/config.yml");
		$snapshots = glob($this->paths->backups() . "/yaml-*/config.yml");
		self::assertIsArray($snapshots);
		self::assertCount(1, $snapshots);
		self::assertSame($yaml, file_get_contents($snapshots[0]));
	}

	public function testPharManifestsAndBundledConfigs() : void{
		$classLoader = new ThreadSafeClassLoader();
		$classLoader->register(true);
		$build = function(string $name, array $files) : void{
			$source = $this->root . "/src-$name";
			$files["src/Main.php"] = "<?php\nnamespace $name;\nfinal class Main extends \\quark\\plugin\\PluginBase {}\n";
			foreach(Utils::stringifyKeys($files) as $path => $contents){
				@mkdir(dirname("$source/$path"), 0777, true);
				file_put_contents("$source/$path", $contents);
			}
			exec(escapeshellarg(PHP_BINARY) . " -n -d phar.readonly=0 " . escapeshellarg(dirname(__DIR__) . "/build-plugin-phar.php") . " " . escapeshellarg($source) . " " . escapeshellarg($this->paths->plugins . "/$name.phar") . " 2>&1", $output, $status);
			self::assertSame(0, $status, implode("\n", $output));
		};
		$prefix = "PharManifest" . bin2hex(random_bytes(4));
		$build("{$prefix}Legacy", [
			"plugin.yml" => "name: Legacy\nmain: {$prefix}Legacy\\Main\nsrc-namespace-prefix: {$prefix}Legacy\nversion: 1.0.0\napi: 6.0.0\n",
			"resources/config.yml" => "# legacy default\ngreeting: hi\n"
		]);
		$build("{$prefix}Modern", [
			"plugin.toml" => "name = \"Modern\"\nmain = \"{$prefix}Modern\\\\Main\"\nsrc-namespace-prefix = \"{$prefix}Modern\"\nversion = \"2.0.0\"\napi = \"6.0.0\"\n",
			"plugin.yml" => "name: Modern\nmain: {$prefix}Modern\\Main\nsrc-namespace-prefix: {$prefix}Modern\nversion: 1.0.0\napi: 6.0.0\n",
			"resources/config.yml" => "greeting: hello\n"
		]);
		$manager = $this->manager();
		$manager->registerInterface(new PharPluginLoader($classLoader));
		$errors = 0;
		$plugins = $manager->loadPlugins($this->paths->plugins, $errors);
		self::assertSame(0, $errors);
		[$legacy, $modern] = [$plugins["Legacy"], $plugins["Modern"]];
		self::assertInstanceOf(PluginBase::class, $legacy);
		self::assertInstanceOf(PluginBase::class, $modern);
		self::assertSame("2.0.0", $modern->getDescription()->getVersion(), "plugin.toml wins over plugin.yml");
		self::assertTrue($legacy->getDescription()->isYamlManifest());
		self::assertSame("hi", $legacy->getConfig()->get("greeting"));
		self::assertSame("# legacy default\ngreeting: hi\n", file_get_contents($this->paths->pluginData() . "/Legacy/config.yml"));
		self::assertFileDoesNotExist($this->paths->pluginData() . "/Legacy/config.toml");
		self::assertSame("hello", $modern->getConfig()->get("greeting"));
		self::assertFileExists($this->paths->pluginData() . "/Modern/config.toml");
		self::assertFileDoesNotExist($this->paths->pluginData() . "/Modern/config.yml");
	}

	public function testSymlinkedPluginDataFolderIsRejected() : void{
		$this->script("alpha.php", "Alpha");
		mkdir($this->root . "/outside");
		if(!@symlink($this->root . "/outside", $this->paths->pluginData() . "/Alpha")){
			self::markTestSkipped("Creating symbolic links is unavailable");
		}
		$manager = $this->manager();
		$errors = 0;
		self::assertSame([], $manager->loadPlugins($this->paths->plugins, $errors));
		self::assertSame(1, $errors);
		self::assertSame([], iterator_to_array(new \FilesystemIterator($this->root . "/outside")));
	}
}
