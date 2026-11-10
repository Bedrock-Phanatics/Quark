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

use PHPUnit\Framework\TestCase;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\snooze\SleeperHandler;
use quark\GarbageCollectorManager;
use quark\network\mcpe\compression\CompressBatchTask;
use quark\network\mcpe\compression\ZlibCompressor;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use Vow\Deferred;
use function chr;
use function define;
use function dirname;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function microtime;
use function str_repeat;
use function substr;
use function usleep;

class AsyncPoolTest extends TestCase{

	public static int $completions = 0;

	/** @var AsyncPool */
	private $pool;
	/** @var MainLogger */
	private $mainLogger;

	public function setUp() : void{
		@define('quark\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 3) . '/vendor/autoload.php');
		$this->mainLogger = new MainLogger(null, false, "Main", new \DateTimeZone('UTC'));
		$this->pool = new AsyncPool(2, 1024, new ThreadSafeClassLoader(), $this->mainLogger, new SleeperHandler());
	}

	public function tearDown() : void{
		$this->pool->shutdown();
	}

	public function testAutomaticGarbageCollectionIsConfiguredInEachThread() : void{
		$previous = gc_enabled();
		try{
			foreach([false, true] as $enabled){
				new GarbageCollectorManager($this->mainLogger, 0, !$enabled);
				self::assertSame(!$enabled, gc_enabled());

				$pool = new AsyncPool(1, 256, new ThreadSafeClassLoader(), $this->mainLogger, new SleeperHandler(), 0, $enabled);
				try{
					$task = new class extends GarbageCollectionTask{
						public function onRun() : void{
							$before = gc_enabled();
							$cycle = new \stdClass();
							$cycle->self = $cycle;
							$reference = \WeakReference::create($cycle);
							unset($cycle);

							parent::onRun();
							$this->setResult([$before, gc_enabled(), $reference->get() === null]);
						}
					};
					$pool->submitTask($task);
					$deadline = microtime(true) + 5;
					while($pool->collectTasks() && microtime(true) < $deadline){
						usleep(10 * 1000);
					}
					self::assertSame([$enabled, $enabled, true], $task->getResult());
					self::assertSame(!$enabled, gc_enabled());
				}finally{
					$pool->shutdown();
				}
			}
		}finally{
			if($previous){
				gc_enable();
			}else{
				gc_disable();
			}
		}
	}

	public function testTaskLeak() : void{
		$start = microtime(true);
		$this->pool->submitTask(new LeakTestAsyncTask());
		while(!LeakTestAsyncTask::$destroyed && microtime(true) < $start + 30){
			usleep(50 * 1000);
			$this->pool->collectTasks();
		}
		self::assertTrue(LeakTestAsyncTask::$destroyed, "Task was not destroyed after 30 seconds");
	}

	public function testUnfinishedTaskIsNotCompleted() : void{
		self::$completions = 0;
		$this->pool->submitTask(new class extends AsyncTask{
			public function onRun() : void{
				usleep(300 * 1000);
			}

			public function onCompletion() : void{
				++AsyncPoolTest::$completions;
			}
		});

		self::assertTrue($this->pool->collectTasks());
		self::assertSame(0, self::$completions);
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
		self::assertSame(1, self::$completions);
		self::assertFalse($this->pool->collectTasks());
	}

	public function testThreadSafeSetResult() : void{
		/** @phpstan-var Deferred<ThreadSafeArray<array-key, mixed>> $deferred */
		$deferred = new Deferred();
		$this->pool->submitTask(new ThreadSafeResultAsyncTask($deferred));
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
		$result = $deferred->promise->value();
		self::assertCount(1, $result);
		self::assertSame(["foo"], (array) $result);
	}

	public function testCompressBatchTaskResolvesInSubmissionOrder() : void{
		$compressor = new ZlibCompressor(ZlibCompressor::DEFAULT_LEVEL, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE);
		$order = [];
		$deferreds = [];
		for($i = 0; $i < 8; ++$i){
			/** @phpstan-var Deferred<string> $deferred */
			$deferred = new Deferred();
			$deferred->promise->observe(function() use (&$order, $i) : void{ $order[] = $i; });
			$deferreds[] = $deferred;
			$this->pool->submitTaskToWorker(new CompressBatchTask(str_repeat("payload $i ", 1000), $deferred, $compressor), 0);
		}
		while($this->pool->collectTasks()){
			usleep(10 * 1000);
		}
		self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], $order);
		foreach($deferreds as $i => $deferred){
			$batch = $deferred->promise->value();
			self::assertSame(chr(CompressionAlgorithm::ZLIB->value), $batch[0]);
			self::assertSame(str_repeat("payload $i ", 1000), $compressor->decompress(substr($batch, 1)));
		}
	}

	/**
	 * This test ensures that the fix for an exotic AsyncTask::__destruct() reentrancy bug has not regressed.
	 *
	 * Due to an unset() in the function body, other AsyncTask::__destruct() calls could be triggered during
	 * an AsyncTask's destruction. If done in the wrong way, this could lead to a crash.
	 *
	 * @doesNotPerformAssertions This test is checking for a crash condition, not a specific output.
	 */
	public function testTaskDestructorReentrancy() : void{
		$this->pool->submitTask(new class extends AsyncTask{
			public function __construct(){
				$this->storeLocal("task", new class extends AsyncTask{

					public function __construct(){
						$this->storeLocal("dummy", 1);
					}

					public function onRun() : void{
						//dummy
					}
				});
			}

			public function onRun() : void{
				//dummy
			}
		});
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
	}

	public function testNullComplexDataFetch() : void{
		$this->pool->submitTask(new class extends AsyncTask{
			public function __construct(){
				$this->storeLocal("null", null);
			}

			public function onRun() : void{
				//dummy
			}

			public function onCompletion() : void{
				AsyncPoolTest::assertNull($this->fetchLocal("null"));
			}
		});
		while($this->pool->collectTasks()){
			usleep(50 * 1000);
		}
	}
}
