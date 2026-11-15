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

use quark\CoreConfig;
use quark\utils\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function assert;
use function chmod;
use function copy;
use function count;
use function file_exists;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rtrim;
use function strlen;
use function strpbrk;
use function strtolower;
use const DIRECTORY_SEPARATOR;

class ResourcePackManager{
	private string $path;
	private bool $serverForceResources = false;

	/**
	 * @var ResourcePack[]
	 * @phpstan-var list<ResourcePack>
	 */
	private array $resourcePacks = [];

	/**
	 * @var ResourcePack[]
	 * @phpstan-var array<string, ResourcePack>
	 */
	private array $uuidList = [];

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $encryptionKeys = [];

	/**
	 * @param string $path Path to resource-packs directory.
	 */
	public function __construct(string $path, \Logger $logger){
		$this->path = $path;

		if(is_link($this->path)){
			throw new \InvalidArgumentException("Resource packs path $path must not be a symbolic link");
		}
		if(!file_exists($this->path)){
			$logger->debug("Resource packs path $path does not exist, creating directory");
			if(!mkdir($this->path)){
				throw new \InvalidArgumentException("Cannot create resource packs path $path");
			}
		}elseif(!is_dir($this->path)){
			throw new \InvalidArgumentException("Resource packs path $path exists and is not a directory");
		}

		$resourcePacksToml = Path::join($this->path, "resource_packs.toml");
		if(!file_exists($resourcePacksToml)){
			//startup migration converts it, so one left over means that didn't run and its settings would be lost
			if(file_exists(Path::join($this->path, "resource_packs.yml"))){
				throw new \InvalidArgumentException("Resource pack configuration resource_packs.yml must be converted to resource_packs.toml");
			}
			if(is_link($resourcePacksToml) || !copy(Path::join(\quark\RESOURCE_PATH, "resource_packs.toml"), $resourcePacksToml)){
				throw new \InvalidArgumentException("Cannot create resource pack configuration $resourcePacksToml");
			}
		}elseif(is_link($resourcePacksToml)){
			throw new \InvalidArgumentException("Resource pack configuration must not be a symbolic link: $resourcePacksToml");
		}

		$resourcePacksConfig = CoreConfig::load($resourcePacksToml);
		CoreConfig::validateResourcePacks($resourcePacksConfig);
		$this->serverForceResources = $resourcePacksConfig["force_resources"] ?? false;
		$encryption = $resourcePacksConfig["encryption"] ?? [];
		$encryptionCache = null;
		if($encryption["enabled"] ?? false){
			if(($encryption["key"] ?? "") !== "" && DIRECTORY_SEPARATOR === "/" && !@chmod($resourcePacksToml, 0600)){
				throw new ResourcePackException("Cannot restrict permissions on the resource-pack encryption configuration");
			}
			$encryptionCache = new ResourcePackEncryptionCache($this->path, $encryption);
		}

		$logger->info("Loading resource packs...");

		try{
			foreach($resourcePacksConfig["resource_stack"] ?? [] as $pack){
				$encrypt = $encryptionCache !== null && (($encryption["packs"] ?? []) === [] || in_array($pack, $encryption["packs"], true));
				try{
					if($pack === "" || $pack === "." || $pack === ".." || strpbrk($pack, "/\\:\0") !== false){
						throw new ResourcePackException("Pack entry must be a filename inside the resource-packs directory");
					}
					$packPath = Path::join($this->path, $pack);
					$keyPath = Path::join($this->path, $pack . ".key");
					if($encrypt && !file_exists($keyPath)){
						if(is_link($packPath) || ResourcePackEncryptor::isEncrypted($packPath)){
							throw new ResourcePackException("Already encrypted packs require their existing .key file; symbolic links are unsupported");
						}
					}
					$newPack = $this->loadPackFromPath($packPath);

					$index = strtolower($newPack->getPackId());
					if(!Uuid::isValid($index)){
						//TODO: we should use Uuid in ResourcePack interface directly but that would break BC
						//for now we need to validate this here to make sure it doesn't cause crashes later on
						throw new ResourcePackException("Invalid UUID ($index)");
					}
					if(isset($this->uuidList[$index])){
						throw new ResourcePackException("Duplicate resource pack UUID ($index)");
					}

					if(is_link($keyPath)){
						throw new ResourcePackException("Encryption key file must not be a symbolic link");
					}
					if(file_exists($keyPath)){
						try{
							if(!is_file($keyPath)){
								throw new ResourcePackException("Encryption key must be a regular file");
							}
							$key = Filesystem::fileGetContents($keyPath, false, null, 0, 4096);
							if(strlen($key) === 4096){
								throw new ResourcePackException("Encryption key file is too large");
							}
						}catch(\RuntimeException $e){
							throw new ResourcePackException("Could not read encryption key file: " . $e->getMessage(), 0, $e);
						}
						$key = rtrim($key, "\r\n");
						if(strlen($key) !== 32){
							throw new ResourcePackException("Invalid encryption key length, must be exactly 32 bytes");
						}
						$this->encryptionKeys[$index] = $key;
					}elseif($encrypt && $encryptionCache !== null){
						assert($newPack instanceof ZippedResourcePack);
						$newPack = $encryptionCache->prepare($newPack);
						$this->encryptionKeys[$index] = $encryptionCache->getKey();
					}
					$newPack->getSha256();
					$this->uuidList[$index] = $newPack;
					$this->resourcePacks[] = $newPack;
				}catch(ResourcePackException $e){
					if($encrypt){
						throw new ResourcePackException("Could not prepare encrypted resource pack \"$pack\": " . $e->getMessage(), 0, $e);
					}
					$logger->critical("Could not load resource pack \"$pack\": " . $e->getMessage());
				}
			}
		}finally{
			$encryptionCache?->finish();
		}

		$logger->debug("Successfully loaded " . count($this->resourcePacks) . " resource packs");
	}

