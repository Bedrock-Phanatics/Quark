<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\pulse\internal\PulseZones;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Config;
use quark\utils\Filesystem;
use Symfony\Component\Filesystem\Path;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function get_resources;
use function ini_get;
use function ini_set;
use function mkdir;
use function stream_get_meta_data;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use const PHP_INT_MAX;

final class MemoryManagementTest extends TestCase{

	/** @return iterable<string, array{bool, list<int>}> */
	public static function pressureModes() : iterable{
		yield "one notification per episode" => [false, [0, 0]];
		yield "repeat interval resets per episode" => [true, [0, 1, 0, 1]];
	}

	/** @param list<int> $expected */
	#[DataProvider("pressureModes")]
	public function testGlobalPressureUsesItsOwnLimitAndRepeatPolicy(bool $continuous, array $expected) : void{
		$path = tempnam(sys_get_temp_dir(), "quark-memory-");
		self::assertNotFalse($path);
		$hardLimit = ini_get("memory_limit");
		self::assertNotFalse($hardLimit);
		try{
			$config = new Config($path, Config::PROPERTIES);
			$config->set("memory", [
				"main-hard-limit" => 0,
				"global-limit" => 1,
				"check-rate" => 1,
				"continuous-trigger" => $continuous,
				"continuous-trigger-rate" => 2,
				"garbage-collection" => ["threshold" => 0, "period" => 0, "php-automatic" => gc_enabled()],
			]);
			$server = self::createStub(Server::class);
			$server->method("getConfigGroup")->willReturn(new ServerConfigGroup($config, $config));
			$server->method("getLogger")->willReturn(self::createStub(AttachableThreadSafeLogger::class));
			PulseZones::init();
			$manager = $this->getMockBuilder(MemoryManager::class)->setConstructorArgs([$server])->onlyMethods(["trigger"])->getMock();
			$calls = [];
			$manager->method("trigger")->willReturnCallback(static function(int $usage, int $limit, bool $global, int $count = 0) use (&$calls) : void{
				self::assertTrue($global);
				self::assertSame(1024 * 1024, $limit);
				$calls[] = $count;
			});
			for($i = 0; $i < 4; ++$i){
				$manager->check();
			}
			$limit = new \ReflectionProperty(MemoryManager::class, "globalMemoryLimit");
			$limit->setValue($manager, PHP_INT_MAX);
			$manager->check();
			self::assertFalse($manager->isLowMemory());
			$limit->setValue($manager, 1024 * 1024);
			$manager->check();
			$manager->check();
			self::assertSame($continuous ? [0, 1, 0] : [0, 0], $calls);
			$manager->check();
			self::assertSame($expected, $calls);
		}finally{
			ini_set("memory_limit", $hardLimit);
			unlink($path);
		}
	}

	public function testFailedMemoryDumpRestoresRuntimeLimitsAndClosesStream() : void{
		$dir = tempnam(sys_get_temp_dir(), "quark-dump-");
		self::assertNotFalse($dir);
		unlink($dir);
		mkdir($dir);
		$hardLimit = ini_get("memory_limit");
		self::assertNotFalse($hardLimit);
		$wasEnabled = gc_enabled();
		try{
			ini_set("memory_limit", "1024M");
			$failure = new \RuntimeException("Interrupted memory dump");
			$logger = self::createStub(\Logger::class);
			$logger->method("info")->willThrowException($failure);
			foreach([true, false] as $enabled){
				$enabled ? gc_enable() : gc_disable();
				try{
					MemoryDump::dumpMemory(null, $dir, 1, 10, $logger);
					self::fail("The memory dump should have failed");
				}catch(\RuntimeException $e){
					self::assertSame($failure, $e);
				}
				self::assertSame($enabled, gc_enabled());
				self::assertSame("1024M", ini_get("memory_limit"));
				$objects = Path::join($dir, "objects.js");
				self::assertFileExists($objects);
				foreach(get_resources("stream") as $stream){
					self::assertNotSame($objects, stream_get_meta_data($stream)["uri"] ?? null);
				}
			}
		}finally{
			$wasEnabled ? gc_enable() : gc_disable();
			ini_set("memory_limit", $hardLimit);
			Filesystem::recursiveUnlink($dir);
		}
	}
}
