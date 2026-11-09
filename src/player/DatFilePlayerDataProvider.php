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

namespace quark\player;

use Bedwire\Nbt;
use Bedwire\NbtException;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use quark\utils\Filesystem;
use quark\utils\Utils;
use Symfony\Component\Filesystem\Path;
use Vow\Promise;
use function file_exists;
use function strtolower;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_GZIP;

/**
 * Stores player data in a single .dat file per player. Each file is gzipped big-endian NBT.
 */
final class DatFilePlayerDataProvider implements PlayerDataProvider{

	public function __construct(
		private string $path
	){}

	private function getPlayerDataPath(string $username) : string{
		return Path::join($this->path, strtolower($username) . '.dat');
	}

	/**
	 * Moves damaged data to a backup that doesn't replace an earlier one. Only data that was set aside is reported as
	 * corrupt, anything that's still in place must not be treated as a new player or it would be overwritten.
	 */
	private function setAsideCorruptedPlayerData(string $name, string $problem, \Throwable $previous) : PlayerDataLoadException{
		$path = $this->getPlayerDataPath($name);
		$backup = $path . '.bak';
		for($i = 1; file_exists($backup); ++$i){
			$backup = $path . '.bak.' . $i;
		}
		if(@rename($path, $backup)){
			return new CorruptPlayerDataException("$problem (moved to \"$backup\")", 0, $previous);
		}
		return new PlayerDataLoadException("$problem (and it couldn't be moved aside)", 0, $previous);
	}

	public function hasData(string $name) : Promise{
		return Promise::resolved(file_exists($this->getPlayerDataPath($name)));
	}

	public function loadData(string $name) : Promise{
		try{
			return Promise::resolved($this->read($name));
		}catch(PlayerDataLoadException $e){
			return Promise::rejected($e);
		}
	}

	public function saveData(string $name, CompoundTag $data) : Promise{
		try{
			$this->write($name, $data);
			return Promise::resolved(null);
		}catch(PlayerDataSaveException $e){
			return Promise::rejected($e);
		}
	}

	private function read(string $name) : ?CompoundTag{
		$name = strtolower($name);
		$path = $this->getPlayerDataPath($name);

		if(!file_exists($path)){
			return null;
		}

		try{
			$contents = Filesystem::fileGetContents($path);
		}catch(\RuntimeException $e){
			throw new PlayerDataLoadException("Failed to read player data file \"$path\": " . $e->getMessage(), 0, $e);
		}
		try{
			$decompressed = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => zlib_decode($contents));
		}catch(\ErrorException $e){
			throw $this->setAsideCorruptedPlayerData($name, "Failed to decompress raw player data for \"$name\": " . $e->getMessage(), $e);
		}

		try{
			return Nbt::read($decompressed, Nbt::JAVA)->mustGetCompoundTag();
		}catch(NbtException|NbtDataException $e){ //corrupt data
			throw $this->setAsideCorruptedPlayerData($name, "Failed to decode NBT data for \"$name\": " . $e->getMessage(), $e);
		}
	}

	private function write(string $name, CompoundTag $data) : void{
		$contents = Utils::assumeNotFalse(zlib_encode(Nbt::write(new TreeRoot($data), Nbt::JAVA), ZLIB_ENCODING_GZIP), "zlib_encode() failed unexpectedly");
		try{
			Filesystem::safeFilePutContents($this->getPlayerDataPath($name), $contents);
		}catch(\RuntimeException $e){
			throw new PlayerDataSaveException("Failed to write player data file: " . $e->getMessage(), 0, $e);
		}
	}
}