	private function loadPackFromPath(string $packPath) : ResourcePack{
		if(is_link($packPath)){
			throw new ResourcePackException("Symbolic links are not supported");
		}
		if(!file_exists($packPath)){
			throw new ResourcePackException("File or directory not found");
		}
		if(is_dir($packPath)){
			throw new ResourcePackException("Directory resource packs are unsupported");
		}
		if(!is_file($packPath)){
			throw new ResourcePackException("Resource pack must be a regular file");
		}

		//Detect the type of resource pack.
		$info = new \SplFileInfo($packPath);
		switch(strtolower($info->getExtension())){
			case "zip":
			case "mcpack":
				return new ZippedResourcePack($packPath);
		}

		throw new ResourcePackException("Format not recognized");
	}

	/**
	 * Returns the directory which resource packs are loaded from.
	 */
	public function getPath() : string{
		return $this->path . DIRECTORY_SEPARATOR;
	}

	/**
	 * Returns whether players must accept resource packs in order to join.
	 */
	public function resourcePacksRequired() : bool{
		return $this->serverForceResources;
	}

	/**
	 * Sets whether players must accept resource packs in order to join.
	 */
	public function setResourcePacksRequired(bool $value) : void{
		$this->serverForceResources = $value;
	}

	/**
	 * Returns an array of resource packs in use, sorted in order of priority.
	 * @return ResourcePack[]
	 * @phpstan-return list<ResourcePack>
	 */
	public function getResourceStack() : array{
		return $this->resourcePacks;
	}

	/**
	 * Sets the resource packs to use. Packs earliest in the list will appear at the top of the stack (maximum
	 * priority), and later ones will appear below (lower priority), in the same manner as the Bedrock resource packs
	 * screen in-game.
	 *
	 * @param ResourcePack[] $resourceStack
	 * @phpstan-param list<ResourcePack> $resourceStack
	 */
	public function setResourceStack(array $resourceStack) : void{
		$uuidList = [];
		$resourcePacks = [];
		foreach($resourceStack as $pack){
			$uuid = strtolower($pack->getPackId());
			if(!Uuid::isValid($uuid)){
				//TODO: we should use Uuid in ResourcePack interface directly but that would break BC
				//for now we need to validate this here to make sure it doesn't cause crashes later on
				throw new \InvalidArgumentException("Invalid resource pack UUID ($uuid)");
			}
			if(isset($uuidList[$uuid])){
				throw new \InvalidArgumentException("Cannot load two resource pack with the same UUID ($uuid)");
			}
			$uuidList[$uuid] = $pack;
			$resourcePacks[] = $pack;
		}
		$this->resourcePacks = $resourcePacks;
		$this->uuidList = $uuidList;
	}

	/**
	 * Returns the resource pack matching the specified UUID string, or null if the ID was not recognized.
	 */
	public function getPackById(string $id) : ?ResourcePack{
		return $this->uuidList[strtolower($id)] ?? null;
	}

	/**
	 * Returns an array of pack IDs for packs currently in use.
	 * @return string[]
	 */
	public function getPackIdList() : array{
		return array_keys($this->uuidList);
	}

	/**
	 * Returns the key with which the pack was encrypted, or null if the pack has no key.
	 */
	public function getPackEncryptionKey(string $id) : ?string{
		return $this->encryptionKeys[strtolower($id)] ?? null;
	}

	/**
	 * Sets the encryption key to use for decrypting the specified resource pack. The pack will **NOT** be decrypted by
	 * Quark; the key is simply passed to the client to allow it to decrypt the pack after downloading it.
	 */
	public function setPackEncryptionKey(string $id, ?string $key) : void{
		$id = strtolower($id);
		if($key === null){
			//allow deprovisioning keys for resource packs that have been removed
			unset($this->encryptionKeys[$id]);
		}elseif(isset($this->uuidList[$id])){
			if(strlen($key) !== 32){
				throw new \InvalidArgumentException("Encryption key must be exactly 32 bytes long");
			}
			$this->encryptionKeys[$id] = $key;
		}else{
			throw new \InvalidArgumentException("Unknown pack ID $id");
		}
	}
}
