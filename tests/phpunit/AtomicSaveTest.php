<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\utils\Filesystem;
use function file_get_contents;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class AtomicSaveTest extends TestCase{
	protected function setUp() : void{
		AtomicSaveStream::$files = ["quarksave://storage/player.dat" => "old"];
		AtomicSaveStream::$shortWrite = false;
		AtomicSaveStream::$renameAttempts = 0;
		self::assertTrue(stream_wrapper_register("quarksave", AtomicSaveStream::class));
	}

	protected function tearDown() : void{
		stream_wrapper_unregister("quarksave");
	}

	public function testFailedReplacementPreservesOriginalAndCleansTemporaryFile() : void{
		$this->assertFailedSavePreservesOriginal();
		self::assertSame(1, AtomicSaveStream::$renameAttempts);
	}

	public function testShortWritePreservesOriginalAndCleansTemporaryFile() : void{
		AtomicSaveStream::$shortWrite = true;
		$this->assertFailedSavePreservesOriginal();
		self::assertSame(0, AtomicSaveStream::$renameAttempts);
	}

	public function testSyncedReplaceOverwritesExistingContents() : void{
		$path = tempnam(sys_get_temp_dir(), "quark-save-");
		self::assertIsString($path);
		try{
			Filesystem::safeFilePutContents($path, "first", sync: true);
			Filesystem::safeFilePutContents($path, "second", sync: true);
			self::assertSame("second", file_get_contents($path));
		}finally{
			unlink($path);
		}
	}

	private function assertFailedSavePreservesOriginal() : void{
		$original = AtomicSaveStream::$files;
		try{
			Filesystem::safeFilePutContents("quarksave://storage/player.dat", "new state");
			self::fail("Expected save failure");
		}catch(\RuntimeException){
			self::assertSame($original, AtomicSaveStream::$files);
		}
	}
}

final class AtomicSaveStream{
	/** @var resource|null */
	public $context;
	/** @var array<string, string> */
	public static array $files;
	public static bool $shortWrite;
	public static int $renameAttempts;
	private string $path;

	public function url_stat(string $path, int $flags) : array|false{
		return match(true){
			$path === "quarksave://storage" => ["mode" => 0040000],
			isset(self::$files[$path]) => ["mode" => 0100000],
			default => false,
		};
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath) : bool{
		$this->path = $path;
		self::$files[$path] = "";
		return true;
	}

	public function stream_write(string $data) : int{
		if(self::$shortWrite){
			$data = self::$files[$this->path] === "" ? substr($data, 0, 1) : "";
		}
		self::$files[$this->path] .= $data;
		return strlen($data);
	}

	public function rename(string $from, string $to) : bool{
		++self::$renameAttempts;
		return false;
	}

	public function unlink(string $path) : bool{
		unset(self::$files[$path]);
		return true;
	}
}
