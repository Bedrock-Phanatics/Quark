<?php

declare(strict_types=1);

namespace quark\scheduler;

use PHPUnit\Framework\TestCase;
use quark\camera\CameraPlayback;
use quark\camera\CameraTimelineTask;
use quark\pulse\internal\PulseZones;
use ReflectionClass;
use ReflectionProperty;
use WeakReference;

final class CameraSchedulerTest extends TestCase{
	public function testCancelledCameraWaitsRemainBoundedBehindALiveRepeater() : void{
		PulseZones::init();
		$scheduler = new TaskScheduler();
		$state = new \stdClass();
		$state->calls = 0;
		$scheduler->scheduleRepeatingTask(new ClosureTask(static function() use ($state) : void{ ++$state->calls; }), 1);
		$playback = (new ReflectionClass(CameraPlayback::class))->newInstanceWithoutConstructor();
		$first = $scheduler->scheduleDelayedTask(new CameraTimelineTask($playback), 72000);
		$reference = WeakReference::create($first);
		$scheduler->cancelTask($first);
		unset($first);
		for($i = 0; $i < 10000; ++$i){
			$task = $scheduler->scheduleDelayedTask(new CameraTimelineTask($playback), 72000);
			$scheduler->cancelTask($task);
		}
		self::assertNull($reference->get());
		$queue = (new ReflectionProperty(TaskScheduler::class, "queue"))->getValue($scheduler);
		$tasks = (new ReflectionProperty(TaskScheduler::class, "tasks"))->getValue($scheduler);
		self::assertInstanceOf(\SplPriorityQueue::class, $queue);
		self::assertInstanceOf(\quark\utils\ObjectSet::class, $tasks);
		self::assertLessThan(64, $queue->count());
		self::assertCount(1, $tasks->toArray());
		$scheduler->mainThreadHeartbeat(1);
		self::assertSame(1, $state->calls);
		$scheduler->mainThreadHeartbeat(2);
		self::assertSame(2, $state->calls);
	}

	public function testCancellationCompactionKeepsLiveTaskDeadlines() : void{
		PulseZones::init();
		$scheduler = new TaskScheduler();
		$state = new \stdClass();
		$state->calls = [];
		$scheduler->scheduleDelayedTask(new ClosureTask(static function() use ($state) : void{ $state->calls[] = 2; }), 2);
		$scheduler->scheduleDelayedTask(new ClosureTask(static function() use ($state) : void{ $state->calls[] = 1; }), 1);
		for($i = 0; $i < 128; ++$i){
			$task = $scheduler->scheduleDelayedTask(new ClosureTask(static function() : void{ self::fail("Cancelled task ran"); }), 72000);
			$scheduler->cancelTask($task);
		}
		$scheduler->mainThreadHeartbeat(1);
		self::assertSame([1], $state->calls);
		$scheduler->mainThreadHeartbeat(2);
		self::assertSame([1, 2], $state->calls);
	}

	public function testCancellationFailureStillReleasesSchedulerOwnership() : void{
		PulseZones::init();
		$scheduler = new TaskScheduler();
		$task = new class extends Task{
			public function onRun() : void{}
			public function onCancel() : void{
				throw new \RuntimeException("Cancellation failed");
			}
		};
		$handler = $scheduler->scheduleDelayedTask($task, 72000);
		try{
			$scheduler->cancelTask($handler);
			self::fail("Cancellation failure was swallowed");
		}catch(\RuntimeException $e){
			self::assertSame("Cancellation failed", $e->getMessage());
		}
		self::assertFalse($scheduler->isQueued($handler));
		self::assertTrue($handler->isCancelled());
		self::assertNull($task->getHandler());
		$scheduler->cancelTask($handler);
	}
}
