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

namespace quark\plugin;

use quark\utils\VersionString;
use function array_map;
use function array_push;
use function count;
use function usort;

final class ApiVersion{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param string[] $wantVersionsStr
	 */
	public static function isCompatible(string $myVersionStr, array $wantVersionsStr) : bool{
		$myVersion = new VersionString($myVersionStr);
		foreach($wantVersionsStr as $versionStr){
			$version = new VersionString($versionStr);
			//Format: majorVersion.minorVersion.patch (3.0.0)
			//    or: majorVersion.minorVersion.patch-devBuild (3.0.0-alpha1)
			if($version->getBaseVersion() !== $myVersion->getBaseVersion()){
				if($version->getMajor() !== $myVersion->getMajor()){
					continue;
				}

				if($version->getMinor() > $myVersion->getMinor()){ //If the plugin requires new API features, being backwards compatible
					continue;
				}

				if($version->getMinor() === $myVersion->getMinor() && $version->getPatch() > $myVersion->getPatch()){ //If the plugin requires bug fixes in patches, being backwards compatible
					continue;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @param string[] $versions
	 *
	 * @return string[]
	 */
	public static function checkAmbiguousVersions(array $versions) : array{
		$indexedVersions = [];

		foreach($versions as $str){
			$v = new VersionString($str);
			if($v->getSuffix() !== ""){ //suffix is always unambiguous
				continue;
			}
			if(!isset($indexedVersions[$v->getMajor()])){
				$indexedVersions[$v->getMajor()] = [$v];
			}else{
				$indexedVersions[$v->getMajor()][] = $v;
			}
		}

		$result = [];
		foreach($indexedVersions as $list){
			if(count($list) > 1){
				array_push($result, ...$list);
			}
		}

		usort($result, static function(VersionString $string1, VersionString $string2) : int{ return $string1->compare($string2); });

		return array_map(static function(VersionString $string) : string{ return $string->getBaseVersion(); }, $result);
	}
}
