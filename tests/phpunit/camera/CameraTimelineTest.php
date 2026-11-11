<?php

declare(strict_types=1);

namespace quark\camera;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use quark\plugin\Plugin;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskScheduler;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use WeakReference;
use const INF;
use const NAN;

final class CameraTimelineTest extends TestCase{
	public function testDefinitionIsReusableAndImmutable() : void{
		$initial = new CameraTimeline();
		$wait = $initial->wait(0.05);
		$loop = $wait->loop(3)->resetAfter(false);
		self::assertSame([], $initial->getSteps());
		self::assertSame([0.05], $wait->getSteps());
		self::assertSame(1, $wait->getIterations());
		self::assertTrue($wait->shouldResetAfter());
		self::assertSame(3, $loop->getIterations());
		self::assertFalse($loop->shouldResetAfter());
	}

	/** @return iterable<string, array{float}> */
	public static function invalidWaits() : iterable{
		yield "negative" => [-0.01];
		yield "too long" => [3600.01];
		yield "NaN" => [NAN];
		yield "infinity" => [INF];
	}

	#[DataProvider("invalidWaits")]
	public function testInvalidWaitsFail(float $seconds) : void{
		$this->expectException(\InvalidArgumentException::class);
		(new CameraTimeline())->wait($seconds);
	}

