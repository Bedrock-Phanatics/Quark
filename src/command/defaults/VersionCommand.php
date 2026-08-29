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

namespace quark\command\defaults;

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use quark\permission\DefaultPermissionNames;
use quark\plugin\Plugin;
use quark\utils\TextFormat;
use quark\utils\Utils;
use quark\VersionInfo;
use function count;
use function implode;
use function sprintf;
use function stripos;
use function strtolower;
use const PHP_VERSION;

class VersionCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"version",
			KnownTranslationFactory::quark_command_version_description(),
			KnownTranslationFactory::quark_command_version_usage(),
			["ver", "about"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_VERSION);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			$versionColor = VersionInfo::IS_DEVELOPMENT_BUILD ? TextFormat::YELLOW : TextFormat::GREEN;

			$jitMode = Utils::getOpcacheJitMode();
			if($jitMode !== null){
				if($jitMode !== 0){
					$jitStatus = KnownTranslationFactory::quark_command_version_phpJitEnabled(sprintf("CRTO: %d", $jitMode));
				}else{
					$jitStatus = KnownTranslationFactory::quark_command_version_phpJitDisabled();
				}
			}else{
				$jitStatus = KnownTranslationFactory::quark_command_version_phpJitNotSupported();
			}

			$this->sendMessages($sender, [
				KnownTranslationFactory::quark_command_version_serverSoftwareName(
					TextFormat::GREEN . VersionInfo::NAME . TextFormat::RESET
				),
				KnownTranslationFactory::quark_command_version_serverSoftwareVersion(
					$versionColor . VersionInfo::VERSION()->getFullVersion() . TextFormat::RESET,
					TextFormat::GREEN . VersionInfo::GIT_HASH() . TextFormat::RESET
				),
				KnownTranslationFactory::quark_command_version_minecraftVersion(
					TextFormat::GREEN . ProtocolInfo::MINECRAFT_VERSION_NETWORK . TextFormat::RESET,
					TextFormat::GREEN . ProtocolInfo::CURRENT_PROTOCOL . TextFormat::RESET
				),
				KnownTranslationFactory::quark_command_version_phpVersion(TextFormat::GREEN . PHP_VERSION . TextFormat::RESET),
				KnownTranslationFactory::quark_command_version_phpJitStatus($jitStatus->format(TextFormat::GREEN, TextFormat::RESET)),
				KnownTranslationFactory::quark_command_version_operatingSystem(TextFormat::GREEN . Utils::getOS() . TextFormat::RESET)
			]);
		}else{
			$pluginName = implode(" ", $args);
			$exactPlugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

			if($exactPlugin instanceof Plugin){
				$this->describeToSender($exactPlugin, $sender);

				return true;
			}

			$found = false;
			$pluginName = strtolower($pluginName);
			foreach($sender->getServer()->getPluginManager()->getPlugins() as $plugin){
				if(stripos($plugin->getName(), $pluginName) !== false){
					$this->describeToSender($plugin, $sender);
					$found = true;
				}
			}

			if(!$found){
				$sender->sendMessage(KnownTranslationFactory::quark_command_version_noSuchPlugin());
			}
		}

		return true;
	}

	private function describeToSender(Plugin $plugin, CommandSender $sender) : void{
		$desc = $plugin->getDescription();
		$messages = [KnownTranslationFactory::quark_command_version_plugin_header(
			TextFormat::DARK_GREEN . $desc->getName() . TextFormat::RESET,
			TextFormat::DARK_GREEN . $desc->getVersion() . TextFormat::RESET
		)];

		if($desc->getDescription() !== ""){
			$messages[] = $desc->getDescription();
		}

		if($desc->getWebsite() !== ""){
			$messages[] = KnownTranslationFactory::quark_command_version_plugin_website($desc->getWebsite());
		}

		if(count($authors = $desc->getAuthors()) > 0){
			if(count($authors) === 1){
				$messages[] = KnownTranslationFactory::quark_command_version_plugin_author(implode(", ", $authors));
			}else{
				$messages[] = KnownTranslationFactory::quark_command_version_plugin_authors(implode(", ", $authors));
			}
		}

		$this->sendMessages($sender, $messages);
	}

	/**
	 * @param list<Translatable|string> $messages
	 */
	private function sendMessages(CommandSender $sender, array $messages) : void{
		$language = $sender->getLanguage();
		$translated = [];
		foreach($messages as $message){
			$translated[] = $message instanceof Translatable ? $language->translate($message) : $message;
		}
		$sender->sendMessage(implode("\n", $translated));
	}
}
