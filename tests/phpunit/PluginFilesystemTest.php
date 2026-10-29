<?php

declare(strict_types=1);

namespace quark\plugin;

use PHPUnit\Framework\TestCase;
use quark\FilesystemPaths;
use quark\lang\Language;
use quark\Server;
use quark\utils\MainLogger;
use function array_keys;
use function assert;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
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
		self::assertFileExists($this->paths->pluginData() . "/Alpha/config.yml");
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
