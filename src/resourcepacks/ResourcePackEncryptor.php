<?php

/*
 * Copyright (c) Quark Team.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\resourcepacks;

use quark\utils\Utils;
use function base64_encode;
use function basename;
use function bin2hex;
use function chmod;
use function chr;
use function count;
use function dirname;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function in_array;
use function is_array;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strtolower;
use function mkdir;
use function openssl_encrypt;
use function ord;
use function preg_match;
use function random_bytes;
use function realpath;
use function rewind;
use function rmdir;
use function sprintf;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strpos;
use function substr;
use function unlink;
use function unpack;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const OPENSSL_RAW_DATA;
use const OPENSSL_ZERO_PADDING;

final class ResourcePackEncryptor{
	private const MAX_ENTRIES = 65535;
	private const MAX_FILE_SIZE = 1073741824;
	private const MAX_TOTAL_SIZE = 2147483648;
	private const MAX_MANIFEST_SIZE = 1048576;
	private const MAX_CONTENTS_SIZE = 16777216;
	private const MAGIC = "\x00\x00\x00\x00\xfc\xb9\xcf\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

	public static function isEncrypted(string $source) : bool{
		$archive = self::open($source);
		try{
			[, $encrypted] = self::inspect($archive);
			return $encrypted;
		}finally{
			$archive->close();
		}
	}

	public static function encrypt(string $source, string $destination, #[\SensitiveParameter] string $key, string $uuid) : void{
		if(strlen($key) !== 32 || preg_match('/^[\x20-\x7e]{32}$/D', $key) !== 1){
			throw new \InvalidArgumentException("Resource pack key must contain exactly 32 printable ASCII bytes");
		}
		if(!self::isUuid($uuid)){
			throw new \InvalidArgumentException("Invalid resource pack UUID");
		}
		if(realpath($source) === realpath($destination)){
			throw new \InvalidArgumentException("Resource pack source and destination must differ");
		}
		$archive = self::open($source);
		$output = new \ZipArchive();
		$outputOpen = false;
		$destinationCreated = false;
		$complete = false;
		$spool = null;
		$files = [];
		try{
			[$entries, $encrypted] = self::inspect($archive);
			if($encrypted){
				throw new ResourcePackException("Resource pack is already encrypted");
			}
			if(count($entries) >= self::MAX_ENTRIES){
				throw new ResourcePackException("Resource pack has no room for its content index");
			}
			$spoolPath = dirname($destination) . DIRECTORY_SEPARATOR . ".pack-spool-" . bin2hex(random_bytes(16));
			if(!mkdir($spoolPath, 0700)){
				throw new ResourcePackException("Unable to create private resource pack spool");
			}
			$spool = $spoolPath;
			$reservation = @fopen($destination, "x+b");
			if($reservation === false){
				throw new ResourcePackException("Resource pack destination must be a new file");
			}
			$destinationCreated = true;
			fclose($reservation);
			if($output->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
				throw new ResourcePackException("Unable to create encrypted resource pack");
			}
			$outputOpen = true;
			$content = [];
			foreach($entries as $index => $entry){
				$name = $entry["path"];
				$fileKey = in_array($name, ["manifest.json", "pack_icon.png", "pack_icon.jpg", "README.txt", "pack_manifest.json"], true) ? null : base64_encode(random_bytes(24));
				$item = ["path" => $name];
				if($fileKey !== null){
					$item["key"] = $fileKey;
				}
				$content[] = $item;
				$file = $spool . DIRECTORY_SEPARATOR . $index;
				$handle = fopen($file, "x+b");
				if($handle === false){
					throw new ResourcePackException("Unable to create resource pack spool file");
				}
				$files[] = $file;
				try{
					if(DIRECTORY_SEPARATOR === "/" && !chmod($file, 0600)){
						throw new ResourcePackException("Unable to protect resource pack spool file");
					}
					self::streamEntry($archive, $index, $entry, $handle, $fileKey);
				}finally{
					fclose($handle);
				}
				if(!$output->addFile($file, $name) || !$output->setCompressionName($name, \ZipArchive::CM_STORE)){
					throw new ResourcePackException("Unable to add encrypted resource pack entry");
				}
			}
			$json = json_encode(["content" => $content], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			if(strlen($json) > self::MAX_CONTENTS_SIZE){
				throw new ResourcePackException("Resource pack content index exceeds 16 MiB");
			}
			$header = str_pad(self::MAGIC . chr(strlen($uuid)) . $uuid, 256, "\x00");
			if(!$output->addFromString("contents.json", $header . self::encryptChunk($json, $key, substr($key, 0, 16))) ||
				!$output->setCompressionName("contents.json", \ZipArchive::CM_STORE)){
				throw new ResourcePackException("Unable to add resource pack content index");
			}
			if(!$output->close()){
				throw new ResourcePackException("Unable to finish encrypted resource pack");
			}
			$outputOpen = false;
			$complete = true;
		}finally{
			try{
				if($outputOpen){
					try{
						$output->unchangeAll();
					}finally{
						@$output->close();
					}
				}
			}finally{
				try{
					$archive->close();
				}finally{
					foreach($files as $file){
						unlink($file);
					}
					if($spool !== null){
						rmdir($spool);
					}
					if($destinationCreated && !$complete && file_exists($destination)){
						unlink($destination);
					}
				}
			}
		}
	}

	private static function open(string $source) : \ZipArchive{
		if(is_link($source) || !is_file($source)){
			throw new ResourcePackException("Resource pack source must be a regular file");
		}
		self::preflight($source);
		$archive = new \ZipArchive();
		if(@$archive->open($source, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true){
			throw new ResourcePackException("Unable to open resource pack archive");
		}
		return $archive;
	}

	private static function preflight(string $source) : void{
		$stream = @fopen($source, "rb");
		if($stream === false){
			throw new ResourcePackException("Unable to read resource pack archive");
		}
		try{
			$stat = fstat($stream);
			if($stat === false || $stat["size"] < 22 || $stat["size"] > self::MAX_TOTAL_SIZE + 67108864){
				throw new ResourcePackException("Resource pack exceeds source archive size limits");
			}
			$size = $stat["size"];
			$tailOffset = max(0, $size - 65557);
			$tail = self::readAt($stream, $tailOffset, $size - $tailOffset);
			for($i = strlen($tail) - 22; $i >= 0; --$i){
				if($tail[$i] !== "P" || substr($tail, $i, 4) !== "PK\x05\x06"){
					continue;
				}
				/** @var array<string, int>|false $end */
				$end = unpack("vdisk/vdirectory_disk/ventries_disk/ventries/Vlength/Voffset/vcomment", substr($tail, $i + 4, 18));
				if($end === false || $i + 22 + $end["comment"] !== strlen($tail) || $end["disk"] !== 0 || $end["directory_disk"] !== 0){
					continue;
				}
				$directoryEnd = $tailOffset + $i;
				$count = $end["entries"];
				$length = $end["length"];
				$offset = $end["offset"];
				$locator = $directoryEnd >= 20 ? self::readAt($stream, $directoryEnd - 20, 20) : "";
				if(str_starts_with($locator, "PK\x06\x07")){
					/** @var array<string, int>|false $location */
					$location = unpack("Vdisk/Voffset_low/Voffset_high/Vdisks", substr($locator, 4));
					if($location === false || $location["disk"] !== 0 || $location["disks"] !== 1 || $location["offset_high"] !== 0){
						continue;
					}
					$recordOffset = $location["offset_low"];
					if($recordOffset > $directoryEnd - 76){
						continue;
					}
					$header = self::readAt($stream, $recordOffset, 12);
					/** @var array<string, int>|false $recordLength */
					$recordLength = unpack("Vlow/Vhigh", substr($header, 4));
					if(!str_starts_with($header, "PK\x06\x06") || $recordLength === false){
						continue;
					}
					if($recordLength["high"] !== 0 || $recordLength["low"] > 67108864){
						throw new ResourcePackException("Resource pack exceeds central directory metadata limits");
					}
					if($recordLength["low"] < 44 || $recordOffset + 12 + $recordLength["low"] !== $directoryEnd - 20){
						continue;
					}
					/** @var array<string, int>|false $record */
					$record = unpack("Vdisk/Vdirectory_disk/Ventries_disk/Ventries_disk_high/Ventries/Ventries_high/Vlength/Vlength_high/Voffset/Voffset_high", self::readAt($stream, $recordOffset + 16, 40));
					if($record !== false && ($record["entries_high"] !== 0 || $record["entries_disk_high"] !== 0 || $record["length_high"] !== 0 ||
						$record["entries"] > self::MAX_ENTRIES || $record["entries_disk"] > self::MAX_ENTRIES || $record["length"] > 67108864)){
						throw new ResourcePackException("Resource pack exceeds central directory metadata limits");
					}
					if($record === false || $record["disk"] !== 0 || $record["directory_disk"] !== 0 ||
						$record["entries_high"] !== 0 || $record["entries_disk_high"] !== 0 || $record["length_high"] !== 0 || $record["offset_high"] !== 0 ||
						$record["entries_disk"] !== $record["entries"]){
						continue;
					}
					$directoryEnd = $recordOffset;
					$count = $record["entries"];
					$length = $record["length"];
					$offset = $record["offset"];
				}elseif($length === 0xffffffff || $offset === 0xffffffff || $end["entries_disk"] !== $count){
					continue;
				}
				if($offset > $directoryEnd - 4 || self::readAt($stream, $offset, 4) !== "PK\x01\x02" || $offset + $length !== $directoryEnd){
					continue;
				}
				if($count > self::MAX_ENTRIES || $length > 67108864){
					throw new ResourcePackException("Resource pack exceeds central directory metadata limits");
				}
				$directory = self::readAt($stream, $offset, $length);
				$actualCount = 0;
				$metadataSize = $length + $size - $directoryEnd;
				$position = 0;
				while($position < $length){
					if(++$actualCount > self::MAX_ENTRIES){
						throw new ResourcePackException("Resource pack exceeds central directory metadata limits");
					}
					if($position + 46 > $length || substr($directory, $position, 4) !== "PK\x01\x02"){
						throw new ResourcePackException("Resource pack has invalid ZIP directory metadata");
					}
					/** @var array<string, int>|false $parts */
					$parts = unpack("vname/vextra/vcomment", substr($directory, $position + 28, 6));
					if($parts === false || $position + 46 + $parts["name"] + $parts["extra"] + $parts["comment"] > $length){
						throw new ResourcePackException("Resource pack has invalid ZIP directory metadata");
					}
					$position += 46 + $parts["name"] + $parts["extra"] + $parts["comment"];
				}
				if($actualCount !== $count){
					throw new ResourcePackException("Resource pack has invalid ZIP directory entry count");
				}
				$position = 0;
				while($position < $length){
					/** @var array<string, int>|false $parts */
					$parts = unpack("vname/vextra/vcomment", substr($directory, $position + 28, 6));
					if($parts === false){
						throw new ResourcePackException("Resource pack has invalid ZIP directory metadata");
					}
					/** @var array<string, int>|false $fields */
					$fields = unpack("Vcompressed/Vuncompressed/x14/Voffset", substr($directory, $position + 20, 26));
					if($fields === false){
						throw new ResourcePackException("Resource pack has invalid ZIP entry metadata");
					}
					$localOffset = $fields["offset"];
					if($localOffset === 0xffffffff){
						$localOffset = null;
						$extraEnd = $position + 46 + $parts["name"] + $parts["extra"];
						for($extra = $position + 46 + $parts["name"]; $extra + 4 <= $extraEnd; $extra += 4 + $field["length"]){
							/** @var array<string, int>|false $field */
							$field = unpack("vtag/vlength", substr($directory, $extra, 4));
							if($field === false || $extra + 4 + $field["length"] > $extraEnd){
								throw new ResourcePackException("Resource pack has invalid ZIP64 extra fields");
							}
							if($field["tag"] === 1){
								$skip = ($fields["uncompressed"] === 0xffffffff ? 8 : 0) + ($fields["compressed"] === 0xffffffff ? 8 : 0);
								if($field["length"] >= $skip + 8){
									/** @var array<string, int>|false $zip64Offset */
									$zip64Offset = unpack("Vlow/Vhigh", substr($directory, $extra + 4 + $skip, 8));
									if($zip64Offset !== false && $zip64Offset["high"] === 0){
										$localOffset = $zip64Offset["low"];
									}
								}
								break;
							}
						}
					}
					if($localOffset === null || $localOffset > $offset - 30){
						throw new ResourcePackException("Resource pack has invalid local ZIP header offset");
					}
					$local = self::readAt($stream, $localOffset, 30);
					/** @var array<string, int>|false $localParts */
					$localParts = unpack("vname/vextra", substr($local, 26, 4));
					if(!str_starts_with($local, "PK\x03\x04") || $localParts === false || $localOffset + 30 + $localParts["name"] + $localParts["extra"] > $offset){
						throw new ResourcePackException("Resource pack has invalid local ZIP header metadata");
					}
					$metadataSize += 30 + $localParts["name"] + $localParts["extra"];
					if($metadataSize > 67108864){
						throw new ResourcePackException("Resource pack exceeds aggregate ZIP metadata limits");
					}
					$position += 46 + $parts["name"] + $parts["extra"] + $parts["comment"];
				}
				return;
			}
			throw new ResourcePackException("Resource pack has invalid ZIP directory metadata");
		}finally{
			fclose($stream);
		}
	}

	/** @param resource $stream */
	private static function readAt($stream, int $offset, int $length) : string{
		if(fseek($stream, $offset) !== 0){
			throw new ResourcePackException("Unable to seek resource pack metadata");
		}
		$result = "";
		$remaining = $length;
		while($remaining > 0){
			$chunk = fread($stream, $remaining);
			if($chunk === false || $chunk === ""){
				throw new ResourcePackException("Truncated resource pack metadata");
			}
			$result .= $chunk;
			$remaining -= strlen($chunk);
		}
		return $result;
	}

	/** @return array{array<int, array{path: string, size: int, crc: int}>, bool} */
	private static function inspect(\ZipArchive $archive) : array{
		if($archive->numFiles < 1 || $archive->numFiles > self::MAX_ENTRIES){
			throw new ResourcePackException("Resource pack must contain between 1 and 65535 entries");
		}
		$entries = [];
		$paths = [];
		$spellings = [];
		$manifests = [];
		$total = 0;
		$pathBytes = 0;
		$prefixBytes = 0;
		for($i = 0; $i < $archive->numFiles; ++$i){
			$stat = $archive->statIndex($i);
			if($stat === false){
				throw new ResourcePackException("Unable to inspect resource pack entry");
			}
			$name = $stat["name"];
			$directory = str_ends_with($name, "/");
			$path = $directory ? substr($name, 0, -1) : $name;
			self::validatePath($path);
			$pathBytes += strlen($path);
			if($pathBytes > 8388608){
				throw new ResourcePackException("Resource pack entry paths exceed 8 MiB");
			}
			$folded = mb_strtolower($path, "UTF-8");
			if(isset($paths[$folded])){
				throw new ResourcePackException("Resource pack contains duplicate or conflicting paths");
			}
			$paths[$folded] = $directory;
			$prefix = "";
			foreach(explode("/", $path, 33) as $component){
				$prefix = $prefix === "" ? $component : $prefix . "/" . $component;
				$fold = mb_strtolower($prefix, "UTF-8");
				if(isset($spellings[$fold]) && $spellings[$fold] !== $prefix){
					throw new ResourcePackException("Resource pack contains conflicting path capitalization");
				}
				if(!isset($spellings[$fold])){
					$prefixBytes += strlen($prefix);
					if($prefixBytes > 16777216){
						throw new ResourcePackException("Resource pack directory paths exceed 16 MiB");
					}
				}
				$spellings[$fold] = $prefix;
			}
			$opsys = 0;
			$attributes = 0;
			if(!$archive->getExternalAttributesIndex($i, $opsys, $attributes)){
				throw new ResourcePackException("Unable to inspect resource pack entry type");
			}
			$type = ($attributes >> 16) & 0170000;
			if(($opsys === \ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== ($directory ? 0040000 : 0100000)) ||
				(($attributes & 0x10) !== 0 && !$directory)){
				throw new ResourcePackException("Resource pack contains a non-regular entry");
			}
			$size = $stat["size"];
			$compressed = $stat["comp_size"];
			if(($stat["encryption_method"] ?? \ZipArchive::EM_NONE) !== \ZipArchive::EM_NONE ||
				!in_array($stat["comp_method"], [\ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE], true)){
				throw new ResourcePackException("Resource pack uses unsupported ZIP encryption or compression");
			}
			$total += $size;
			if($size < 0 || $compressed < 0 || $size > self::MAX_FILE_SIZE || $total > self::MAX_TOTAL_SIZE ||
				$size > max(1, $compressed) * 1000 || ($directory && $size !== 0)){
				throw new ResourcePackException("Resource pack exceeds archive size or expansion limits");
			}
			if(!$directory){
				$entries[$i] = ["path" => $name, "size" => $size, "crc" => $stat["crc"]];
				if(basename($name) === "manifest.json"){
					$manifests[] = $i;
				}
			}
		}
		foreach(Utils::stringifyKeys($paths) as $path => $directory){
			$offset = 0;
			while(($slash = strpos($path, "/", $offset)) !== false){
				$parent = substr($path, 0, $slash);
				if(isset($paths[$parent]) && !$paths[$parent]){
					throw new ResourcePackException("Resource pack file conflicts with a directory");
				}
				$offset = $slash + 1;
			}
		}
		if(count($manifests) === 0){
			throw new ResourcePackException("Resource pack must have one unambiguous manifest.json");
		}
		$manifestIndex = $manifests[0];
		foreach($manifests as $index){
			if(strlen($entries[$index]["path"]) < strlen($entries[$manifestIndex]["path"])){
				$manifestIndex = $index;
			}
		}
		$manifest = $entries[$manifestIndex];
		if($manifest["size"] > self::MAX_MANIFEST_SIZE){
			throw new ResourcePackException("Resource pack manifest exceeds 1 MiB");
		}
		$root = substr($manifest["path"], 0, -strlen("manifest.json"));
		foreach($manifests as $index){
			if(!str_starts_with($entries[$index]["path"], $root)){
				throw new ResourcePackException("Resource pack must have one unambiguous manifest.json");
			}
		}
		foreach(Utils::stringifyKeys($paths) as $path => $directory){
			if($directory && $path === mb_strtolower($root . "contents.json", "UTF-8")){
				throw new ResourcePackException("Resource pack has conflicting contents.json metadata");
			}
			if(!str_starts_with($path . ($directory ? "/" : ""), mb_strtolower($root, "UTF-8")) &&
				!($directory && str_starts_with(mb_strtolower($root, "UTF-8"), $path . "/"))){
				throw new ResourcePackException("Resource pack contains entries outside its manifest root");
			}
		}
		$encrypted = false;
		foreach($entries as $index => $entry){
			$entry["path"] = substr($entry["path"], strlen($root));
			$entries[$index] = $entry;
			if(mb_strtolower($entry["path"], "UTF-8") === "contents.json"){
				if($entry["path"] !== "contents.json" || $entry["size"] > self::MAX_CONTENTS_SIZE + 256){
					throw new ResourcePackException("Resource pack has conflicting contents.json metadata");
				}
				$data = self::readEntry($archive, $index, $entry);
				if(str_starts_with($data, self::MAGIC)){
					if(strlen($data) <= 256 || ord($data[16]) !== 36 || !self::isUuid(substr($data, 17, 36)) ||
						substr($data, 53, 203) !== str_repeat("\x00", 203)){
						throw new ResourcePackException("Resource pack has invalid contents.json metadata");
					}
					$encrypted = true;
				}else{
					self::validatePlainContents($data, $root, $paths);
					unset($entries[$index]);
				}
			}
		}
		self::readEntry($archive, $manifestIndex, $entries[$manifestIndex]);
		return [$entries, $encrypted];
	}

	/** @param array<int|string, bool> $paths */
	private static function validatePlainContents(#[\SensitiveParameter] string $data, string $root, array $paths) : void{
		// Bound decoded nodes before JSON allocates them; quoted paths can contain punctuation.
		$quoted = false;
		$tokens = 0;
		for($i = 0, $length = strlen($data); $i < $length; ++$i){
			$char = $data[$i];
			if($quoted){
				if($char === "\\"){
					++$i;
				}elseif($char === '"'){
					$quoted = false;
				}
			}elseif($char === '"'){
				$quoted = true;
			}elseif(($char === "{" || $char === "[" || $char === ",") && ++$tokens > self::MAX_ENTRIES * 4 + 4){
				throw new ResourcePackException("Resource pack plaintext content index exceeds structural limits");
			}
		}
		try{
			$index = json_decode($data, false, 32, JSON_THROW_ON_ERROR);
		}catch(\JsonException){
			throw new ResourcePackException("Resource pack has invalid contents.json metadata");
		}
		if(!$index instanceof \stdClass || !isset($index->content) || !is_array($index->content) || count($index->content) > self::MAX_ENTRIES){
			throw new ResourcePackException("Resource pack has invalid plaintext content index");
		}
		$seen = [];
		foreach($index->content as $item){
			if(!$item instanceof \stdClass || !isset($item->path) || !is_string($item->path) ||
				(isset($item->key) && $item->key !== "")){
				throw new ResourcePackException("Resource pack plaintext content index has invalid paths or encryption keys");
			}
			self::validatePath($item->path);
			$path = mb_strtolower($root . $item->path, "UTF-8");
			if(isset($seen[$path]) || !isset($paths[$path]) || $paths[$path]){
				throw new ResourcePackException("Resource pack plaintext content index has duplicate or missing files");
			}
			$seen[$path] = true;
		}
	}

	private static function validatePath(string $path) : void{
		if($path === "" || strlen($path) > 1024 || preg_match('//u', $path) !== 1 ||
			preg_match('/[\\\\:\p{Cc}]/u', $path) !== 0){
			throw new ResourcePackException("Resource pack contains an unsafe entry path");
		}
		$parts = explode("/", $path, 33);
		if(count($parts) > 32){
			throw new ResourcePackException("Resource pack entry path exceeds 32 components");
		}
		foreach($parts as $part){
			if($part === "" || $part === "." || $part === ".."){
				throw new ResourcePackException("Resource pack contains an unsafe entry path");
			}
		}
	}

	/** @param array{path: string, size: int, crc: int} $entry */
	private static function readEntry(\ZipArchive $archive, int $index, array $entry) : string{
		$handle = fopen("php://temp/maxmemory:1048576", "w+b");
		if($handle === false){
			throw new ResourcePackException("Unable to buffer resource pack metadata");
		}
		try{
			self::streamEntry($archive, $index, $entry, $handle, null);
			rewind($handle);
			$result = stream_get_contents($handle);
			if($result === false){
				throw new ResourcePackException("Unable to read resource pack metadata");
			}
			return $result;
		}finally{
			fclose($handle);
		}
	}

	/**
	 * @param array{path: string, size: int, crc: int} $entry
	 * @param resource                                 $destination
	 */
	private static function streamEntry(\ZipArchive $archive, int $index, array $entry, $destination, #[\SensitiveParameter] ?string $key) : void{
		$source = @$archive->getStreamIndex($index);
		if($source === false){
			throw new ResourcePackException("Unable to read resource pack entry");
		}
		$hash = hash_init("crc32b");
		$size = 0;
		$iv = $key === null ? "" : substr($key, 0, 16);
		try{
			while(!feof($source)){
				$data = @fread($source, 65536);
				if($data === false || ($data === "" && !feof($source))){
					throw new ResourcePackException("Unable to read resource pack entry");
				}
				$size += strlen($data);
				if($size > $entry["size"]){
					throw new ResourcePackException("Resource pack entry exceeds its declared size");
				}
				hash_update($hash, $data);
				if($key !== null && $data !== ""){
					$data = self::encryptChunk($data, $key, $iv);
					$iv = substr($iv . $data, -16);
				}
				$written = 0;
				while($written < strlen($data)){
					$count = fwrite($destination, substr($data, $written));
					if($count === false || $count === 0){
						throw new ResourcePackException("Unable to write encrypted resource pack entry");
					}
					$written += $count;
				}
			}
			if($size !== $entry["size"] || hash_final($hash) !== sprintf("%08x", $entry["crc"])){
				throw new ResourcePackException("Resource pack entry has invalid size or CRC");
			}
		}finally{
			fclose($source);
		}
	}

	private static function encryptChunk(#[\SensitiveParameter] string $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $iv) : string{
		$result = openssl_encrypt($data, "aes-256-cfb8", $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
		if($result === false){
			throw new ResourcePackException("Unable to encrypt resource pack data");
		}
		return $result;
	}

	private static function isUuid(string $uuid) : bool{
		return preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/D', $uuid) === 1;
	}
}
