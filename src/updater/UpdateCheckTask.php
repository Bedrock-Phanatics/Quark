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

namespace quark\updater;

use quark\scheduler\AsyncTask;
use quark\utils\Internet;
use function is_array;
use function is_string;
use function json_decode;

class UpdateCheckTask extends AsyncTask{
	private const TLS_KEY_UPDATER = "updater";

	private string $error = "Unknown error";

	public function __construct(
		UpdateChecker $updater,
		private string $endpoint,
		private string $channel
	){
		$this->storeLocal(self::TLS_KEY_UPDATER, $updater);
	}

	public function onRun() : void{
		$error = "";
		$response = Internet::getURL($this->endpoint . "?channel=" . $this->channel, 4, [], $error);
		$this->error = $error;

		if($response !== null){
			$response = json_decode($response->getBody(), true);
			if(is_array($response)){
				if(isset($response["error"]) && is_string($response["error"])){
					$this->error = $response["error"];
				}else{
					$mapper = new \JsonMapper();
					$mapper->bExceptionOnMissingData = true;
					$mapper->bStrictObjectTypeChecking = true;
					$mapper->bEnforceMapType = false;
					try{
						/** @var UpdateInfo $responseObj */
						$responseObj = $mapper->map($response, new UpdateInfo());
						$this->setResult($responseObj);
					}catch(\JsonMapper_Exception $e){
						$this->error = "Invalid JSON response data: " . $e->getMessage();
					}
				}
			}else{
				$this->error = "Invalid response data";
			}
		}
	}

	public function onCompletion() : void{
		/** @var UpdateChecker $updater */
		$updater = $this->fetchLocal(self::TLS_KEY_UPDATER);
		if($this->hasResult()){
			/** @var UpdateInfo $response */
			$response = $this->getResult();
			$updater->checkUpdateCallback($response);
		}else{
			$updater->checkUpdateError($this->error);
		}
	}
}
