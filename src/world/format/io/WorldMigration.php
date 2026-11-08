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

namespace quark\world\format\io;

use quark\utils\Filesystem;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\io\zigrite\ZigriteDB;
use Symfony\Component\Filesystem\Path;
use Zigrite\Exception as ZigriteException;
use Zigrite\World as ZigriteWorld;
use function basename;
use function bin2hex;
use function date;
use function dirname;
use function disk_free_space;
use function extension_loaded;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function fsync;
use function is_dir;
use function is_link;
use function mkdir;
use function phpversion;
use function preg_match;
use function random_bytes;
use function rename;
use function round;
use function rtrim;
use function scandir;
use function version_compare;
use const SCANDIR_SORT_NONE;

/**
 * Converts unloaded worlds between LevelDB and ZigriteDB with ZigriteDB's native converter, which copies every
 * database record and world file as is and compares the result with the source before handing it over.
 *
 * The converted world is built in a hidden folder next to the world. Activation moves the world to a hidden
 * ".<name>.original-*" folder, moves the converted world into its place, then moves the original to the backups.
 * recover() finishes or undoes that after a crash, so a world folder is never left missing.
 */
final class WorldMigration{
	public const MODE_OFF = "off";
	public const MODE_MANUAL = "manual";
	public const MODE_AUTOMATIC = "automatic";

	public const LEVELDB = "leveldb";
	public const ZIGRITEDB = "zigritedb";

	/** Hidden folders a conversion leaves next to the world, including the native converter's own partial output. */
	public const STAGING_PATTERN = '/^\..+\.converting-[0-9a-f]{8}(?:\.zigrite-partial)?$/D';
	private const ORIGINAL_PATTERN = '/^\.(.+)\.original-[0-9a-f]{8}$/D';

	private const STATUS_NEEDS_MIGRATION = 16;

	private function __construct(){
		//NOOP
	}

	/**
	 * @throws UnsupportedWorldFormatException
	 */
	public static function checkSupported() : void{
		$version = extension_loaded("zigritedb") ? phpversion("zigritedb") : false;
		if($version === false || version_compare($version, "0.3.0", "<")){
			throw new UnsupportedWorldFormatException("Converting worlds needs ext-zigritedb 0.3.0 or newer, found " . ($version === false ? "none" : $version));
		}
		if(!ZigriteWorld::isSupported()){
			throw new UnsupportedWorldFormatException("ZigriteDB can't store worlds on this platform, so worlds can't be converted here");
		}
	}

	public static function detectFormat(string $path) : ?string{
		$leveldb = LevelDB::isValid($path);
		$zigrite = ZigriteDB::isValid($path);
		return $leveldb === $zigrite ? null : ($leveldb ? self::LEVELDB : self::ZIGRITEDB);
	}

	/**
	 * Whether a ZigriteDB world was written in an older disk format and must be migrated before it can be loaded.
	 */
	public static function needsFormatMigration(string $path) : bool{
		try{
			(new ZigriteWorld($path))->close();
			return false;
		}catch(ZigriteException $e){
			if($e->getCode() === self::STATUS_NEEDS_MIGRATION){
				return true;
			}
			throw $e;
		}
	}

	/**
	 * Converts an unloaded world in place and keeps the original under $backupDir. Blocks until done, which can take
	 * minutes for a large world, so the server only calls it off the main thread or before the world loads.
	 *
	 * @return int[]|string[]
	 * @phpstan-return array{from: string, backup: string, chunk_records: int, other_records: int, bytes: int, files: int}
	 *
	 * @throws \RuntimeException with a message saying what to do
	 */
	public static function convert(string $path, string $format, string $backupDir) : array{
		self::checkSupported();
		$path = rtrim($path, "/\\");
		if(is_link($path)){
			throw new \RuntimeException("$path is a symlink. Convert the world in the folder it points to.");
		}
		$from = self::detectFormat($path);
		if($from === null){
			throw new \RuntimeException("$path is not a LevelDB or ZigriteDB world");
		}
		if($format !== self::LEVELDB && $format !== self::ZIGRITEDB){
			throw new \InvalidArgumentException("Worlds can only be converted to " . self::LEVELDB . " or " . self::ZIGRITEDB);
		}
		$formatMigration = $from === self::ZIGRITEDB && $format === self::ZIGRITEDB;
		if($from === $format && !($formatMigration && self::needsFormatMigration($path))){
			throw new \RuntimeException("$path is already a $format world");
		}

		$parent = dirname($path);
		$name = basename($path);
		self::checkFreeSpace($path, $parent, $format);
		$staging = Path::join($parent, ".$name.converting-" . bin2hex(random_bytes(4)));
		try{
			$result = match(true){
				$formatMigration => ZigriteWorld::migrate($path, $staging),
				$format === self::ZIGRITEDB => ZigriteWorld::importBedrock($path, $staging),
				default => ZigriteWorld::exportBedrock($path, $staging)
			};
			if($formatMigration){
				ZigriteWorld::verify($staging);
			}
		}catch(\Throwable $e){
			self::removeQuietly($staging);
			throw new \RuntimeException("Could not convert $path, the world was not changed: " . $e->getMessage(), 0, $e);
		}

		$original = Path::join($parent, ".$name.original-" . bin2hex(random_bytes(4)));
		if(!@rename($path, $original)){
			self::removeQuietly($staging);
			throw new \RuntimeException("Could not move $path aside to activate the converted world, the world was not changed");
		}
		self::syncDirectory($parent);
		if(!@rename($staging, $path)){
			if(@rename($original, $path)){
				self::removeQuietly($staging);
				throw new \RuntimeException("Could not activate the converted world, the original was put back at $path");
			}
			throw new \RuntimeException("Could not activate the converted world or put the original back. The original is at $original and the server puts it back on its next start");
		}
		self::syncDirectory($parent);

		return [
			"from" => $from,
			"backup" => self::moveToBackups($original, $name, $from, $backupDir),
			"chunk_records" => $result["chunk_records"] ?? 0,
			"other_records" => $result["other_records"] ?? 0,
			"bytes" => $result["bytes"] ?? 0,
			"files" => $result["files"] ?? 0
		];
	}

