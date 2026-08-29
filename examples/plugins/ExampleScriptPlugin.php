<?php

namespace quark\ExampleScriptPlugin;

use quark\event\Listener;
use quark\event\world\WorldLoadEvent;
use quark\plugin\PluginBase;

/**
 * Script plugins are self-contained .php files. They are intended for quick testing only.
 * They don't support all the features of a normal plugin.
 * See the documentation at https://github.com/Bedrock-Phanatics/Quark/tree/stable/docs
 *
 * Required fields
 * @main quark\ExampleScriptPlugin\Main
 * @api 5.37.0
 *
 * Optional fields
 * Version and name are optional in script plugins for convenience, and will be filled with 1.0.0 and
 * ScriptPlugin_{file name without extension} respectively.
 * @version 1.0.0
 * @name ExampleScriptPlugin
 * @load STARTUP
 */
class Main extends PluginBase{
	public function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents(new ExampleListener($this->getLogger()), $this);
	}
}

class ExampleListener implements Listener{

	public function __construct(
		private \Logger $logger
	){}

	public function onWorldLoad(WorldLoadEvent $event) : void{
		$this->logger->info("Script plugin detected world " . $event->getWorld()->getDisplayName() . " being loaded!");
	}
}

