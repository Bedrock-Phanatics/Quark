<?php

declare(strict_types=1);

namespace QuarkExample;

use quark\plugin\PluginBase;

final class Main extends PluginBase{
	protected function onEnable() : void{
		$this->getLogger()->info("QuarkExample enabled");
	}
}
