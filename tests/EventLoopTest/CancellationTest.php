<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\Exception\CancellationException;
use M6Web\Tornado\EventLoop;

trait CancellationTest
{
    abstract protected function createEventLoop(): EventLoop;

    static private function promiseRaceCancelMessage() {
        return 'Cancelled by promiseRace function';
    }

    public function testCannotCancelFulfilledPromise()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseFulfilled(42);
        $promise->cancel(new CancellationException('Cancellation'));

        $this->assertSame(42, $eventLoop->wait($promise));
    }

    public function testCannotCancelRejectedPromise()
    {
        $eventLoop = $this->createEventLoop();
        $expectedException = new class() extends \Exception {
        };

        $promise = $eventLoop->promiseRejected($expectedException);
        $promise->cancel(new CancellationException('Cancellation'));

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    public function testDeferredCallbackIsCalledWhenPromiseIsCancelled()
    {
        $eventLoop = $this->createEventLoop();
        $receivedException = false;
        $deferred = $eventLoop->deferred(function(CancellationException $exception) use(&$receivedException) {
            $receivedException = $exception;
        });
        $promise = $deferred->getPromise();
        $promise->cancel($expectedException = new CancellationException('Cancellation'));

        $this->assertSame($expectedException, $receivedException);
        $eventLoop->wait($eventLoop->async((function() use($promise) {yield $promise;})()));
        $this->expectException($expectedException);
    }

    public function testCancellationDoesNotInterruptExecution()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->idle();

        $result = $eventLoop->wait($eventLoop->async((function () use ($promise) {
            yield $promise;
            $promise->cancel(new CancellationException('Cancellation'));

            return 'success';
        })()));
        $this->assertSame('success', $result);
    }

    public function testPromiseRaceCancelLastPromises()
    {
        $eventLoop = $this->createEventLoop();
        $exception1 = null;
        $exception3 = null;

        $promises = [
            ($eventLoop->deferred(function(CancellationException $exception) use(&$exception1) {$exception1 = $exception;}))->getPromise(),
            $eventLoop->promiseFulfilled('success'),
            ($eventLoop->deferred(function(CancellationException $exception) use(&$exception3) {$exception3 = $exception;}))->getPromise(),
        ];

        $this->assertSame('success', $eventLoop->wait($eventLoop->promiseRace(...$promises)));
        $this->assertInstanceOf(CancellationException::class, $exception1);
        //$this->assertSame(self::promiseRaceCancelMessage(), $exception1->getMessage());
        $this->assertInstanceOf(CancellationException::class, $exception3);
        //$this->assertSame(self::promiseRaceCancelMessage(), $exception3->getMessage());
    }


    public function testCancellingDelayPromise()
    {
        $eventLoop = $this->createEventLoop();
        $testedPromise = $eventLoop->delay(100/*ms*/);

        $globalPromise = $eventLoop->promiseAll(
            // Check that $testedPromise throw an exception if cancelled
            $eventLoop->async((function() use($testedPromise) {
                try {
                    yield $testedPromise;
                } catch(CancellationException $exception) {
                    return $exception->getMessage();
                }
            })()),
            // Cancel $testedPromise through promiseRace
            $eventLoop->promiseRace(
                $testedPromise,
                $eventLoop->promiseFulfilled('promiseRace success')
            )
        );

        $this->assertSame([self::promiseRaceCancelMessage(), 'promiseRace success'], $eventLoop->wait($globalPromise));
    }

    // ------------- TODO

    public function testIdleThrowACancelledException()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->idle();
        $promise->cancel(new CancellationException('generic cancellation'));

        $this->expectException(CancellationException::class);
        $eventLoop->wait($promise);
    }

    public function testComplexAsync()
    {
        $eventLoop = $this->createEventLoop();
        $promise1 = $eventLoop->idle();
        $promise2 = $eventLoop->async((function () use ($eventLoop) {
            try {
                yield $eventLoop->idle();
            } catch (CancellationException $exception) {
                return;
            }

            throw new \Exception('Nooooooo');
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

        try {
            $asyncPromise->cancel(new CancellationException('generic cancellation'));
        } catch (CancellationException $exception) {
        }

        $exception = null;
        try {
            $eventLoop->wait($asyncPromise);
        } catch (CancellationException $exception) {
        }
        $this->assertNotNull($exception);

        $exception = null;
        try {
            $eventLoop->wait($promise1);
        } catch (CancellationException  $exception) {
        }
        $this->assertNotNull($exception);

        $this->assertNull($eventLoop->wait(
            $eventLoop->idle()
        ));
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

        $this->assertEquals($result, 'request cancelled');
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

        $this->assertEquals($result, 'request cancelled');
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

        $this->assertEquals($result, 'request cancelled');
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

        $this->assertEquals($result, 'request cancelled');
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
            $result = 'other Exception';
        }

        $this->assertEquals($result, 'Timer First');
    }
}
