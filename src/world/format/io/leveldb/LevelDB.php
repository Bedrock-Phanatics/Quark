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

namespace quark\world\format\io\leveldb;

use pmmp\encoding\LE;
use quark\utils\Utils;
use quark\VersionInfo;
use quark\world\format\io\data\BedrockWorldData;
use quark\world\format\io\exception\CorruptedChunkException;
use quark\world\format\io\exception\CorruptedWorldException;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function defined;
use function extension_loaded;
use function file_exists;
use function is_dir;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use const LEVELDB_ZLIB_RAW_COMPRESSION;

class LevelDB extends BedrockWorldProvider{

	protected \LevelDB $db;

	private static function checkForLevelDBExtension() : void{
		if(!extension_loaded('leveldb')){
			throw new UnsupportedWorldFormatException("The leveldb PHP extension is required to use this world format");
		}

		if(!defined('LEVELDB_ZLIB_RAW_COMPRESSION')){
			throw new UnsupportedWorldFormatException("Given version of php-leveldb doesn't support zlib raw compression");
		}
	}

	/**
	 * @throws \LevelDBException
	 */
	private static function createDB(string $path) : \LevelDB{
		return new \LevelDB(Path::join($path, "db"), [
			"compression" => LEVELDB_ZLIB_RAW_COMPRESSION,
			//a chunk read inflates a whole block, and 16KB loads chunks ~40% faster than 64KB for ~20% more disk
			"block_size" => 16 * 1024,
			//the 4MB default fills during an autosave and blocks the main thread until the flush catches up
			"write_buffer_size" => 16 * 1024 * 1024
		]);
	}

	public function __construct(string $path, \Logger $logger){
		self::checkForLevelDBExtension();
		parent::__construct($path, $logger);

		try{
			$this->db = self::createDB($path);
		}catch(\LevelDBException $e){
			//we can't tell the difference between errors caused by bad permissions and actual corruption :(
			throw new CorruptedWorldException(trim($e->getMessage()), 0, $e);
		}
	}

	public static function isValid(string $path) : bool{
		return file_exists(Path::join($path, "level.dat")) && is_dir(Path::join($path, "db"));
	}

	public static function generate(string $path, string $name, WorldCreationOptions $options) : void{
		self::checkForLevelDBExtension();

		$dbPath = Path::join($path, "db");
		if(!file_exists($dbPath)){
			mkdir($dbPath, 0777, true);
		}

		BedrockWorldData::generate($path, $name, $options);
	}

	/**
	 * One scan is much cheaper than a lookup per record, since most of them don't exist.
	 */
	protected function readChunkRecords(int $chunkX, int $chunkZ) : array{
		$index = self::chunkIndex($chunkX, $chunkZ);
		$records = [];
		$iterator = $this->db->getIterator();
		for($iterator->seek($index); $iterator->valid(); $iterator->next()){
			$key = $iterator->key();
			if(!str_starts_with($key, $index)){
				break;
			}
			//longer keys belong to other dimensions
			if(strlen($key) <= 10){
				$records[substr($key, 8)] = $iterator->current();
			}
		}
		return $records;
	}

	protected function writeChunkRecords(int $chunkX, int $chunkZ, array $records) : void{
		$index = self::chunkIndex($chunkX, $chunkZ);
		$write = new \LevelDBWriteBatch();
		$write->put($index . ChunkDataKey::PM_DATA_VERSION, LE::packSignedLong(VersionInfo::WORLD_DATA_VERSION));
		foreach(Utils::promoteKeys($records) as $suffix => $value){
			if($value !== null){
				$write->put($index . $suffix, $value);
			}else{
				$write->delete($index . $suffix);
			}
		}
		$this->db->write($write);
	}

	public function getDatabase() : \LevelDB{
		return $this->db;
	}

	public static function chunkIndex(int $chunkX, int $chunkZ) : string{
		return LE::packSignedInt($chunkX) . LE::packSignedInt($chunkZ);
	}

	public function doGarbageCollection() : void{

	}

	public function close() : void{
		unset($this->db);
	}

	public function getAllChunks(bool $skipCorrupted = false, ?\Logger $logger = null) : \Generator{
		foreach($this->db->getIterator() as $key => $_){
			if(strlen($key) === 9 && ($key[8] === ChunkDataKey::NEW_VERSION || $key[8] === ChunkDataKey::OLD_VERSION)){
				$index = substr($key, 0, 8);
				if($key[8] === ChunkDataKey::OLD_VERSION && $this->db->get($index . ChunkDataKey::NEW_VERSION) !== false){
					continue;
				}

				$chunkX = LE::unpackSignedInt(substr($index, 0, 4));
				$chunkZ = LE::unpackSignedInt(substr($key, 4, 4));
				try{
					if(($chunk = $this->loadChunk($chunkX, $chunkZ)) !== null){
						yield [$chunkX, $chunkZ] => $chunk;
					}
				}catch(CorruptedChunkException $e){
					if(!$skipCorrupted){
						throw $e;
					}
					if($logger !== null){
						$logger->error("Skipped corrupted chunk $chunkX $chunkZ (" . $e->getMessage() . ")");
					}
				}
			}
		}
	}

	public function calculateChunkCount() : int{
		$count = 0;
		foreach($this->db->getIterator() as $key => $_){
			if(strlen($key) === 9 && ($key[8] === ChunkDataKey::NEW_VERSION || $key[8] === ChunkDataKey::OLD_VERSION)){
				$index = substr($key, 0, 8);
				if($key[8] !== ChunkDataKey::OLD_VERSION || $this->db->get($index . ChunkDataKey::NEW_VERSION) === false){
					++$count;
				}
			}
		}
		return $count;
	}
}
