<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\resourcepacks\ResourcePackEncryptor;
use quark\resourcepacks\ResourcePackException;
use function array_unique;
use function bin2hex;
use function count;
use function crc32;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fopen;
use function fseek;
use function ftruncate;
use function fwrite;
use function hash_file;
use function json_decode;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function mkdir;
use function openssl_decrypt;
use function ord;
use function pack;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_RAW_DATA;
use const OPENSSL_ZERO_PADDING;

final class ResourcePackEncryptorTest extends TestCase{
	private const UUID = "481c7dcf-f147-4373-9cc5-afc6a651dc40";
	private const KEY = "12345678901234567890123456789012";
	private string $path;

	protected function setUp() : void{
		$this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-encrypt-" . bin2hex(random_bytes(8));
		mkdir($this->path);
	}

	protected function tearDown() : void{
		foreach(new \FilesystemIterator($this->path) as $file){
			self::assertInstanceOf(\SplFileInfo::class, $file);
			self::assertFalse($file->isDir(), "Encryption must remove private spools");
			unlink($file->getPathname());
		}
		rmdir($this->path);
	}

	/** @param array<int|string, string> $files */
	private function createPack(array $files, string $filename = "source.zip") : string{
		$source = $this->path . "/" . $filename;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source, \ZipArchive::CREATE));
		foreach($files as $path => $data){
			self::assertTrue($zip->addFromString((string) $path, $data));
			self::assertTrue($zip->setCompressionName((string) $path, \ZipArchive::CM_STORE));
		}
		self::assertTrue($zip->close());
		return $source;
	}

	public function testEncryptsNestedUnicodeBinaryAndEmptyFiles() : void{
		$manifest = json_encode(["header" => ["uuid" => self::UUID]], JSON_THROW_ON_ERROR);
		$files = [
			"manifest.json" => $manifest,
			"pack_icon.png" => "\x89PNG\x00\xff",
			"pack_icon.jpg" => "JPEG",
			"pack_manifest.json" => "legacy metadata",
			"README.txt" => "Read me",
			"textures/水.bin" => random_bytes(65536 * 2 + 7),
			"texts/é.txt" => "Hello 世界",
			"empty.bin" => "",
			"textures/manifest.json" => "ordinary nested asset"
		];
		$nested = [];
		foreach($files as $name => $data){
			$nested["wrapper/pack/" . $name] = $data;
		}
		$source = $this->createPack($nested);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source));
		self::assertTrue($zip->addEmptyDir("wrapper"));
		self::assertTrue($zip->addEmptyDir("wrapper/pack"));
		self::assertTrue($zip->close());
		$sourceHash = hash_file("sha256", $source);
		self::assertFalse(ResourcePackEncryptor::isEncrypted($source));
		$destination = $this->path . "/encrypted.zip";
		ResourcePackEncryptor::encrypt($source, $destination, self::KEY, self::UUID);
		self::assertSame($sourceHash, hash_file("sha256", $source));
		self::assertTrue(ResourcePackEncryptor::isEncrypted($destination));
		self::assertTrue($zip->open($destination));
		self::assertSame(count($files) + 1, $zip->numFiles);
		$contents = $zip->getFromName("contents.json");
		self::assertIsString($contents);
		self::assertSame("\x00\x00\x00\x00\xfc\xb9\xcf\x9b" . str_repeat("\x00", 8), substr($contents, 0, 16));
		self::assertSame(36, ord($contents[16]));
		self::assertSame(self::UUID, substr($contents, 17, 36));
		self::assertSame(str_repeat("\x00", 203), substr($contents, 53, 203));
		$index = openssl_decrypt(substr($contents, 256), "aes-256-cfb8", self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr(self::KEY, 0, 16));
		self::assertIsString($index);
		$decoded = json_decode($index, true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		self::assertArrayHasKey("content", $decoded);
		$content = $decoded["content"];
		self::assertIsArray($content);
		self::assertCount(count($files), $content);
		$keys = [];
		foreach($content as $item){
			self::assertIsArray($item);
			self::assertArrayHasKey("path", $item);
			$name = $item["path"];
			self::assertIsString($name);
			$stored = $zip->getFromName($name);
			self::assertIsString($stored);
			if(isset($item["key"])){
				self::assertIsString($item["key"]);
				self::assertMatchesRegularExpression('/^[\x21-\x7e]{32}$/D', $item["key"]);
				$keys[] = $item["key"];
				self::assertSame($files[$name], openssl_decrypt($stored, "aes-256-cfb8", $item["key"], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($item["key"], 0, 16)));
			}else{
				self::assertContains($name, ["manifest.json", "pack_icon.png", "pack_icon.jpg", "pack_manifest.json", "README.txt"]);
				self::assertSame($files[$name], $stored);
			}
			$stat = $zip->statName($name);
			self::assertIsArray($stat);
			self::assertSame(\ZipArchive::CM_STORE, $stat["comp_method"]);
		}
		self::assertCount(count($keys), array_unique($keys));
		$zip->close();
		$this->expectExceptionMessage("already encrypted");
		ResourcePackEncryptor::encrypt($destination, $this->path . "/twice.zip", self::KEY, self::UUID);
	}

	/** @return iterable<string, array{array<string, string>}> */
	public static function invalidPacks() : iterable{
		yield "traversal" => [["manifest.json" => "{}", "../outside" => "bad"]];
		yield "backslash" => [["manifest.json" => "{}", "a\\b" => "bad"]];
		yield "absolute" => [["manifest.json" => "{}", "/outside" => "bad"]];
		yield "drive" => [["manifest.json" => "{}", "C:outside" => "bad"]];
		yield "control" => [["manifest.json" => "{}", "a\nb" => "bad"]];
		yield "empty component" => [["manifest.json" => "{}", "a//b" => "bad"]];
		yield "case duplicate" => [["manifest.json" => "{}", "a.bin" => "a", "A.bin" => "b"]];
		yield "unicode duplicate" => [["manifest.json" => "{}", "É.bin" => "a", "é.bin" => "b"]];
		yield "directory conflict" => [["manifest.json" => "{}", "a" => "a", "a/b" => "b"]];
		yield "directory casing" => [["manifest.json" => "{}", "A/a" => "a", "a/b" => "b"]];
		yield "ambiguous manifest" => [["packA/manifest.json" => "{}", "packB/manifest.json" => "{}"]];
		yield "outside root" => [["pack/manifest.json" => "{}", "outside" => "bad"]];
		yield "sibling directory" => [["pack/manifest.json" => "{}", "other/" => ""]];
		yield "missing manifest" => [["asset.bin" => "bad"]];
		yield "reserved plaintext" => [["manifest.json" => "{}", "contents.json" => "{}"]];
		yield "reserved directory" => [["manifest.json" => "{}", "contents.json/" => ""]];
		yield "reserved casing" => [["manifest.json" => "{}", "CONTENTS.JSON" => str_repeat("x", 257)]];
		yield "bad encrypted metadata" => [["manifest.json" => "{}", "contents.json" => str_repeat("x", 257)]];
		yield "plaintext encryption key" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":"manifest.json","key":"secret"}]}']];
		yield "plaintext key type" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":"manifest.json","key":false}]}']];
		yield "plaintext path type" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":1}]}']];
		yield "plaintext missing file" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":"missing"}]}']];
		yield "plaintext duplicate path" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":"manifest.json"},{"path":"MANIFEST.JSON"}]}']];
		yield "plaintext traversal" => [["manifest.json" => "{}", "contents.json" => '{"content":[{"path":"../manifest.json"}]}']];
		yield "plaintext content object" => [["manifest.json" => "{}", "contents.json" => '{"content":{}}']];
		yield "plaintext excessive structure" => [["manifest.json" => "{}", "contents.json" => '{"content":[' . str_repeat('{},', 131072) . '{}]}']];
		yield "large manifest" => [["manifest.json" => str_repeat("x", 1048577)]];
		yield "long path" => [["manifest.json" => "{}", str_repeat("a", 1025) => "bad"]];
		yield "deep path" => [["manifest.json" => "{}", str_repeat("a/", 32) . "bad" => "bad"]];
	}

	/** @param array<string, string> $files */
	#[DataProvider("invalidPacks")]
	public function testRejectsUnsafeArchive(array $files) : void{
		$source = $this->createPack($files);
		$this->expectException(ResourcePackException::class);
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsSymlink() : void{
		$source = $this->createPack(["manifest.json" => "{}", "link" => "/outside"]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source));
		self::assertTrue($zip->setExternalAttributesName("link", \ZipArchive::OPSYS_UNIX, 0120777 << 16));
		self::assertTrue($zip->close());
		$this->expectExceptionMessage("non-regular");
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsZipEncryption() : void{
		$source = $this->createPack(["manifest.json" => "{}", "asset" => "payload"]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source));
		self::assertTrue($zip->setEncryptionName("asset", \ZipArchive::EM_AES_256, "password"));
		self::assertTrue($zip->close());
		$this->expectException(ResourcePackException::class);
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsExcessiveExpansion() : void{
		$source = $this->createPack(["manifest.json" => "{}", "asset" => str_repeat("a", 2 * 1024 * 1024)]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source));
		self::assertTrue($zip->setCompressionName("asset", \ZipArchive::CM_DEFLATE, 9));
		self::assertTrue($zip->close());
		$this->expectExceptionMessage("expansion limits");
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsCrcCorruptionAndRemovesSpools() : void{
		$source = $this->createPack(["manifest.json" => "{}", "asset" => "unique-resource-payload"]);
		$bytes = file_get_contents($source);
		self::assertIsString($bytes);
		file_put_contents($source, str_replace("unique-resource-payload", "broken-resource-payload", $bytes));
		$this->expectException(ResourcePackException::class);
		try{
			ResourcePackEncryptor::encrypt($source, $this->path . "/encrypted.zip", self::KEY, self::UUID);
		}finally{
			self::assertFileDoesNotExist($this->path . "/encrypted.zip");
		}
	}

	public function testRejectsInvalidZip() : void{
		$source = $this->path . "/bad.zip";
		file_put_contents($source, "not a zip");
		$this->expectException(ResourcePackException::class);
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsSameDestination() : void{
		$source = $this->createPack(["manifest.json" => "{}"]);
		$this->expectException(\InvalidArgumentException::class);
		ResourcePackEncryptor::encrypt($source, $source, self::KEY, self::UUID);
	}

	public function testRejectsInvalidKey() : void{
		$source = $this->createPack(["manifest.json" => "{}"]);
		$this->expectException(\InvalidArgumentException::class);
		ResourcePackEncryptor::encrypt($source, $this->path . "/encrypted.zip", str_repeat("a", 31), self::UUID);
	}

	public function testPreservesExistingDestination() : void{
		$source = $this->createPack(["manifest.json" => "{}"]);
		$destination = $this->path . "/existing.zip";
		file_put_contents($destination, "keep existing content");
		$this->expectExceptionMessage("new file");
		try{
			ResourcePackEncryptor::encrypt($source, $destination, self::KEY, self::UUID);
		}finally{
			self::assertSame("keep existing content", file_get_contents($destination));
		}
	}

	public function testAcceptsSpacesInKeyAndEncryptsNonRootContents() : void{
		$source = $this->createPack(["manifest.json" => "{}", "assets/contents.json" => "a regular asset", "123" => "numeric filename"]);
		self::assertFalse(ResourcePackEncryptor::isEncrypted($source));
		$destination = $this->path . "/encrypted.zip";
		ResourcePackEncryptor::encrypt($source, $destination, str_repeat(" ", 32), self::UUID);
		self::assertTrue(ResourcePackEncryptor::isEncrypted($destination));
	}

	public function testStreamsLargePackWithBoundedMemory() : void{
		$payload = $this->path . "/payload.bin";
		$handle = fopen($payload, "wb");
		self::assertIsResource($handle);
		self::assertTrue(ftruncate($handle, 16 * 1024 * 1024));
		fclose($handle);
		$source = $this->createPack(["manifest.json" => "{}"]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($source));
		self::assertTrue($zip->addFile($payload, "asset.bin"));
		self::assertTrue($zip->setCompressionName("asset.bin", \ZipArchive::CM_STORE));
		self::assertTrue($zip->close());
		$before = memory_get_usage();
		memory_reset_peak_usage();
		ResourcePackEncryptor::encrypt($source, $this->path . "/encrypted.zip", self::KEY, self::UUID);
		self::assertLessThanOrEqual(8 * 1024 * 1024, memory_get_peak_usage() - $before);
		self::assertGreaterThan(16 * 1024 * 1024, filesize($this->path . "/encrypted.zip"));
	}

	public function testReplacesValidPlaintextContentIndex() : void{
		$plaintext = '{"content":[{"path":"manifest.json","key":null},{"path":"asset.bin","key":""}]}';
		$source = $this->createPack([
			"nested/manifest.json" => "{}",
			"nested/contents.json" => $plaintext,
			"nested/asset.bin" => "asset payload"
		]);
		self::assertFalse(ResourcePackEncryptor::isEncrypted($source));
		$destination = $this->path . "/encrypted.zip";
		ResourcePackEncryptor::encrypt($source, $destination, self::KEY, self::UUID);
		self::assertTrue(ResourcePackEncryptor::isEncrypted($destination));
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($destination));
		self::assertSame(3, $zip->numFiles);
		$contents = $zip->getFromName("contents.json");
		self::assertIsString($contents);
		$decoded = openssl_decrypt(substr($contents, 256), "aes-256-cfb8", self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr(self::KEY, 0, 16));
		self::assertIsString($decoded);
		$index = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($index);
		self::assertArrayHasKey("content", $index);
		self::assertIsArray($index["content"]);
		self::assertCount(2, $index["content"]);
		$keys = [];
		foreach($index["content"] as $item){
			self::assertIsArray($item);
			self::assertArrayHasKey("path", $item);
			self::assertIsString($item["path"]);
			$keys[$item["path"]] = $item["key"] ?? null;
		}
		self::assertArrayNotHasKey("contents.json", $keys);
		self::assertNull($keys["manifest.json"]);
		self::assertIsString($keys["asset.bin"]);
		$asset = $zip->getFromName("asset.bin");
		self::assertIsString($asset);
		self::assertSame("asset payload", openssl_decrypt($asset, "aes-256-cfb8", $keys["asset.bin"], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($keys["asset.bin"], 0, 16)));
		self::assertTrue($zip->close());
	}

	private function createRawPack(bool $descriptor = false, bool $zip64 = false, string $prefix = "", string $comment = "", string $extension = "", bool $zip64Offset = false) : string{
		$name = "manifest.json";
		$data = "{}";
		$crc = crc32($data);
		$local = pack("VvvvvvVVVvv", 0x04034b50, 20, $descriptor ? 8 : 0, 0, 0, 0, $descriptor ? 0 : $crc, $descriptor ? 0 : 2, $descriptor ? 0 : 2, strlen($name), 0) . $name . $data;
		if($descriptor){
			$local .= pack("VVVV", 0x08074b50, $crc, 2, 2);
		}
		$extra = $zip64Offset ? pack("vvV2", 1, 8, strlen($prefix), 0) : "";
		$directory = pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, $zip64Offset ? 45 : 20, $descriptor ? 8 : 0, 0, 0, 0, $crc, 2, 2, strlen($name), strlen($extra), 0, 0, 0, 0, $zip64Offset ? 0xffffffff : strlen($prefix)) . $name . $extra;
		$zip64Data = "";
		if($zip64){
			$zip64Data = "PK\x06\x06" . pack("V2vvV2V2V2V2V2", 44 + strlen($extension), 0, 45, 45, 0, 0, 1, 0, 1, 0, strlen($directory), 0, strlen($prefix . $local), 0) . $extension;
			$zip64Data .= "PK\x06\x07" . pack("V4", 0, strlen($prefix . $local . $directory), 0, 1);
		}
		$end = pack("VvvvvVVv", 0x06054b50, 0, 0, $zip64 ? 65535 : 1, $zip64 ? 65535 : 1, $zip64 ? 0xffffffff : strlen($directory), $zip64 ? 0xffffffff : strlen($prefix . $local), strlen($comment));
		$path = $this->path . "/raw.zip";
		file_put_contents($path, $prefix . $local . $directory . $zip64Data . $end . $comment);
		return $path;
	}

	/** @return iterable<string, array{bool, bool, string, string, string}> */
	public static function validZipLayouts() : iterable{
		yield "data descriptor" => [true, false, "", "", ""];
		yield "self extracting prefix" => [false, false, "MZ fake executable prefix", "", ""];
		yield "ZIP64" => [false, true, "", "", ""];
		yield "ZIP64 descriptor" => [true, true, "", "", ""];
		yield "ZIP64 prefix" => [false, true, "MZ fake executable prefix", "", ""];
		yield "ZIP64 extensible prefix" => [false, true, "MZ fake executable prefix", "", "extra metadata"];
		yield "comment signatures" => [false, false, "", "signature PK\x05\x06 in comment", ""];
		yield "footer-shaped comment" => [false, false, "", "PK\x05\x06" . str_repeat("\x00", 18), ""];
		yield "oversized-looking comment" => [false, false, "", pack("VvvvvVVv", 0x06054b50, 0, 0, 1, 1, 0x10000000, 0, 0), ""];
		yield "maximum comment" => [false, false, "", str_repeat("a", 65535), ""];
	}

	#[DataProvider("validZipLayouts")]
	public function testAcceptsValidZipLayouts(bool $descriptor, bool $zip64, string $prefix, string $comment, string $extension) : void{
		$source = $this->createRawPack($descriptor, $zip64, $prefix, $comment, $extension);
		self::assertFalse(ResourcePackEncryptor::isEncrypted($source));
		ResourcePackEncryptor::encrypt($source, $this->path . "/encrypted.zip", self::KEY, self::UUID);
		self::assertTrue(ResourcePackEncryptor::isEncrypted($this->path . "/encrypted.zip"));
	}

	public function testRejectsOversizedZip64EntryCountBeforeOpeningArchive() : void{
		$directory = "PK\x01\x02" . str_repeat("\x00", 65536 * 46 - 4);
		$record = "PK\x06\x06" . pack("V2vvV2V2V2V2V2", 44, 0, 45, 45, 0, 0, 65536, 0, 65536, 0, strlen($directory), 0, 0, 0);
		$locator = "PK\x06\x07" . pack("V4", 0, strlen($directory), 0, 1);
		$end = pack("VvvvvVVv", 0x06054b50, 0, 0, 65535, 65535, 0xffffffff, 0xffffffff, 22);
		$comment = "PK\x05\x06" . str_repeat("\x00", 18);
		$source = $this->path . "/oversized.zip";
		file_put_contents($source, $directory . $record . $locator . $end . $comment);
		$this->expectExceptionMessage("central directory metadata limits");
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsOversizedCentralDirectoryBeforeOpeningArchive() : void{
		$source = $this->path . "/oversized.zip";
		$handle = fopen($source, "w+b");
		self::assertIsResource($handle);
		fwrite($handle, "PK\x01\x02");
		fseek($handle, 67108865);
		fwrite($handle, pack("VvvvvVVv", 0x06054b50, 0, 0, 1, 1, 67108865, 0, 0));
		fclose($handle);
		$this->expectExceptionMessage("central directory metadata limits");
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testRejectsWrappedClassicEntryCountBeforeOpeningArchive() : void{
		$local = pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 0, 0, 0, 0, 0, 0, 0, 0);
		$directory = str_repeat("PK\x01\x02" . str_repeat("\x00", 42), 65536);
		$source = $this->path . "/wrapped.zip";
		file_put_contents($source, $local . $directory . pack("VvvvvVVv", 0x06054b50, 0, 0, 0, 0, strlen($directory), strlen($local), 0));
		$this->expectExceptionMessage("central directory metadata limits");
		ResourcePackEncryptor::isEncrypted($source);
	}

	public function testAcceptsForcedZip64EntryOffset() : void{
		$source = $this->createRawPack(zip64: true, prefix: "MZ prefix", zip64Offset: true);
		self::assertFalse(ResourcePackEncryptor::isEncrypted($source));
		ResourcePackEncryptor::encrypt($source, $this->path . "/encrypted.zip", self::KEY, self::UUID);
		self::assertTrue(ResourcePackEncryptor::isEncrypted($this->path . "/encrypted.zip"));
	}

	public function testRejectsAggregateLocalMetadataBeforeOpeningArchive() : void{
		$local = pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 0, 0, 0, 0, 0, 0, 0, 65535) . str_repeat("\x00", 65535);
		$directory = str_repeat("PK\x01\x02" . str_repeat("\x00", 42), 1025);
		$end = pack("VvvvvVVv", 0x06054b50, 0, 0, 1025, 1025, strlen($directory), strlen($local), 0);
		$source = $this->path . "/local-metadata.zip";
		file_put_contents($source, $local . $directory . $end);
		$this->expectExceptionMessage("aggregate ZIP metadata limits");
		ResourcePackEncryptor::isEncrypted($source);
	}
}
