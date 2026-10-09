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

namespace quark\pulse;

use quark\pulse\internal\PulseContext;
use function hrtime;

final class PulseZone{
	/** @internal */
	public function __construct(
		private readonly PulseContext $context,
		private readonly int $id,
		private readonly string $name
	){}

	public function getId() : int{ return $this->id; }

	public function getName() : string{ return $this->name; }

	/** Zero means disabled or a capture limit was reached. */
	public function start() : int{
		return $this->context->recording ? $this->context->begin($this->id, hrtime(true)) : 0;
	}

	public function stop(int $scope) : void{
		if($scope !== 0 && $this->context->recording){
			$this->context->end($this->id, $scope, hrtime(true));
		}
	}

	/**
	 * @template T
	 * @param \Closure() : T $work
	 * @return T
	 */
	public function time(\Closure $work) : mixed{
		$scope = $this->start();
		try{
			return $work();
		}finally{
			$this->stop($scope);
		}
	}
}
