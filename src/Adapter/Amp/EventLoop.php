<?php

namespace M6Web\Tornado\Adapter\Amp;

use M6Web\Tornado\Adapter\Common;
use M6Web\Tornado\Exception\CancellationException;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        try {
            $result = \Amp\Promise\wait(
                Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getAmpPromise()
            );
            $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

            return $result;
        } catch (\Error $error) {
            // Modify exceptions sent by Amp itself
            if ($error->getCode() !== 0) {
                throw $error;
            }
            switch ($error->getMessage()) {
                case 'Loop stopped without resolving the promise':
                    throw new \Error('Impossible to resolve the promise, no more task to execute.', 0, $error);
                case 'Loop exceptionally stopped without resolving the promise':
                    throw $error->getPrevious() ?? $error;
                default:
                    throw $error;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        /** @var Promise $currentPromise */
        $currentPromise = null;

        $wrapper = function (\Generator $generator, \Amp\Deferred $deferred) use (&$currentPromise): \Generator {
            try {
                while ($generator->valid()) {
                    $blockingPromise = $generator->current();
                    if (!$blockingPromise instanceof Promise) {
                        throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
                    }
                    $currentPromise = $blockingPromise;
                    $blockingPromise = Internal\PromiseWrapper::toHandledPromise(
                        $blockingPromise,
                        $this->unhandledFailingPromises
                    )->getAmpPromise();

                    // Forwards promise value/exception to underlying generator
                    $blockingPromiseValue = null;
                    $blockingPromiseException = null;
                    try {
                        $blockingPromiseValue = yield $blockingPromise;
                    } catch (\Throwable $throwable) {
                        $blockingPromiseException = $throwable;
                    }
                    if ($blockingPromiseException) {
                        $generator->throw($blockingPromiseException);
                    } else {
                        $generator->send($blockingPromiseValue);
                    }
                }
            } catch (\Throwable $throwable) {
                $deferred->fail($throwable);

                return;
            }

            $deferred->resolve($generator->getReturn());
        };

        $deferred = new \Amp\Deferred();
        \Amp\Promise\rethrow(new \Amp\Coroutine($wrapper($generator, $deferred)));

        $cancellable = function (CancellationException $exception) use (&$currentPromise) {
            $currentPromise->cancel($exception);
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellable);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $cancellable = function (CancellationException $exception) use (&$promises) {
            foreach ($promises as $promise) {
                $promise->cancel($exception);
            }
        };

        return Internal\PromiseWrapper::createUnhandled(
            \Amp\Promise\all(
                array_map(
                    function (Promise $promise) {
                        return Internal\PromiseWrapper::toHandledPromise(
                            $promise,
                            $this->unhandledFailingPromises
                        )->getAmpPromise();
                    },
                    $promises
                )
            ),
            $this->unhandledFailingPromises,
            $cancellable
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
        $promises = [];
        foreach ($traversable as $key => $value) {
            $promises[] = $this->async($function($value, $key));
        }

        return $this->promiseAll(...$promises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        if (empty($promises)) {
            return $this->promiseFulfilled(null);
        }

        $deferred = new \Amp\Deferred();
        $isFirstPromise = true;

        $wrapPromise = function (\Amp\Promise $promise) use ($deferred, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->fail($throwable);
                }
            }
        };

        $ampPromises = array_map(
            function (Promise $promise) {
                $tempPromise = Internal\PromiseWrapper::toHandledPromise(
                    $promise,
                    $this->unhandledFailingPromises
                );

                return $tempPromise->getAmpPromise();
            },
            $promises
        );

        foreach ($ampPromises as $promise) {
            \Amp\Promise\rethrow(new \Amp\Coroutine($wrapPromise($promise)));
        }

        $cancelCallback = function (CancellationException $exception) use (&$deferred, &$promises) {
            $deferred->fail($exception);
            foreach ($promises as $promise) {
                $promise->cancel($exception);
            }
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancelCallback);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createHandled(
            new \Amp\Success($value),
            function () {
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        // Manually created promises are considered as handled.
        return Internal\PromiseWrapper::createHandled(
            new \Amp\Failure($throwable),
            function () {
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = new \Amp\Deferred();

        $deferedId = \Amp\Loop::defer(function () use ($deferred) {
            $deferred->resolve();
        });

        $cancelCallback = function () use ($deferedId, $deferred) {
            \Amp\Loop::cancel($deferedId);
            $deferred->fail(new CancellationException('Delay cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancelCallback);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = new \Amp\Deferred();

        $delayId = \Amp\Loop::delay($milliseconds, function () use ($deferred) {
            $deferred->resolve();
        });

        $cancelCallback = function () use ($delayId, $deferred) {
            \Amp\Loop::cancel($delayId);
            $deferred->fail(new CancellationException('Delay cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancelCallback);
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(callable $cancelCallback): Deferred
    {
        $ampDeferred = new \Amp\Deferred();
        $deferred = null;
        // Manually created promises are considered as handled.
        $promise = Internal\PromiseWrapper::createHandled(
            $ampDeferred->promise(),
            function(CancellationException $exception) use(&$deferred, $cancelCallback) {
                $deferred->reject($exception);
                $cancelCallback($exception);
        });

        return $deferred = new Internal\Deferred($ampDeferred,$promise);
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = new \Amp\Deferred();

        $watcherId = \Amp\Loop::onReadable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Amp\Loop::cancel($watcherId);
                $deferred->resolve($stream);
            }
        );

        $cancelCallback = function () use ($watcherId, $deferred) {
            \Amp\Loop::cancel($watcherId);
            $deferred->fail(new CancellationException('writable cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancelCallback);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $deferred = new \Amp\Deferred();

        $watcherId = \Amp\Loop::onWritable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Amp\Loop::cancel($watcherId);
                $deferred->resolve($stream);
            }
        );

        $cancelCallback = function () use ($watcherId, $deferred) {
            \Amp\Loop::cancel($watcherId);
            $deferred->fail(new CancellationException('writable cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancelCallback);
    }

    public function __construct()
    {
        $this->unhandledFailingPromises = new Common\Internal\FailingPromiseCollection();
    }

    /** @var Common\Internal\FailingPromiseCollection */
    private $unhandledFailingPromises;
}
