<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\block\RuntimeBlockStateRegistry;
use quark\scheduler\AsyncTask;
use function igbinary_unserialize;

/**
 * Gives a worker the light properties of custom states, so off-thread light population matches the main thread.
 * @internal
 */
final class CustomBlockLightTask extends AsyncTask{
	private string $lightData;

	public function __construct(string $lightData){
		$this->lightData = $lightData;
	}

	public function onRun() : void{
		/** @var array{array<int, int>, array<int, int>, array<int, true>} $data */
		$data = igbinary_unserialize($this->lightData);
		RuntimeBlockStateRegistry::getInstance()->registerLightData(...$data);
	}
}
