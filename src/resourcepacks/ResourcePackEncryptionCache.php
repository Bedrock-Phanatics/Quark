<?php

/*
 * Copyright (c) Quark Team.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\resourcepacks;

use quark\utils\Filesystem;
use function assert;
use function base64_encode;
use function basename;
use function bin2hex;
use function chmod;
use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function hash_equals;
use function hash_file;
use function hash_hmac;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function strlen;
use function substr;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

final class ResourcePackEncryptionCache{
	private const FORMAT_VERSION = 1;
	private string $directory;
	private string $key;
	/** @var resource|null */
	private $lock = null;
	/** @var array<string, true> */
	private array $leases = [];

	/** @param array{enabled?: bool, packs?: list<string>, key?: string, key-file?: string} $settings */
	public function __construct(string $packsDirectory, #[\SensitiveParameter] array $settings){
		$this->directory = $packsDirectory . "/.quark-encryption";
		if(is_link($this->directory) || (!is_dir($this->directory) && !@mkdir($this->directory, 0700))){
			throw new ResourcePackException("Cannot create private resource-pack encryption cache");
		}
		$this->restrictPermissions($this->directory, 0700);
		$lockPath = $this->directory . "/lock";
		if(is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))){
			throw new ResourcePackException("Invalid resource-pack encryption cache lock");
		}
		$lock = @fopen($lockPath, "c+b");
		if($lock === false){
			throw new ResourcePackException("Cannot open resource-pack encryption cache lock");
		}
		if(!flock($lock, LOCK_EX | LOCK_NB)){
			fclose($lock);
			throw new ResourcePackException("Another process is preparing encrypted resource packs; retry startup when it finishes");
		}
		$this->lock = $lock;
		try{
			$this->restrictPermissions($lockPath, 0600);
			$this->removeInterruptedWrites();
			$key = $settings["key"] ?? "";
			if($key === ""){
				$keyFile = $settings["key-file"] ?? "";
				$keyPath = $packsDirectory . "/" . ($keyFile !== "" ? $keyFile : ".encryption.key");
				if($keyFile === "" && !file_exists($keyPath) && !is_link($keyPath)){
					$this->publish($keyPath, base64_encode(random_bytes(24)));
				}
				if(is_link($keyPath) || !is_file($keyPath)){
					throw new ResourcePackException("Resource-pack encryption key file must be an existing regular local file");
				}
				$this->restrictPermissions($keyPath, 0600);
				$key = Filesystem::fileGetContents($keyPath, false, null, 0, 35);
				if(strlen($key) > 34){
					throw new ResourcePackException("Resource-pack encryption key file exceeds 32 bytes plus a final newline");
				}
				$key = rtrim($key, "\r\n");
			}
			if(preg_match('/^[\x20-\x7e]{32}$/D', $key) !== 1){
				throw new ResourcePackException("Resource-pack encryption key must contain exactly 32 printable ASCII bytes");
			}
			$this->key = $key;
		}catch(\Throwable $e){
			$this->unlock();
			throw $e;
		}
	}

	public function __destruct(){
		$this->unlock();
	}

	public function getKey() : string{
		return $this->key;
	}

	public function prepare(ZippedResourcePack $source) : ZippedResourcePack{
		if(!is_resource($this->lock)){
			throw new \LogicException("Resource-pack encryption preparation has finished");
		}
		$sourceHash = $source->getSha256();
		$identity = hash_hmac("sha256", self::FORMAT_VERSION . "\0" . $sourceHash, $this->key);
		$path = $this->directory . "/pack-" . $identity . ".zip";
		$metadata = $path . ".json";
		$pack = $this->loadCached($path, $metadata, $identity);
		if($pack === null){
			$this->removeArchive($path);
			$this->removeFile($metadata);
			$temporary = $this->directory . "/.building-" . bin2hex(random_bytes(16));
			if(!mkdir($temporary, 0700)){
				throw new ResourcePackException("Cannot create resource-pack encryption workspace");
			}
			try{
				$output = $temporary . "/pack.zip";
				ResourcePackEncryptor::encrypt($source->getPath(), $output, $this->key, $source->getPackId());
				$this->restrictPermissions($output, 0600);
				$prepared = new ZippedResourcePack($output);
				$hash = $prepared->getSha256();
				if($prepared->getPackId() !== $source->getPackId() || $prepared->getPackVersion() !== $source->getPackVersion() || !ResourcePackEncryptor::isEncrypted($output)){
					throw new ResourcePackException("Encrypted resource-pack verification failed");
				}
				unset($prepared);
				$currentHash = hash_file("sha256", $source->getPath(), true);
				if($currentHash === false || !hash_equals($sourceHash, $currentHash)){
					throw new ResourcePackException("Resource pack changed during encryption; retry startup");
				}
				if(!rename($output, $path)){
					throw new ResourcePackException("Cannot publish encrypted resource pack");
				}
				$this->publish($metadata, json_encode([
					"sha256" => bin2hex($hash),
					"authentication" => hash_hmac("sha256", $identity . $hash, $this->key)
				], JSON_THROW_ON_ERROR));
				$pack = new ZippedResourcePack($path, $hash);
			}finally{
				Filesystem::recursiveUnlink($temporary);
			}
		}
		$this->leases[basename($path)] = true;
		return $pack;
	}

	private function loadCached(string $path, string $metadata, string $identity) : ?ZippedResourcePack{
		if(is_link($path) || is_link($metadata)){
			throw new ResourcePackException("Symbolic links are not allowed in the encryption cache");
		}
		if(!is_file($path) || !is_file($metadata)){
			return null;
		}
		try{
			$data = json_decode(Filesystem::fileGetContents($metadata, false, null, 0, 512), true, 8, JSON_THROW_ON_ERROR);
			if(!is_array($data) || !is_string($data["sha256"] ?? null) || !is_string($data["authentication"] ?? null)){
				return null;
			}
			$hash = hash_file("sha256", $path, true);
			if($hash === false || !hash_equals($data["sha256"], bin2hex($hash)) || !hash_equals($data["authentication"], hash_hmac("sha256", $identity . $hash, $this->key))){
				return null;
			}
			return new ZippedResourcePack($path, $hash);
		}catch(\RuntimeException|\JsonException $e){
			return null;
		}
	}

	public function finish() : void{
		if(!is_resource($this->lock)){
			return;
		}
		try{
			foreach(new \FilesystemIterator($this->directory) as $entry){
				assert($entry instanceof \SplFileInfo);
				$name = $entry->getFilename();
				if(preg_match('/^pack-[a-f0-9]{64}\.zip$/D', $name) === 1 && !isset($this->leases[$name])){
					if($this->removeArchive($entry->getPathname(), false)){
						$this->removeFile($entry->getPathname() . ".json");
					}
				}elseif(preg_match('/^pack-[a-f0-9]{64}\.zip\.json$/D', $name) === 1 && !file_exists(substr($entry->getPathname(), 0, -5))){
					$this->removeFile($entry->getPathname());
				}
			}
		}finally{
			$this->unlock();
		}
	}

	private function unlock() : void{
		if(is_resource($this->lock)){
			flock($this->lock, LOCK_UN);
			fclose($this->lock);
			$this->lock = null;
		}
	}

	private function removeInterruptedWrites() : void{
		foreach(new \FilesystemIterator($this->directory) as $entry){
			assert($entry instanceof \SplFileInfo);
			if(preg_match('/^\.building-[a-f0-9]{32}$/D', $entry->getFilename()) === 1){
				Filesystem::recursiveUnlink($entry->getPathname());
			}
		}
	}

	private function removeArchive(string $path, bool $required = true) : bool{
		if(is_link($path)){
			throw new ResourcePackException("Symbolic links are not allowed in the encryption cache");
		}
		if(!file_exists($path)){
			return true;
		}
		if(!is_file($path)){
			throw new ResourcePackException("Invalid encryption cache file");
		}
		$stream = @fopen($path, "rb");
		if($stream === false){
			throw new ResourcePackException("Cannot open obsolete encryption cache file");
		}
		// Pack streams retain shared locks until their last user releases them.
		$unused = flock($stream, LOCK_EX | LOCK_NB);
		fclose($stream);
		if(!$unused){
			if($required){
				throw new ResourcePackException("An active server is using a corrupt encryption cache file; stop it before regenerating");
			}
			return false;
		}
		$this->removeFile($path);
		return true;
	}

	private function removeFile(string $path) : void{
		if(is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))){
			throw new ResourcePackException("Cannot remove obsolete encryption cache file");
		}
	}

	private function publish(string $path, #[\SensitiveParameter] string $contents) : void{
		$temporary = $this->directory . "/.building-" . bin2hex(random_bytes(16));
		if(!mkdir($temporary, 0700)){
			throw new ResourcePackException("Cannot create private encryption workspace");
		}
		try{
			Filesystem::atomicWrite($temporary . "/data", $contents);
			$this->restrictPermissions($temporary . "/data", 0600);
			if(file_exists($path) || is_link($path) || !rename($temporary . "/data", $path)){
				throw new ResourcePackException("Cannot publish resource-pack encryption metadata or key");
			}
		}finally{
			Filesystem::recursiveUnlink($temporary);
		}
	}

	private function restrictPermissions(string $path, int $mode) : void{
		if(DIRECTORY_SEPARATOR === "/" && !@chmod($path, $mode)){
			throw new ResourcePackException("Cannot restrict resource-pack encryption file permissions");
		}
	}
}
