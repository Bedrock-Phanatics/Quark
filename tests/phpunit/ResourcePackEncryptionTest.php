<?php

declare(strict_types=1);

namespace quark\resourcepacks;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePackDataInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\types\ResourcePackClientResponseStatus;
use quark\CoreConfig;
use quark\event\player\PlayerResourcePackOfferEvent;
use quark\network\mcpe\handler\ResourcePacksPacketHandler;
use quark\network\mcpe\NetworkSession;
use quark\player\PlayerInfo;
use quark\utils\Filesystem;
use Vow\Promise;
use function array_map;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fileperms;
use function gc_collect_cycles;
use function hash;
use function hash_file;
use function json_encode;
use function mkdir;
use function random_bytes;
use function sprintf;
use function str_repeat;
use function strtolower;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

final class ResourcePackEncryptionTest extends TestCase{
	private string $root;
	private string $packs;
	private int $packIndex = 1;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-pack-encryption-" . bin2hex(random_bytes(8));
		$this->packs = $this->root . DIRECTORY_SEPARATOR . "resource-packs";
		mkdir($this->packs, 0700, true);
	}

	protected function tearDown() : void{
		CoreConfig::forget($this->configPath());
		Filesystem::recursiveUnlink($this->root);
	}

	public function testDisabledModeLeavesSourcePackAndDoesNotCreateCacheOrKey() : void{
		$pack = $this->createPack("plain.zip");
		$sourceHash = hash_file("sha256", $pack);
		$this->writeConfig(["plain.zip"], ["enabled" => false]);

		$manager = $this->manager();
		self::assertSame($sourceHash, hash_file("sha256", $pack));
		self::assertSame($pack, $this->getPack($manager)->getPath());
		self::assertDirectoryDoesNotExist($this->cacheDirectory());
		self::assertFileDoesNotExist($this->packs . DIRECTORY_SEPARATOR . ".encryption.key");
	}

	public function testDefaultKeyIsPersistedAndReusedOnRestart() : void{
		$this->createPack("plain.zip");
		$this->writeConfig(["plain.zip"], ["enabled" => true]);
		$keyPath = $this->packs . DIRECTORY_SEPARATOR . ".encryption.key";

		$first = $this->manager();
		$key = file_get_contents($keyPath);
		self::assertIsString($key);
		self::assertMatchesRegularExpression('/^[\x20-\x7e]{32}$/D', $key);
		self::assertSame($key, $first->getPackEncryptionKey($first->getPackIdList()[0]));
		if(DIRECTORY_SEPARATOR === "/"){
			self::assertSame(0600, fileperms($keyPath) & 0777);
			self::assertSame(0700, fileperms($this->cacheDirectory()) & 0777);
			self::assertSame(0600, fileperms($this->getPack($first)->getPath()) & 0777);
		}
		unset($first);
		gc_collect_cycles();

		$second = $this->manager();
		$persistedKey = file_get_contents($keyPath);
		self::assertIsString($persistedKey);
		self::assertSame($key, $persistedKey);
		self::assertSame($key, $second->getPackEncryptionKey($second->getPackIdList()[0]));
	}

	public function testConfiguredAndKeyFileKeysAreUsedWithTrailingNewlines() : void{
		$this->createPack("configured.MCPACK");
		$this->writeConfig(["configured.MCPACK"], ["enabled" => true, "key" => str_repeat("C", 32)]);
		$configured = $this->manager();
		$id = $configured->getPackIdList()[0];
		self::assertSame(str_repeat("C", 32), $configured->getPackEncryptionKey($id));
		if(DIRECTORY_SEPARATOR === "/"){
			self::assertSame(0600, fileperms($this->configPath()) & 0777);
		}
		unset($configured);
		gc_collect_cycles();

		$this->createPack("file-key.zip");
		$key = str_repeat("F", 32);
		file_put_contents($this->packs . DIRECTORY_SEPARATOR . "encryption.key", $key . "\r\n");
		$this->writeConfig(["file-key.zip"], ["enabled" => true, "key-file" => "encryption.key"]);
		$fileKey = $this->manager();
		$id = $fileKey->getPackIdList()[0];
		self::assertSame($key, $fileKey->getPackEncryptionKey($id));
		self::assertFileDoesNotExist($this->packs . DIRECTORY_SEPARATOR . ".encryption.key");
	}

	public function testSelectiveEncryptionPreservesMixedStackAndPackSource() : void{
		$plain = $this->createPack("plain.zip");
		$selected = $this->createPack("selected.zip");
		$selectedHash = hash_file("sha256", $selected);
		$this->writeConfig(["plain.zip", "selected.zip"], ["enabled" => true, "packs" => ["selected.zip"], "key" => str_repeat("S", 32)]);

		$manager = $this->manager();
		$plainPack = $this->getPack($manager, 0);
		$selectedPack = $this->getPack($manager, 1);
		self::assertSame($plain, $plainPack->getPath());
		self::assertNotSame($selected, $selectedPack->getPath());
		self::assertNull($manager->getPackEncryptionKey($plainPack->getPackId()));
		self::assertSame(str_repeat("S", 32), $manager->getPackEncryptionKey($selectedPack->getPackId()));
		self::assertTrue(ResourcePackEncryptor::isEncrypted($selectedPack->getPath()));
		self::assertSame($selectedHash, hash_file("sha256", $selected));
	}

	public function testPreencryptedPackRequiresAndPreservesItsSidecarKey() : void{
		$key = str_repeat("P", 32);
		$pack = $this->createEncryptedPack("prepared.zip", $key);
		$packHash = hash_file("sha256", $pack);
		$keyPath = $pack . ".key";
		file_put_contents($keyPath, $key . "\n");
		$keyBytes = file_get_contents($keyPath);
		self::assertIsString($keyBytes);
		$this->writeConfig(["prepared.zip"], ["enabled" => true, "packs" => ["prepared.zip"], "key" => str_repeat("G", 32)]);

		$manager = $this->manager();
		$id = $manager->getPackIdList()[0];
		self::assertSame($key, $manager->getPackEncryptionKey($id));
		self::assertSame($packHash, hash_file("sha256", $pack));
		self::assertSame($keyBytes, file_get_contents($keyPath));

		unlink($keyPath);
		CoreConfig::forget($this->configPath());
		$this->expectException(ResourcePackException::class);
		$this->manager();
	}

	public function testCacheWarmReuseAndChunkHashMatch() : void{
		$this->createPack("chunked.zip", random_bytes(65540));
		$this->writeConfig(["chunked.zip"], ["enabled" => true, "key" => str_repeat("H", 32)]);
		$first = $this->manager();
		$firstPack = $this->getPack($first);
		$cachePath = $firstPack->getPath();
		$cacheHash = hash_file("sha256", $cachePath);
		$mtime = filemtime($cachePath);
		unset($firstPack, $first);
		gc_collect_cycles();

		$second = $this->manager();
		$secondPack = $this->getPack($second);
		$bytes = "";
		for($offset = 0; $offset < $secondPack->getPackSize(); $offset += 65531){
			$bytes .= $secondPack->getPackChunk($offset, 65531);
		}
		self::assertSame($cachePath, $secondPack->getPath());
		self::assertSame($cacheHash, hash_file("sha256", $cachePath));
		self::assertSame($mtime, filemtime($cachePath));
		self::assertSame(hash("sha256", $bytes, true), $secondPack->getSha256());
		self::assertTrue(ResourcePackEncryptor::isEncrypted($cachePath));
	}

	public function testCacheInvalidatesChangedSourceAndKey() : void{
		$source = $this->createPack("changing.zip", str_repeat("A", 4096));
		$this->writeConfig(["changing.zip"], ["enabled" => true, "key" => str_repeat("1", 32)]);
		$first = $this->manager();
		$firstPath = $this->getPack($first)->getPath();
		unset($first);
		gc_collect_cycles();

		$this->createPack("changing.zip", str_repeat("B", 4096), $this->uuidFor(1));
		$sourceChanged = $this->manager();
		$sourcePath = $this->getPack($sourceChanged)->getPath();
		self::assertNotSame($firstPath, $sourcePath);
		self::assertFileDoesNotExist($firstPath);
		unset($sourceChanged);
		gc_collect_cycles();

		$this->writeConfig(["changing.zip"], ["enabled" => true, "key" => str_repeat("2", 32)]);
		$keyChanged = $this->manager();
		self::assertNotSame($sourcePath, $this->getPack($keyChanged)->getPath());
		self::assertFileDoesNotExist($sourcePath);
		self::assertFileExists($source);
	}

	public function testCorruptCacheRecoversAfterHandlesAreReleased() : void{
		$this->createPack("recover.zip");
		$this->writeConfig(["recover.zip"], ["enabled" => true, "key" => str_repeat("R", 32)]);
		$manager = $this->manager();
		$pack = $this->getPack($manager);
		$path = $pack->getPath();
		unset($pack, $manager);
		gc_collect_cycles();
		file_put_contents($path, "broken archive");

		$recovered = $this->manager();
		$recoveredPack = $this->getPack($recovered);
		self::assertSame($path, $recoveredPack->getPath());
		self::assertTrue(ResourcePackEncryptor::isEncrypted($path));
		self::assertSame(hash_file("sha256", $path, true), $recoveredPack->getSha256());
	}

	public function testInterruptedBuildAndUnrelatedCacheFilesAreHandled() : void{
		$this->createPack("startup.zip");
		$this->writeConfig(["startup.zip"], ["enabled" => true, "key" => str_repeat("I", 32)]);
		$cache = $this->cacheDirectory();
		mkdir($cache, 0700);
		$interrupted = $cache . DIRECTORY_SEPARATOR . ".building-0123456789abcdef0123456789abcdef";
		mkdir($interrupted);
		file_put_contents($interrupted . DIRECTORY_SEPARATOR . "partial", "partial");
		$unrelated = $cache . DIRECTORY_SEPARATOR . "keep-this-file";
		file_put_contents($unrelated, "keep");

		$this->manager();
		self::assertDirectoryDoesNotExist($interrupted);
		self::assertSame("keep", file_get_contents($unrelated));
	}

	public function testInvalidCacheAuthenticationNeverReplacesAnActiveDownload() : void{
		$this->createPack("authentication.zip");
		$this->writeConfig(["authentication.zip"], ["enabled" => true]);
		$manager = $this->manager();
		$pack = $this->getPack($manager);
		$path = $pack->getPath();
		$hash = $pack->getSha256();
		file_put_contents($path . ".json", json_encode([
			"sha256" => bin2hex($hash), "authentication" => str_repeat("0", 64)
		], JSON_THROW_ON_ERROR));
		try{
			$this->manager();
			self::fail("Replaced an active cached pack");
		}catch(ResourcePackException $e){
			self::assertStringContainsString("active server", $e->getMessage());
		}
		self::assertSame($hash, hash_file("sha256", $path, true));
		unset($pack, $manager, $e);
		gc_collect_cycles();
		$regenerated = $this->getPack($this->manager());
		self::assertNotSame($hash, $regenerated->getSha256());
	}

	public function testOversizedKeyFilesCannotHideAnInvalidTail() : void{
		$this->createPack("oversized.zip");
		$key = str_repeat("K", 32) . str_repeat("\n", 4096) . "invalid tail";
		file_put_contents($this->packs . "/oversized.zip.key", $key);
		$this->writeConfig(["oversized.zip"], ["enabled" => true]);
		try{
			$this->manager();
			self::fail("Accepted an oversized legacy key file");
		}catch(ResourcePackException $e){
			self::assertStringContainsString("too large", $e->getMessage());
		}
		unlink($this->packs . "/oversized.zip.key");
		file_put_contents($this->packs . "/oversized.key", $key);
		$this->writeConfig(["oversized.zip"], ["enabled" => true, "key-file" => "oversized.key"]);
		$this->expectExceptionMessage("exceeds 32 bytes");
		$this->manager();
	}

	public function testActivePackSurvivesAnotherManagersKeyRotationCleanup() : void{
		$this->createPack("active.zip");
		$this->writeConfig(["active.zip"], ["enabled" => true, "key" => str_repeat("A", 32)]);
		$first = $this->manager();
		$activePack = $this->getPack($first);
		$activePath = $activePack->getPath();
		$prefix = $activePack->getPackChunk(0, 16);
		unset($first);
		gc_collect_cycles();

		$this->writeConfig(["active.zip"], ["enabled" => true, "key" => str_repeat("B", 32)]);
		$rotated = $this->manager();
		self::assertNotSame($activePath, $this->getPack($rotated)->getPath());
		self::assertFileExists($activePath);
		self::assertSame($prefix, $activePack->getPackChunk(0, 16));
	}

	public function testEncryptionCacheLockContentionFailsImmediately() : void{
		$this->createPack("locked.zip");
		$this->writeConfig(["locked.zip"], ["enabled" => true, "key" => str_repeat("L", 32)]);
		$cache = new ResourcePackEncryptionCache($this->packs, ["key" => str_repeat("L", 32)]);
		$this->expectExceptionMessage("Another process is preparing encrypted resource packs");
		$this->manager();
	}

	public function testInvalidAndSymlinkKeyFilesFailStartup() : void{
		$this->createPack("invalid-key.zip");
		file_put_contents($this->packs . DIRECTORY_SEPARATOR . "short.key", "short");
		$this->writeConfig(["invalid-key.zip"], ["enabled" => true, "key-file" => "short.key"]);
		try{
			$this->manager();
			self::fail("Accepted a short key file");
		}catch(ResourcePackException $e){
			self::assertStringNotContainsString("short", $e->getMessage());
		}

		$keyTarget = $this->packs . DIRECTORY_SEPARATOR . "target.key";
		$keyLink = $this->packs . DIRECTORY_SEPARATOR . "linked.key";
		file_put_contents($keyTarget, str_repeat("T", 32));
		if(!symlink($keyTarget, $keyLink)){
			self::markTestSkipped("Symlinks are unavailable");
		}
		$this->writeConfig(["invalid-key.zip"], ["enabled" => true, "key-file" => "linked.key"]);
		$this->expectException(ResourcePackException::class);
		$this->manager();
	}

	public function testEventAndProtocolDeliverEncryptedPackWithUppercaseManifestUuid() : void{
		$key = str_repeat("W", 32);
		$this->createPack("wire.zip", random_bytes(270 * 1024), "ABCDEFAB-CDEF-4ABC-8DEF-ABCDEFABCDEF");
		$this->createPack("plain-wire.zip");
		$this->writeConfig(["wire.zip", "plain-wire.zip"], ["enabled" => true, "packs" => ["wire.zip"], "key" => $key]);
		$manager = $this->manager();
		$pack = $manager->getResourceStack()[0];
		$otherPack = $manager->getResourceStack()[1];
		$dummyId = "00000000-0000-4000-8000-000000000099";
		$dummy = $this->createMock(ResourcePack::class);
		$dummy->method("getPackId")->willReturn($dummyId);
		$dummy->method("getPackVersion")->willReturn("1.0.0");
		$dummy->method("getPackSize")->willReturn(9);
		$dummy->method("getSha256")->willReturn(str_repeat("d", 32));
		$dummy->method("getPackChunk")->willReturn("dummydata");
		$packKey = $manager->getPackEncryptionKey($pack->getPackId());
		self::assertNotNull($packKey);
		$keys = [strtolower($pack->getPackId()) => $packKey];
		$event = new PlayerResourcePackOfferEvent($this->createMock(PlayerInfo::class), [$pack, $otherPack], $keys, false);
		$event->setResourcePacks([$otherPack, $pack, $dummy], $keys);
		self::assertSame($keys, $event->getEncryptionKeys());
		self::assertSame([$otherPack, $pack, $dummy], $event->getResourcePacks());

		$sent = [];
		$receipts = [];
		$session = $this->createMock(NetworkSession::class);
		$session->method("getLogger")->willReturn($this->createMock(\Logger::class));
		$session->method("sendDataPacket")->willReturnCallback(static function($packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		$session->method("sendDataPacketWithReceipt")->willReturnCallback(static function($packet) use (&$receipts) : Promise{
			$receipts[] = $packet;
			return Promise::resolved(null);
		});
		$handler = new ResourcePacksPacketHandler($session, $event->getResourcePacks(), $event->getEncryptionKeys(), $event->mustAccept(), static function() : void{});
		$handler->setUp();
		$info = $sent[0];
		self::assertInstanceOf(ResourcePacksInfoPacket::class, $info);
		self::assertSame([strtolower($otherPack->getPackId()), strtolower($pack->getPackId()), $dummyId], array_map(static fn($entry) => $entry->getPackId()->toString(), $info->resourcePackEntries));
		self::assertSame($key, $info->resourcePackEntries[1]->getEncryptionKey());
		self::assertSame($pack->getPackId(), $info->resourcePackEntries[1]->getContentId());
		self::assertSame($pack->getPackSize(), $info->resourcePackEntries[1]->getSizeBytes());

		self::assertTrue($handler->handleResourcePackClientResponse(ResourcePackClientResponsePacket::create(ResourcePackClientResponseStatus::SEND_PACKS, [$pack->getPackId()])));
		$dataInfo = $sent[1];
		self::assertInstanceOf(ResourcePackDataInfoPacket::class, $dataInfo);
		self::assertSame($pack->getPackId(), $dataInfo->packId);
		self::assertSame($pack->getPackSize(), $dataInfo->compressedPackSize);
		self::assertSame(hash("sha256", $this->packBytes($pack), true), $dataInfo->sha256);
		self::assertSame(2, $dataInfo->chunkCount);

		self::assertTrue($handler->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(strtolower($pack->getPackId()), 0)));
		self::assertTrue($handler->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create($pack->getPackId(), 1)));
		self::assertCount(2, $receipts);
		$received = "";
		foreach($receipts as $index => $chunk){
			self::assertInstanceOf(ResourcePackChunkDataPacket::class, $chunk);
			self::assertSame($pack->getPackId(), $chunk->packId);
			self::assertSame($index, $chunk->chunkIndex);
			self::assertSame($index * 256 * 1024, $chunk->offset);
			self::assertSame($pack->getPackChunk($chunk->offset, 256 * 1024), $chunk->data);
			$received .= $chunk->data;
		}
		self::assertSame($this->packBytes($pack), $received);
		self::assertSame($dataInfo->sha256, hash("sha256", $received, true));
	}

	private function manager() : ResourcePackManager{
		return new ResourcePackManager($this->packs, $this->createMock(\Logger::class));
	}

	/**
	 * @param list<string>         $stack
	 * @param array<string, mixed> $encryption
	 */
	private function writeConfig(array $stack, array $encryption) : void{
		$path = $this->configPath();
		file_put_contents($path, CoreConfig::encode([
			"force_resources" => false,
			"resource_stack" => $stack,
			"encryption" => $encryption
		]));
		CoreConfig::forget($path);
	}

	private function configPath() : string{
		return $this->packs . DIRECTORY_SEPARATOR . "resource_packs.toml";
	}

	private function cacheDirectory() : string{
		return $this->packs . DIRECTORY_SEPARATOR . ".quark-encryption";
	}

	private function getPack(ResourcePackManager $manager, int $index = 0) : ZippedResourcePack{
		$pack = $manager->getResourceStack()[$index] ?? null;
		self::assertInstanceOf(ZippedResourcePack::class, $pack);
		return $pack;
	}

	private function packBytes(ResourcePack $pack) : string{
		$size = $pack->getPackSize();
		if($size < 1){
			self::fail("Resource pack must not be empty");
		}
		return $pack->getPackChunk(0, $size);
	}

	private function createPack(string $name, ?string $payload = null, ?string $uuid = null) : string{
		$uuid ??= $this->uuidFor($this->packIndex++);
		$moduleUuid = $this->uuidFor($this->packIndex++);
		$manifest = json_encode([
			"format_version" => 2,
			"header" => ["name" => $name, "description" => "Test pack", "uuid" => $uuid, "version" => [1, 0, 0], "min_engine_version" => [1, 20, 0]],
			"modules" => [["type" => "resources", "uuid" => $moduleUuid, "version" => [1, 0, 0]]]
		], JSON_THROW_ON_ERROR);
		$path = $this->packs . DIRECTORY_SEPARATOR . $name;
		$archive = new \ZipArchive();
		if($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
			throw new \RuntimeException("Cannot create test resource pack");
		}
		try{
			$archive->addFromString("manifest.json", $manifest);
			$archive->addFromString("assets.bin", $payload ?? random_bytes(1024));
		}finally{
			$archive->close();
		}
		return $path;
	}

	private function createEncryptedPack(string $name, string $key) : string{
		$source = $this->createPack("source-" . $name);
		$target = $this->packs . DIRECTORY_SEPARATOR . $name;
		$packId = (new ZippedResourcePack($source))->getPackId();
		ResourcePackEncryptor::encrypt($source, $target, $key, $packId);
		unlink($source);
		return $target;
	}

	private function uuidFor(int $index) : string{
		return sprintf("00000000-0000-4000-8000-%012d", $index);
	}
}
