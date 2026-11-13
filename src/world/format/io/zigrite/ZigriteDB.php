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

namespace quark\world\format\io\zigrite;

use quark\world\format\io\data\BedrockWorldData;
use quark\world\format\io\exception\CorruptedChunkException;
use quark\world\format\io\exception\CorruptedWorldException;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\format\io\exception\WorldInUseException;
use quark\world\format\io\leveldb\BedrockWorldProvider;
use quark\world\format\io\WorldData;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use Zigrite\Exception as ZigriteException;
use Zigrite\World as ZigriteWorld;
use function count;
use function extension_loaded;
use function file_exists;
use function mkdir;
use function phpversion;
use function sprintf;
use function version_compare;

/**
 * Bedrock chunk records stored in ZigriteDB through ext-zigritedb. `zigrite import` turns a LevelDB world into one,
 * and `zigrite export` turns it back.
 */
final class ZigriteDB extends BedrockWorldProvider{
	/** Where ZigriteDB keeps level.dat and the other Bedrock world files, as `zigrite import` lays them out. */
	private const BEDROCK_DIR = "bedrock";
	private const OVERWORLD = 0;
	private const MIN_EXTENSION_VERSION = "0.3.2";
	/** ZigriteDB status codes, see zigritedb.h. */
	private const STATUS_CORRUPTION = 6;
	private const STATUS_NEEDS_RECOVERY = 8;
	private const STATUS_BUSY = 9;
	private const STATUS_NEEDS_MIGRATION = 16;

	private ZigriteWorld $world;

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, int|bool>
	 */
	private static function options() : array{
		return [
			//a save rewrites every record of the chunk, and most of them haven't changed
			"skip_unchanged" => true,
			//the most one atomic write can hold; a bigger chunk is still saved, but in several writes a crash can split
			"batch_buffer_size" => 64 * 1024 * 1024,
			//an evicted region is synced and reopened later, which made random loads 15x slower at the default 64
			"max_open_regions" => 256,
		];
	}

	private static function checkForExtension() : void{
		if(!extension_loaded("zigritedb")){
			throw new UnsupportedWorldFormatException("The zigritedb PHP extension is required to use this world format");
		}
		//older builds leave a region unusable after a crash cuts a write short, and size their buffers by batch_buffer_size
		$version = phpversion("zigritedb");
		if($version === false || version_compare($version, self::MIN_EXTENSION_VERSION, "<")){
			throw new UnsupportedWorldFormatException("ZigriteDB worlds need ext-zigritedb " . self::MIN_EXTENSION_VERSION . " or newer, found " . ($version === false ? "none" : $version));
		}
		if(!ZigriteWorld::isSupported()){
			throw new UnsupportedWorldFormatException("ZigriteDB can't store worlds on this platform");
		}
	}

	public function __construct(string $path, \Logger $logger){
		self::checkForExtension();
		parent::__construct($path, $logger);

		try{
			$this->world = new ZigriteWorld($path, self::options());
		}catch(ZigriteException $e){
			throw match($e->getCode()){
				self::STATUS_NEEDS_MIGRATION => new UnsupportedWorldFormatException($e->getMessage(), 0, $e),
				//another server, or a zigrite command, has the world open
				self::STATUS_BUSY => new WorldInUseException($e->getMessage(), 0, $e),
				default => new CorruptedWorldException($e->getMessage(), 0, $e),
			};
		}
	}

	protected function loadLevelData() : WorldData{
		return new BedrockWorldData(Path::join($this->getPath(), self::BEDROCK_DIR, "level.dat"));
	}

	public static function isValid(string $path) : bool{
		return file_exists(Path::join($path, "ZIGRITE")) && file_exists(Path::join($path, self::BEDROCK_DIR, "level.dat"));
	}

	public static function generate(string $path, string $name, WorldCreationOptions $options) : void{
		self::checkForExtension();

		if(!file_exists($path)){
			mkdir($path, 0777, true);
		}
		//opening an empty directory creates the store
		(new ZigriteWorld($path, self::options()))->close();

		$bedrockPath = Path::join($path, self::BEDROCK_DIR);
		if(!file_exists($bedrockPath)){
			mkdir($bedrockPath, 0777, true);
		}
		BedrockWorldData::generate($bedrockPath, $name, $options);
	}

	protected function readChunkRecords(int $chunkX, int $chunkZ) : array{
		try{
			return $this->world->getChunk(self::OVERWORLD, $chunkX, $chunkZ) ?? [];
		}catch(ZigriteException $e){
			if($e->getCode() === self::STATUS_CORRUPTION){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
			throw $this->explain($chunkX, $chunkZ, $e);
		}
	}

	protected function writeChunkRecords(int $chunkX, int $chunkZ, array $records) : void{
		try{
			$this->world->writeChunk(self::OVERWORLD, $chunkX, $chunkZ, $records);
		}catch(ZigriteException $e){
			throw $this->explain($chunkX, $chunkZ, $e);
		}
	}

	/**
	 * A region that lost data it had already synced refuses every read and write until it is recovered by hand. That
	 * isn't a corrupted chunk: treating it as one would regenerate the whole region over data that can still be saved.
	 */
	private function explain(int $chunkX, int $chunkZ, ZigriteException $e) : \Throwable{
		if($e->getCode() !== self::STATUS_NEEDS_RECOVERY){
			return $e;
		}
		$region = Path::join($this->getPath(), sprintf("%08x-%08x-%08x.region", self::OVERWORLD, ($chunkX >> 5) & 0xffffffff, ($chunkZ >> 5) & 0xffffffff));
		return new \RuntimeException("Region $region is damaged. Stop the server, run \"zigrite recover\" on it with an empty folder as the destination, and put the result in its place. " . $e->getMessage(), 0, $e);
	}

	public function doGarbageCollection() : void{

	}

	/**
	 * Chunk writes are buffered: they survive a crash of the server, but only a flush makes them survive a power loss.
	 */
	public function flush() : void{
		$this->world->flush();
	}

	public function close() : void{
		$this->world->close();
	}

	public function getAllChunks(bool $skipCorrupted = false, ?\Logger $logger = null) : \Generator{
		foreach($this->world->listChunks(self::OVERWORLD) as [$chunkX, $chunkZ]){
			try{
				if(($chunk = $this->loadChunk($chunkX, $chunkZ)) !== null){
					yield [$chunkX, $chunkZ] => $chunk;
				}
			}catch(CorruptedChunkException $e){
				if(!$skipCorrupted){
					throw $e;
				}
				$logger?->error("Skipped corrupted chunk $chunkX $chunkZ (" . $e->getMessage() . ")");
			}
		}
	}

	public function calculateChunkCount() : int{
		return count($this->world->listChunks(self::OVERWORLD));
	}
}
