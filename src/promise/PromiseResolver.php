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

namespace quark\promise;

/**
 * @phpstan-template TValue
 */
final class PromiseResolver{
	/** @phpstan-var PromiseSharedData<TValue> */
	private PromiseSharedData $shared;
	/** @phpstan-var Promise<TValue> */
	private Promise $promise;

	public function __construct(){
		$this->shared = new PromiseSharedData();
		$this->promise = new Promise($this->shared);
	}

	/**
	 * @phpstan-param TValue $value
	 */
	public function resolve(mixed $value) : void{
		if($this->shared->state !== null){
			throw new \LogicException("Promise has already been resolved/rejected");
		}
		$this->shared->state = true;
		$this->shared->result = $value;
		foreach($this->shared->onSuccess as $c){
			$c($value);
		}
		$this->shared->onSuccess = [];
		$this->shared->onFailure = [];
	}

	public function reject() : void{
		if($this->shared->state !== null){
			throw new \LogicException("Promise has already been resolved/rejected");
		}
		$this->shared->state = false;
		foreach($this->shared->onFailure as $c){
			$c();
		}
		$this->shared->onSuccess = [];
		$this->shared->onFailure = [];
	}

	/**
	 * @phpstan-return Promise<TValue>
	 */
	public function getPromise() : Promise{
		return $this->promise;
	}
}
