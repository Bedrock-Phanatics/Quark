<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use quark\scheduler\Task;
use WeakReference;

/**
 * @internal
 */
final class CameraTimelineTask extends Task{
	/** @var WeakReference<CameraPlayback> */
	private WeakReference $playback;

	public function __construct(CameraPlayback $playback){
		$this->playback = WeakReference::create($playback);
	}

	public function getName() : string{
		return "Camera timeline";
	}

	public function onRun() : void{
		$this->playback->get()?->resume($this);
	}

	public function onCancel() : void{
		$this->playback->get()?->onTaskCancelled($this);
	}
}