	public function testEmptyTimelineFinishesOnceWithoutScheduling() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$camera->expects(self::once())->method("finishPlayback")->with(self::isInstanceOf(CameraPlayback::class), true);
		$playback = new CameraPlayback($camera, (new CameraTimeline())->loop(10000), $owner);
		$playback->start();
		self::assertFalse($playback->isRunning());
		$playback->cancel();
	}

	public function testDelayUsesCeilingTicksAndClientInterpolationNeedsNoPolling() : void{
		$scheduler = new TaskScheduler();
		$owner = $this->owner($scheduler);
		$camera = $this->camera();
		$state = new stdClass();
		$state->calls = 0;
		$timeline = (new CameraTimeline())->wait(0.051)->callback(static function(CameraPlayback $playback) use ($state) : void{ ++$state->calls; });
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		$scheduler->mainThreadHeartbeat(1);
		self::assertSame(0, $state->calls);
		$scheduler->mainThreadHeartbeat(2);
		self::assertSame(1, $state->calls);
		self::assertFalse($playback->isRunning());
		$scheduler->mainThreadHeartbeat(100);
		self::assertSame(1, $state->calls);
	}

	public function testSignalsOnlyResumeTheCurrentMatchingWait() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$calls = 0;
		$timeline = (new CameraTimeline())->awaitSignal("intro")
			->callback(static function(CameraPlayback $playback) use (&$calls) : void{ ++$calls; })
			->awaitSignal("outro");
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		self::assertFalse($playback->signal("outro"));
		self::assertTrue($playback->signal("intro"));
		self::assertSame(1, $calls);
		self::assertFalse($playback->signal("intro"));
		self::assertTrue($playback->signal("outro"));
		self::assertFalse($playback->isRunning());
		self::assertFalse($playback->signal("outro"));
	}

	public function testCancelPreventsScheduledCallbacksAndReleasesCapturedReferences() : void{
		$scheduler = new TaskScheduler();
		$owner = $this->owner($scheduler);
		$camera = $this->camera();
		$captured = new stdClass();
		$reference = WeakReference::create($captured);
		$timeline = (new CameraTimeline())->wait(3600)
			->callback(static function(CameraPlayback $playback) use ($captured) : void{
				$captured->invoked = true;
				throw new RuntimeException("Cancelled callback ran");
			});
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		unset($timeline, $captured);
		$playback->cancel();
		self::assertNull($reference->get());
		$playbackReference = WeakReference::create($playback);
		unset($playback);
		self::assertNull($playbackReference->get());
		$scheduler->mainThreadHeartbeat(72000);
	}

	public function testPlaybackDoesNotRetainCamera() : void{
		$camera = (new ReflectionClass(CameraTimelineRetentionController::class))->newInstanceWithoutConstructor();
		$owner = $this->owner(new TaskScheduler());
		$cameraReference = WeakReference::create($camera);
		$timeline = (new CameraTimeline())->awaitSignal("continue")
			->callback(static function(CameraPlayback $playback) : void{ throw new RuntimeException("Released camera resumed"); });
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		unset($camera);
		self::assertNull($cameraReference->get());
		self::assertTrue($playback->signal("continue"));
		self::assertFalse($playback->isRunning());
	}

	public function testCompletionCanKeepTheLastCameraInstruction() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$camera->expects(self::once())->method("finishPlayback")->with(self::isInstanceOf(CameraPlayback::class), false);
		$playback = new CameraPlayback($camera, (new CameraTimeline())->resetAfter(false), $owner);
		$playback->start();
		self::assertFalse($playback->isRunning());
	}

	public function testSchedulerShutdownCancelsAnActiveDelay() : void{
		$scheduler = new TaskScheduler();
		$owner = $this->owner($scheduler);
		$camera = $this->camera();
		$camera->expects(self::once())->method("finishPlayback")->with(self::isInstanceOf(CameraPlayback::class), false);
		$camera->expects(self::once())->method("resetForContext");
		$playback = new CameraPlayback($camera, (new CameraTimeline())->wait(5), $owner);
		$playback->start();
		$scheduler->shutdown();
		self::assertFalse($playback->isRunning());
	}

	public function testLossOfReadinessCancelsWithoutFurtherCallbacks() : void{
		$scheduler = new TaskScheduler();
		$owner = $this->owner($scheduler);
		$ready = true;
		$camera = $this->getMockBuilder(CameraController::class)->disableOriginalConstructor()->onlyMethods(["isReady", "finishPlayback"])->getMock();
		$camera->method("isReady")->willReturnCallback(static function() use (&$ready) : bool{ return $ready; });
		$camera->expects(self::once())->method("finishPlayback")->with(self::isInstanceOf(CameraPlayback::class), false);
		$timeline = (new CameraTimeline())->wait(1)->callback(static function(CameraPlayback $playback) : void{
			throw new RuntimeException("Disconnected camera resumed");
		});
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		$ready = false;
		$scheduler->mainThreadHeartbeat(20);
		self::assertFalse($playback->isRunning());
	}

	public function testSameTickLoopsYieldWithoutStarvingOtherTasks() : void{
		$scheduler = new TaskScheduler();
		$owner = $this->owner($scheduler);
		$camera = $this->camera();
		$state = new stdClass();
		$state->calls = 0;
		$timeline = (new CameraTimeline())->callback(static function(CameraPlayback $playback) use ($state) : void{ ++$state->calls; })->loop(130);
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		self::assertSame(64, $state->calls);
		$otherTaskRan = false;
		$scheduler->scheduleDelayedTask(new ClosureTask(static function() use (&$otherTaskRan) : void{ $otherTaskRan = true; }), 1);
		$scheduler->mainThreadHeartbeat(1);
		self::assertTrue($otherTaskRan);
		self::assertSame(128, $state->calls);
		self::assertTrue($playback->isRunning());
		$scheduler->mainThreadHeartbeat(2);
		self::assertSame(130, $state->calls);
		self::assertFalse($playback->isRunning());
	}

	public function testCancellationInsideCallbackStopsSameTickSteps() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$timeline = (new CameraTimeline())->callback(static function(CameraPlayback $playback) : void{ $playback->cancel(); })
			->callback(static function(CameraPlayback $playback) : void{ throw new RuntimeException("Cancelled playback continued"); });
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		self::assertFalse($playback->isRunning());
	}

	public function testReentrantReplacementRejectsInstructionsFromTheOldCallback() : void{
		$camera = $this->getMockBuilder(CameraController::class)->disableOriginalConstructor()->onlyMethods(["isReady"])->getMock();
		$camera->method("isReady")->willReturn(true);
		$owner = $this->owner(new TaskScheduler());
		$state = new class{
			public ?CameraPlayback $replacement = null;
			public bool $rejected = false;
		};
		$timeline = (new CameraTimeline())->callback(static function(CameraPlayback $old) use ($camera, $owner, $state) : void{
			$state->replacement = $camera->play((new CameraTimeline())->awaitSignal("finish"), $owner);
			try{
				$old->send(CameraInstruction::clear());
			}catch(LogicException){
				$state->rejected = true;
			}
		})->callback(static function(CameraPlayback $old) : void{
			throw new RuntimeException("Replaced timeline continued");
		});
		$old = $camera->play($timeline, $owner);
		self::assertFalse($old->isRunning());
		self::assertTrue($state->rejected);
		self::assertNotNull($state->replacement);
		self::assertTrue($state->replacement->isRunning());
		$state->replacement->cancel(false);
	}

	public function testCancelledScheduledTaskCannotResumePlayback() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$camera->expects(self::once())->method("finishPlayback");
		$timeline = (new CameraTimeline())->wait(10)->callback(static function(CameraPlayback $playback) : void{
			throw new RuntimeException("Stale scheduled callback resumed");
		});
		$playback = new CameraPlayback($camera, $timeline, $owner);
		$playback->start();
		$task = (new ReflectionProperty(CameraPlayback::class, "task"))->getValue($playback);
		self::assertInstanceOf(CameraTimelineTask::class, $task);
		$playback->cancel();
		$task->onRun();
		$task->onCancel();
		self::assertFalse($playback->isRunning());
	}

	public function testCallbackFailureCancelsAndPropagates() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$camera->expects(self::once())->method("finishPlayback");
		$playback = new CameraPlayback($camera, (new CameraTimeline())->callback(static function(CameraPlayback $playback) : void{
			throw new RuntimeException("Callback failed");
		}), $owner);
		try{
			$playback->start();
			self::fail("Callback exception was swallowed");
		}catch(RuntimeException $e){
			self::assertSame("Callback failed", $e->getMessage());
		}
		self::assertFalse($playback->isRunning());
	}

	public function testAPlaybackCannotRestartAfterCompletion() : void{
		$camera = $this->camera();
		$owner = $this->owner(new TaskScheduler());
		$playback = new CameraPlayback($camera, new CameraTimeline(), $owner);
		$playback->start();
		$this->expectException(LogicException::class);
		$playback->start();
	}

	public function testDisabledOwnersCannotStartTimelines() : void{
		$camera = $this->camera();
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(false);
		$this->expectException(LogicException::class);
		new CameraPlayback($camera, new CameraTimeline(), $owner);
	}

	private function owner(TaskScheduler $scheduler) : Plugin{
		$owner = $this->createMock(Plugin::class);
		$owner->method("isEnabled")->willReturn(true);
		$owner->method("getScheduler")->willReturn($scheduler);
		return $owner;
	}

	private function camera() : CameraController&MockObject{
		$camera = $this->getMockBuilder(CameraController::class)->disableOriginalConstructor()
			->onlyMethods(["isReady", "sendForPlayback", "finishPlayback", "resetForContext"])->getMock();
		$camera->method("isReady")->willReturn(true);
		return $camera;
	}
}

final class CameraTimelineRetentionController extends CameraController{
	public function isReady() : bool{ return true; }
	public function finishPlayback(CameraPlayback $playback, bool $reset) : void{}
}
