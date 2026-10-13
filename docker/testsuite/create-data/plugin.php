<?php

/**
 * @name data-test
 * @version 1.0.0
 * @api 6.0.0
 * @main DataTest
 * @author Quark Team
 */

class DataTest extends \quark\plugin\PluginBase {
	public function onEnable() : void {
		if(file_put_contents($this->getDataFolder() . "/create-data", "successful") !== strlen("successful")){
			throw new \RuntimeException("Failed to create plugin data");
		}
		$this->getLogger()->notice("Test passed: create-data was written");
	}
}
