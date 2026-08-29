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

namespace quark\event;

use quark\plugin\Plugin;
use quark\timings\TimingsHandler;
use function in_array;

/**
 * @phpstan-template TEvent of Event
 */
class RegisteredListener{
	/**
	 * @phpstan-param \Closure(TEvent) : void $handler
	 */
	public function __construct(
		private \Closure $handler,
		private int $priority,
		private Plugin $plugin,
		private bool $handleCancelled,
		private TimingsHandler $timings
	){
		if(!in_array($priority, EventPriority::ALL, true)){
			throw new \InvalidArgumentException("Invalid priority number $priority");
		}
	}

	/**
	 * @phpstan-return \Closure(TEvent) : void
	 */
	public function getHandler() : \Closure{
		return $this->handler;
	}

	public function getPlugin() : Plugin{
		return $this->plugin;
	}

	public function getPriority() : int{
		return $this->priority;
	}

	/**
	 * @phpstan-param TEvent $event
	 */
	public function callEvent(Event $event) : void{
		if($event instanceof Cancellable && $event->isCancelled() && !$this->isHandlingCancelled()){
			return;
		}
		$this->timings->startTiming();
		try{
			($this->handler)($event);
		}finally{
			$this->timings->stopTiming();
		}
	}

	public function isHandlingCancelled() : bool{
		return $this->handleCancelled;
	}
}
