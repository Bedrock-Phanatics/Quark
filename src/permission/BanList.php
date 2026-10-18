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

namespace quark\permission;

use quark\utils\Utils;
use function fclose;
use function fgets;
use function fopen;
use function fwrite;
use function is_resource;
use function is_string;
use function strtolower;
use function trim;

class BanList{
	/**
	 * @var BanEntry[]
	 * @phpstan-var array<string, BanEntry>
	 */
	private array $list = [];

	private bool $enabled = true;

	public function __construct(
		private string|AccessStorage $file,
		private ?string $kind = null
	){}

	public function isEnabled() : bool{
		return $this->enabled;
	}

	public function setEnabled(bool $flag) : void{
		$this->enabled = $flag;
	}

	public function getEntry(string $name) : ?BanEntry{
		$entry = $this->list[strtolower($name)] ?? null;
		if($entry !== null && $entry->hasExpired()){
			unset($this->list[$entry->getName()]);
			return null;
		}
		return $entry;
	}

	/**
	 * @return BanEntry[]
	 */
	public function getEntries() : array{
		$this->removeExpired();

		return $this->list;
	}

	public function isBanned(string $name) : bool{
		return $this->isEnabled() && $this->getEntry($name) !== null;
	}

	public function add(BanEntry $entry) : void{
		if($this->file instanceof AccessStorage){
			$this->file->putBan($this->kind ?? throw new \LogicException("Missing ban kind"), $entry);
		}else{
			$this->list[$entry->getName()] = $entry;
			$this->save();
			return;
		}
		$this->list[$entry->getName()] = $entry;
	}

	public function addBan(string $target, ?string $reason = null, ?\DateTime $expires = null, ?string $source = null) : BanEntry{
		$entry = new BanEntry($target);
		$entry->setSource($source ?? $entry->getSource());
		$entry->setExpires($expires);
		$entry->setReason($reason ?? $entry->getReason());

		$this->add($entry);

		return $entry;
	}

	public function remove(string $name) : void{
		$name = strtolower($name);
		if($this->file instanceof AccessStorage){
			$this->file->removeBan($this->kind ?? throw new \LogicException("Missing ban kind"), $name);
		}
		if(isset($this->list[$name])){
			unset($this->list[$name]);
			if(is_string($this->file)){
				$this->save();
			}
		}
	}

	public function removeExpired() : void{
		foreach(Utils::promoteKeys($this->list) as $name => $entry){
			if($entry->hasExpired()){
				unset($this->list[$name]);
			}
		}
	}

	public function load() : void{
		if($this->file instanceof AccessStorage){
			$this->list = $this->file->loadBans($this->kind ?? throw new \LogicException("Missing ban kind"));
			$this->removeExpired();
			return;
		}
		$this->list = [];
		$fp = @fopen($this->file, "r");
		if(is_resource($fp)){
			while(($line = fgets($fp)) !== false){
				if($line[0] !== "#"){
					try{
						$entry = BanEntry::fromString($line);
						if($entry !== null){
							$this->list[$entry->getName()] = $entry;
						}
					}catch(\RuntimeException $e){
						$logger = \GlobalLogger::get();
						$logger->critical("Failed to parse ban entry from string \"" . trim($line) . "\": " . $e->getMessage());
					}

				}
			}
			fclose($fp);
		}else{
			\GlobalLogger::get()->error("Could not load ban list");
		}
	}

	public function save(bool $writeHeader = true) : void{
		$this->removeExpired();
		if($this->file instanceof AccessStorage){
			$this->file->replaceBans($this->kind ?? throw new \LogicException("Missing ban kind"), $this->list);
			return;
		}
		$fp = @fopen($this->file, "w");
		if(is_resource($fp)){
			if($writeHeader){
				fwrite($fp, "# victim name | ban date | banned by | banned until | reason\n\n");
			}

			foreach($this->list as $entry){
				fwrite($fp, $entry->getString() . "\n");
			}
			fclose($fp);
		}else{
			\GlobalLogger::get()->error("Could not save ban list");
		}
	}
}
