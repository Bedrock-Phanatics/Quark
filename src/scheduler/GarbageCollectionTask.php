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

namespace quark\scheduler;

use function gc_collect_cycles;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function gc_mem_caches;

class GarbageCollectionTask extends AsyncTask{

	public function onRun() : void{
		$wasEnabled = gc_enabled();
		gc_enable();
		try{
			gc_collect_cycles();
			gc_mem_caches();
		}finally{
			if(!$wasEnabled){
				gc_disable();
			}
		}
	}
}