	/**
	 * Checks every frame of every region of an unloaded ZigriteDB world.
	 *
	 * @return int[]
	 * @phpstan-return array<string, int>
	 */
	public static function verify(string $path) : array{
		self::checkSupported();
		$format = self::detectFormat($path);
		if($format !== self::ZIGRITEDB){
			throw new \RuntimeException($format === self::LEVELDB ?
				"Only ZigriteDB worlds have checksums to verify. LevelDB worlds are checked record by record when converted." :
				"$path is not a ZigriteDB world"
			);
		}
		return ZigriteWorld::verify($path);
	}

	/**
	 * Finishes or undoes conversions a crash interrupted in $dir: a world whose folder is missing gets its original
	 * back, and an original left beside a converted world goes to the backups. Staging folders are left for the
	 * usual cleanup.
	 *
	 * @return string[] what was done, for logging
	 * @phpstan-return list<string>
	 */
	public static function recover(string $dir, string $backupDir) : array{
		$done = [];
		$entries = @scandir($dir, SCANDIR_SORT_NONE);
		foreach($entries === false ? [] : $entries as $entry){
			if(preg_match(self::ORIGINAL_PATTERN, $entry, $matches) !== 1 || is_link($original = Path::join($dir, $entry)) || !is_dir($original)){
				continue;
			}
			$world = Path::join($dir, $matches[1]);
			if(!file_exists($world) && !is_link($world)){
				$done[] = @rename($original, $world) ?
					"Restored world $world, its conversion was interrupted before it finished" :
					"Could not restore world $world from $original after an interrupted conversion, move it back by hand";
			}else{
				$backup = self::moveToBackups($original, $matches[1], self::detectFormat($original) ?? "unknown", $backupDir);
				$done[] = "Finished converting world $world, its original is at $backup";
			}
		}
		if($done !== []){
			self::syncDirectory($dir);
		}
		return $done;
	}

	/**
	 * Returns where the original ended up. It stays in its hidden folder when it can't be moved, for example when the
	 * backups are on another drive, and the next start tries again.
	 */
	private static function moveToBackups(string $original, string $name, string $from, string $backupDir) : string{
		if(!is_dir($backupDir) && !@mkdir($backupDir, 0777, true) && !is_dir($backupDir)){
			return $original;
		}
		$base = Path::join($backupDir, "$name-$from-" . date("Y-m-d_H-i-s"));
		$backup = $base;
		for($i = 2; file_exists($backup) || is_link($backup); ++$i){
			$backup = "$base-$i";
		}
		if(!@rename($original, $backup)){
			return $original;
		}
		self::syncDirectory($backupDir);
		self::syncDirectory(dirname($original));
		return $backup;
	}

	/**
	 * Fails early instead of filling the disk the running server saves to.
	 * ponytail: rough factors from real worlds, where ZigriteDB took ~2.5x the space of zlib LevelDB
	 */
	private static function checkFreeSpace(string $path, string $parent, string $format) : void{
		$free = @disk_free_space($parent);
		if($free === false){
			return;
		}
		$needed = (int) (self::size($path) * ($format === self::ZIGRITEDB ? 3 : 1.2));
		if($free < $needed){
			throw new \RuntimeException("Converting $path needs about " . round($needed / 1048576) . " MB free next to it, but only " . round($free / 1048576) . " MB is free");
		}
	}

	private static function size(string $path) : int{
		$total = 0;
		$entries = @scandir($path, SCANDIR_SORT_NONE);
		foreach($entries === false ? [] : $entries as $entry){
			if($entry === "." || $entry === ".." || is_link($child = Path::join($path, $entry))){
				continue;
			}
			$total += is_dir($child) ? self::size($child) : (int) @filesize($child);
		}
		return $total;
	}

	/**
	 * Makes renames in a directory durable, where the OS lets PHP open directories.
	 */
	private static function syncDirectory(string $dir) : void{
		$handle = @fopen($dir, "r");
		if($handle !== false){
			@fsync($handle);
			fclose($handle);
		}
	}

	private static function removeQuietly(string $path) : void{
		try{
			Filesystem::recursiveUnlink($path);
		}catch(\RuntimeException){
			//the cleanup at the next start removes it
		}
	}
}
