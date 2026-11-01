<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\resourcepacks\ResourcePackManager;
use quark\resourcepacks\ZippedResourcePack;
use function assert;
use function bin2hex;
use function fclose;
use function file_put_contents;
use function fopen;
use function ftruncate;
use function hash_file;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function mkdir;
use function random_bytes;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

final class ResourcePackFilesystemTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		$this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-packs-" . bin2hex(random_bytes(8));
		mkdir($this->path);
	}

	protected function tearDown() : void{
		foreach(new \FilesystemIterator($this->path) as $child){
			assert($child instanceof \SplFileInfo);
			if($child->isDir() && !$child->isLink()){
				foreach(new \FilesystemIterator($child->getPathname()) as $file){
					assert($file instanceof \SplFileInfo);
					unlink($file->getPathname());
				}
				rmdir($child->getPathname());
			}else{
				unlink($child->getPathname());
			}
		}
		rmdir($this->path);
	}

	private function createPack(string $name, string $uuid, ?string $payload = null) : string{
		$path = $this->path . DIRECTORY_SEPARATOR . $name;
		$archive = new \ZipArchive();
		self::assertTrue($archive->open($path, \ZipArchive::CREATE));
		self::assertTrue($archive->addFromString("manifest.json", json_encode([
			"format_version" => 2,
			"header" => ["name" => "Example", "uuid" => $uuid, "version" => [1, 2, 3]],
			"modules" => [["type" => "resources", "uuid" => "799255a2-4ff9-4a37-ad9c-8ed09cfa5fff", "version" => [1, 0, 0]]],
			"dependencies" => [["uuid" => "990b7dd9-1681-4c48-b44f-4538da474aa3", "version" => [1, 0, 0]]]
		], JSON_THROW_ON_ERROR)));
		if($payload !== null){
			self::assertTrue($archive->addFile($payload, "payload.bin"));
			self::assertTrue($archive->setCompressionName("payload.bin", \ZipArchive::CM_STORE));
		}
		self::assertTrue($archive->close());
		return $path;
	}

	public function testLoadsPacksAndKeysFromModernDirectory() : void{
		mkdir($this->path . "/resource-packs");
		$packs = $this->path . "/resource-packs";
		$uuid = "481c7dcf-f147-4373-9cc5-afc6a651dc40";
		$archive = new \ZipArchive();
		self::assertTrue($archive->open($packs . "/Example.mcpack", \ZipArchive::CREATE));
		self::assertTrue($archive->addFromString("manifest.json", json_encode([
			"format_version" => 2,
			"header" => ["name" => "Example", "uuid" => $uuid, "version" => [1, 2, 3]],
			"modules" => [["type" => "resources", "uuid" => "799255a2-4ff9-4a37-ad9c-8ed09cfa5fff", "version" => [1, 0, 0]]]
		], JSON_THROW_ON_ERROR)));
		self::assertTrue($archive->close());
		file_put_contents($packs . "/resource_packs.yml", "force_resources: true\nresource_stack:\n  - Example.mcpack\n");
		file_put_contents($packs . "/Example.mcpack.key", "12345678901234567890123456789012");
		$manager = new ResourcePackManager($packs, $this->createMock(\Logger::class));
		self::assertSame($packs . DIRECTORY_SEPARATOR, $manager->getPath());
		self::assertTrue($manager->resourcePacksRequired());
		self::assertSame([$uuid], $manager->getPackIdList());
		self::assertSame("1.2.3", $manager->getPackById($uuid)?->getPackVersion());
		self::assertSame("12345678901234567890123456789012", $manager->getPackEncryptionKey($uuid));
		self::assertSame(hash_file("sha256", $packs . "/Example.mcpack", true), $manager->getPackById($uuid)?->getSha256());
	}

	public function testLargePackUsesBoundedMemoryAndStreamsChunks() : void{
		$payload = $this->path . "/payload.bin";
		$handle = fopen($payload, "wb");
		self::assertIsResource($handle);
		self::assertTrue(ftruncate($handle, 16 * 1024 * 1024));
		fclose($handle);
		$packPath = $this->createPack("large.zip", "481c7dcf-f147-4373-9cc5-afc6a651dc40", $payload);
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$pack = new ZippedResourcePack($packPath);
		self::assertGreaterThan(16 * 1024 * 1024, $pack->getPackSize());
		self::assertSame(hash_file("sha256", $packPath, true), $pack->getSha256());
		self::assertSame(1024, strlen($pack->getPackChunk($pack->getPackSize() - 1024, 1024)));
		self::assertLessThanOrEqual(8 * 1024 * 1024, memory_get_peak_usage() - $before);
		$this->expectException(\InvalidArgumentException::class);
		$pack->getPackChunk($pack->getPackSize(), 1024);
	}

	public function testRejectsTraversalAndInvalidManifest() : void{
		mkdir($this->path . "/resource-packs");
		$packs = $this->path . "/resource-packs";
		file_put_contents($packs . "/resource_packs.yml", "resource_stack:\n  - ../outside.zip\n");
		$logger = $this->createMock(\Logger::class);
		$logger->expects(self::once())->method("critical")->with(self::stringContains("must be a filename"));
		$manager = new ResourcePackManager($packs, $logger);
		self::assertSame([], $manager->getPackIdList());
		$archive = new \ZipArchive();
		self::assertTrue($archive->open($packs . "/bad.zip", \ZipArchive::CREATE));
		self::assertTrue($archive->addFromString("manifest.json", "not json"));
		self::assertTrue($archive->close());
		$this->expectException(resourcepacks\ResourcePackException::class);
		new ZippedResourcePack($packs . "/bad.zip");
	}
}
