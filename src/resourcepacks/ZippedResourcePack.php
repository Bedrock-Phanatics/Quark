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

namespace quark\resourcepacks;

use Ahc\Json\Comment as CommentedJsonDecoder;
use quark\resourcepacks\json\Manifest;
use quark\utils\Utils;
use Ramsey\Uuid\Uuid;
use function assert;
use function fclose;
use function file_exists;
use function filesize;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function gettype;
use function hash_final;
use function hash_init;
use function hash_update_stream;
use function implode;
use function is_resource;
use function min;
use function preg_match;
use function strlen;
use const LOCK_NB;
use const LOCK_SH;

class ZippedResourcePack implements ResourcePack{
	protected string $path;
	protected Manifest $manifest;
	protected ?string $sha256 = null;
	private int $size;

	/** @var resource|null */
	protected $fileResource = null;

	/**
	 * @param string      $zipPath        Path to the resource pack zip
	 * @param string|null $verifiedSha256 Raw digest already verified by the startup encryption cache.
	 * @throws ResourcePackException
	 */
	public function __construct(string $zipPath, ?string $verifiedSha256 = null){
		if($verifiedSha256 !== null && strlen($verifiedSha256) !== 32){
			throw new \InvalidArgumentException("Resource pack SHA-256 must be 32 bytes");
		}
		$this->path = $zipPath;
		$this->sha256 = $verifiedSha256;

		if(!file_exists($zipPath)){
			throw new ResourcePackException("File not found");
		}
		$size = filesize($zipPath);
		if($size === false){
			throw new ResourcePackException("Unable to determine size of file");
		}
		if($size === 0){
			throw new ResourcePackException("Empty file, probably corrupted");
		}
		$this->size = $size;

		$archive = new \ZipArchive();
		if(($openResult = $archive->open($zipPath)) !== true){
			throw new ResourcePackException("Encountered ZipArchive error code $openResult while trying to open $zipPath");
		}

		try{
			if(($manifestData = $archive->getFromName("manifest.json", 1048577)) === false){
				$manifestPath = null;
				$manifestIdx = null;
				for($i = 0; $i < $archive->numFiles; ++$i){
					$name = Utils::assumeNotFalse($archive->getNameIndex($i), "This index should be valid");
					if(
						($manifestPath === null || strlen($name) < strlen($manifestPath)) &&
						preg_match('#.*/manifest.json$#', $name) === 1
					){
						$manifestPath = $name;
						$manifestIdx = $i;
					}
				}
				if($manifestIdx !== null){
					$manifestData = $archive->getFromIndex($manifestIdx, 1048577);
					assert($manifestData !== false);
				}elseif($archive->locateName("pack_manifest.json") !== false){
					throw new ResourcePackException("Unsupported old pack format");
				}else{
					throw new ResourcePackException("manifest.json not found in the archive root");
				}
			}

			if(strlen($manifestData) > 1048576){
				throw new ResourcePackException("Resource pack manifest exceeds 1 MiB");
			}
		}finally{
			$archive->close();
		}

		//maybe comments in the json, use stripped decoder (thanks mojang)
		try{
			$manifest = (new CommentedJsonDecoder())->decode($manifestData);
		}catch(\RuntimeException $e){
			throw new ResourcePackException("Failed to parse manifest.json: " . $e->getMessage(), 0, $e);
		}
		if(!($manifest instanceof \stdClass)){
			throw new ResourcePackException("manifest.json should contain a JSON object, not " . gettype($manifest));
		}

		$mapper = new \JsonMapper();
		$mapper->bExceptionOnMissingData = true;
		$mapper->bStrictObjectTypeChecking = true;

		try{
			/** @var Manifest $manifest */
			$manifest = $mapper->map($manifest, new Manifest());
		}catch(\JsonMapper_Exception $e){
			throw new ResourcePackException("Invalid manifest.json contents: " . $e->getMessage(), 0, $e);
		}
		if(!Uuid::isValid($manifest->header->uuid)){
			throw new ResourcePackException("Resource pack has an invalid UUID");
		}

		$this->manifest = $manifest;

		$fileResource = @fopen($zipPath, "rb");
		if($fileResource === false){
			throw new ResourcePackException("Unable to open resource pack file: $zipPath");
		}
		if(!flock($fileResource, LOCK_SH | LOCK_NB)){
			fclose($fileResource);
			throw new ResourcePackException("Resource pack file is locked for replacement");
		}
		$this->fileResource = $fileResource;
	}

	public function __destruct(){
		if(is_resource($this->fileResource)){
			fclose($this->fileResource);
		}
	}

	public function getPath() : string{
		return $this->path;
	}

	public function getPackName() : string{
		return $this->manifest->header->name;
	}

	public function getPackVersion() : string{
		return implode(".", $this->manifest->header->version);
	}

	public function getPackId() : string{
		return $this->manifest->header->uuid;
	}

	public function getPackSize() : int{
		return $this->size;
	}

	public function getSha256(bool $cached = true) : string{
		if($this->sha256 === null || !$cached){
			$stream = $this->fileResource;
			if(!is_resource($stream) || fseek($stream, 0) !== 0){
				throw new ResourcePackException("Unable to seek resource pack for hashing");
			}
			$hash = hash_init("sha256");
			if(hash_update_stream($hash, $stream) !== $this->size){
				throw new ResourcePackException("Resource pack changed while hashing");
			}
			$this->sha256 = hash_final($hash, true);
		}
		return $this->sha256;
	}

	public function getPackChunk(int $start, int $length) : string{
		if($length < 1 || $start < 0 || $start >= $this->size){
			throw new \InvalidArgumentException("Invalid resource pack chunk range");
		}
		$stream = $this->fileResource;
		if(!is_resource($stream)){
			throw new ResourcePackException("Resource pack file is closed");
		}
		if(fseek($stream, $start) !== 0){
			throw new ResourcePackException("Unable to seek resource pack file");
		}
		$length = min($length, $this->size - $start);
		if($length < 1){
			throw new \InvalidArgumentException("Invalid resource pack chunk range");
		}
		$data = fread($stream, $length);
		if($data === false || strlen($data) !== $length){
			throw new ResourcePackException("Unable to read resource pack chunk");
		}
		return $data;
	}
}
