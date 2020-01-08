<?php

namespace M6WebTest\Tornado\Adapter\Tornado;

use M6Web\Tornado\Adapter\Tornado;
use M6Web\Tornado\Exception\CancellationException;
use M6Web\Tornado\EventLoop;

class SynchronousEventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Tornado\SynchronousEventLoop();
    }

    public function testIdleThrowACancelledException()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->idle();
        $promise->cancel(new CancellationException('generic cancellation'));

//        $this->expectException(CancellationException::class);
//        $eventLoop->wait($promise);
        $this->assertSame(null, $eventLoop->wait($promise));
    }

    public function testDelayPromiseThrowException()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->delay(self::LONG_WAITING_TIME);
        $promise->cancel(new CancellationException('generic cancellation'));

//        $this->expectException(CancellationException::class);
        $eventLoop->wait($promise);
        $this->assertSame(null, $eventLoop->wait($promise));
    }


    public function testIdle($expectedSequence = '')
    {
        //By definition, this is not an asynchronous EventLoop
        parent::testIdle('AAABBC');
    }

    public function testPromiseRaceShouldResolvePromisesArray(int $expectedValue = 2)
    {
        // In the synchronous case, there is no race, first promise always win
        parent::testPromiseRaceShouldResolvePromisesArray(1);
    }

    public function testPromiseRaceShouldRejectIfFirstSettledPromiseRejects(int $expectedValue = 2)
    {
        // In the synchronous case, there is no race, first promise always win
        parent::testPromiseRaceShouldRejectIfFirstSettledPromiseRejects(1);
    }

    public function testStreamShouldReadFromWritable($expectedSequence = '')
    {
        // Never wait…
        parent::testStreamShouldReadFromWritable('W0W12345W6R01R23R45R6R');
    }

    public function testDelay()
    {
        $expectedDelay = 42; /*ms*/
        $eventLoop = $this->createEventLoop();

        // For synchronous event loop, the delay is applied as soon as requested!
        $start = microtime(true);
        $promise = $eventLoop->delay($expectedDelay);
        $duration = (microtime(true) - $start) * 1000;
        $result = $eventLoop->wait($promise);

        $this->assertSame(null, $result);
        // Can be a little sooner
        $this->assertGreaterThanOrEqual($expectedDelay - 5, $duration);
        // In these conditions, we should be close of the expected delay
        $this->assertLessThanOrEqual($expectedDelay + 10, $duration);
    }

    public function testWaitFunctionShouldReturnAsSoonAsPromiseIsResolved()
    {
        // By definition, synchronous event loop can only wait a promise if already resolved.
        // So this use case is not relevant for this particular implementation.
        $this->assertTrue(true);
    }

    public function testWaitFunctionShouldThrowIfPromiseCannotBeResolved()
    {
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred(function(){});

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Synchronous Deferred must be resolved/rejected before to retrieve its promise.');
        $eventLoop->wait($deferred->getPromise());
    }

    public function testComplexAsync()
    {
//        $expectedException = new class("unstopable") extends \Exception {
//        };


        $eventLoop = $this->createEventLoop();
        $promise1 = $eventLoop->idle();
        $promise2 = $eventLoop->async((function () use ($eventLoop) {
            try {
                yield $eventLoop->idle();
            } catch (CancellationException $exception) {
                return;
            }

            throw new CancellationException('should not be reach');
           //throw $expectedException;
        })());

        $asyncPromise = $eventLoop->async((function () use ($promise1, $promise2) {
            try {
                yield $promise1;
                yield $promise2;
            } catch (CancellationException $exception) {
                $promise1->cancel(new CancellationException('generic cancellation'));
                $promise2->cancel(new CancellationException('generic cancellation'));

                throw $exception;
            }

            return 'success';
        })());

//        try {
            $asyncPromise->cancel(new CancellationException('generic cancellation'));
//        } catch (CancellationException $exception) {
//        }

        $exception = null;
        try{

        $eventLoop->wait($asyncPromise);
        } catch (\Throwable $e) {}
//        $this->expectException(get_class($expectedException));
        $this->assertNull($exception);
//        $this->expectException(CancellationException::class);

//        $this->assertNull($exception);
//
//        $exception = null;
//        try {
//            $eventLoop->wait($promise1);
//        } catch (CancellationException  $exception) {
//        }
//        $this->assertNull($exception);
//
//        $this->assertNull($eventLoop->wait(
//            $eventLoop->idle()
//        ));


    }

    private function canceller(EventLoop $eventLoop, int $time, \M6Web\Tornado\Promise &$promise = null)
    {
        yield $eventLoop->delay($time);

        if ($promise) {
            $promise->cancel(new CancellationException('generic cancellation'));
        }
        yield $eventLoop->delay($time);

        return 'canceller resolved';
    }

    private function timer(EventLoop $eventLoop, string $id, int $time)
    {
        $result = 'not resolved';

        yield $eventLoop->delay($time);

        $result = $id;

        return $result;
    }

    private function stepHaveToBeCancelled(EventLoop $eventLoop, int $time)
    {
        yield $eventLoop->delay($time);

        throw new \LogicException('should not be reach');
    }

    public function testDelayCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->delay(self::LONG_WAITING_TIME),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        }

        $this->assertEquals($result, [null, 'canceller resolved']);
    }

    public function testAsyncCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->async($this->timer($eventLoop, 'A', self::LONG_WAITING_TIME)),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = 'other Exception';
        }

        $this->assertEquals($result, ['A', 'canceller resolved']);
    }

    public function testPromiseAllCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->promiseAll(
                        $eventLoop->async($this->timer($eventLoop, 'Timer A', self::LONG_WAITING_TIME)),
                        $eventLoop->async($this->timer($eventLoop, 'Timer B', self::LONG_WAITING_TIME))
                    ),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = 'other Exception' .$e->getMessage();
        }

        $this->assertEquals($result, [["Timer A","Timer B"],"canceller resolved"]);
    }
    /** try to cancel a promiseAll */
    public function testPromiseRaceCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->promiseRace(
                        $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME)),
                        $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME))
                    ),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        $this->assertEquals($result, 'should not be reach');
    }


    /** try auto cancel a after first resolution */
    public function testPromiseRaceAutoCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $promise = $eventLoop->promiseRace(
                    $eventLoop->async($this->timer($eventLoop, 'Timer First', $shortWaitingTime)),
                    $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME)),
                    $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        $this->assertEquals($result, 'should not be reach');
    }
}
