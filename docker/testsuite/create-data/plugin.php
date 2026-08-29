<?php

/**
 * @name data-test
 * @version 1.0.0
 * @api 5.0.0
 * @main DataTest
 * @author QUARK Team
 */

class DataTest extends \quark\plugin\PluginBase {
	public function onEnable() : void {
		file_put_contents($this->getDataFolder() . "/create-data", "successful");
	}
}
