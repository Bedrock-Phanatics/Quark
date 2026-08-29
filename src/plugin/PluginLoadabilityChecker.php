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

use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use quark\utils\Utils;
use quark\utils\VersionString;
use function array_intersect;
use function count;
use function extension_loaded;
use function implode;
use function in_array;
use function phpversion;
use function str_starts_with;
use function stripos;
use function strlen;
use function substr;
use function version_compare;

final class PluginLoadabilityChecker{

	public function __construct(
		private string $apiVersion
	){}

	public function check(PluginDescription $description) : Translatable|null{
		$name = $description->getName();
		if(stripos($name, "quark") !== false || stripos($name, "minecraft") !== false || stripos($name, "mojang") !== false){
			return KnownTranslationFactory::quark_plugin_restrictedName();
		}

		foreach($description->getCompatibleApis() as $api){
			if(!VersionString::isValidBaseVersion($api)){
				return KnownTranslationFactory::quark_plugin_invalidAPI($api);
			}
		}

		if(!ApiVersion::isCompatible($this->apiVersion, $description->getCompatibleApis())){
			return KnownTranslationFactory::quark_plugin_incompatibleAPI(implode(", ", $description->getCompatibleApis()));
		}

		$ambiguousVersions = ApiVersion::checkAmbiguousVersions($description->getCompatibleApis());
		if(count($ambiguousVersions) > 0){
			return KnownTranslationFactory::quark_plugin_ambiguousMinAPI(implode(", ", $ambiguousVersions));
		}

		if(count($description->getCompatibleOperatingSystems()) > 0 && !in_array(Utils::getOS(), $description->getCompatibleOperatingSystems(), true)) {
			return KnownTranslationFactory::quark_plugin_incompatibleOS(implode(", ", $description->getCompatibleOperatingSystems()));
		}

		if(count($pluginMcpeProtocols = $description->getCompatibleMcpeProtocols()) > 0){
			$serverMcpeProtocols = [ProtocolInfo::CURRENT_PROTOCOL];
			if(count(array_intersect($pluginMcpeProtocols, $serverMcpeProtocols)) === 0){
				return KnownTranslationFactory::quark_plugin_incompatibleProtocol(implode(", ", $pluginMcpeProtocols));
			}
		}

		foreach(Utils::stringifyKeys($description->getRequiredExtensions()) as $extensionName => $versionConstrs){
			if(!extension_loaded($extensionName)){
				return KnownTranslationFactory::quark_plugin_extensionNotLoaded($extensionName);
			}
			$gotVersion = phpversion($extensionName);
			if($gotVersion === false){
				//extensions may set NULL as the extension version, in which case phpversion() may return false
				$gotVersion = "**UNKNOWN**";
			}

			foreach($versionConstrs as $k => $constr){ // versionConstrs_loop
				if($constr === "*"){
					continue;
				}
				if($constr === ""){
					return KnownTranslationFactory::quark_plugin_emptyExtensionVersionConstraint(extensionName: $extensionName, constraintIndex: "$k");
				}
				foreach(["<=", "le", "<>", "!=", "ne", "<", "lt", "==", "=", "eq", ">=", "ge", ">", "gt"] as $comparator){
					// warning: the > character should be quoted in YAML
					if(str_starts_with($constr, $comparator)){
						$version = substr($constr, strlen($comparator));
						if(!version_compare($gotVersion, $version, $comparator)){
							return KnownTranslationFactory::quark_plugin_incompatibleExtensionVersion(extensionName: $extensionName, extensionVersion: $gotVersion, pluginRequirement: $constr);
						}
						continue 2; // versionConstrs_loop
					}
				}
				return KnownTranslationFactory::quark_plugin_invalidExtensionVersionConstraint(extensionName: $extensionName, versionConstraint: $constr);
			}
		}

		return null;
	}
}
