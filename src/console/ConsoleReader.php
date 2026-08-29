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

namespace quark\console;

use quark\utils\Utils;
use function fclose;
use function fgets;
use function fopen;
use function is_resource;
use function stream_select;
use function trim;
use function usleep;

final class ConsoleReader{
	/** @var resource */
	private $stdin;

	public function __construct(){
		$this->initStdin();
	}

	private function initStdin() : void{
		if(is_resource($this->stdin)){
			fclose($this->stdin);
		}

		$this->stdin = Utils::assumeNotFalse(fopen("php://stdin", "r"), "Opening stdin should never fail");
	}

	/**
	 * Reads a line from the console and adds it to the buffer. This method may block the thread.
	 */
	public function readLine() : ?string{
		if(!is_resource($this->stdin)){
			$this->initStdin();
		}

		$r = [$this->stdin];
		$w = $e = null;
		if(($count = stream_select($r, $w, $e, 0, 200000)) === 0){ //nothing changed in 200000 microseconds
			return null;
		}elseif($count === false){ //stream error
			return null;
		}

		if(($raw = fgets($this->stdin)) === false){ //broken pipe or EOF
			usleep(200000); //prevent CPU waste if it's end of pipe
			return null; //loop back round
		}

		$line = trim($raw);

		return $line !== "" ? $line : null;
	}

	public function __destruct(){
		fclose($this->stdin);
	}
}
