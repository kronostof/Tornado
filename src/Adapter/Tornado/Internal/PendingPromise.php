<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Exception\CancellationException;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PendingPromise implements Promise, Deferred
{
    private $value;
    private $throwable;
    private $callbacks = [];
    /** @var callable */
    private $cancelCallback;
    private $isSettled = false;
    /** @var ?FailingPromiseCollection */
    private $failingPromiseCollection;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    public function cancel(CancellationException $exception): void
    {
        if (!$this->isSettled) {
            $this->reject($exception);
            ($this->cancelCallback)($exception);
        }
    }

    public static function createUnhandled(FailingPromiseCollection $failingPromiseCollection, callable $cancelCallback = null)
    {
        $promiseWrapper = new self();
        $promiseWrapper->failingPromiseCollection = $failingPromiseCollection;
        $promiseWrapper->cancelCallback = $cancelCallback ?? function () {};

        return $promiseWrapper;
    }

    public static function createHandled(callable $cancelCallback = null)
    {
        $promiseWrapper = new self();
        $promiseWrapper->cancelCallback = $cancelCallback ?? function () {};
        $promiseWrapper->failingPromiseCollection = null;

        return $promiseWrapper;
    }

    public static function toHandledPromise(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        if ($promise->failingPromiseCollection !== null) {
            $promise->failingPromiseCollection->unwatchPromise($promise);
            $promise->failingPromiseCollection = null;
        }

        return $promise;
    }

    public function resolve($value): void
    {
        $this->settle();
        $this->value = $value;

        $this->triggerCallbacks();
    }

    public function reject(\Throwable $throwable): void
    {
        $this->settle();
        $this->throwable = $throwable;

        if ($this->failingPromiseCollection !== null) {
            $this->failingPromiseCollection->watchFailingPromise($this, $throwable);
        }

        $this->triggerCallbacks();
    }

    public function getPromise(): Promise
    {
        return $this;
    }

    public function addCallbacks(callable $onResolved, callable $onRejected): self
    {
        $this->callbacks[] = [$onResolved, $onRejected];

        return $this->isSettled ? $this->triggerCallbacks() : $this;
    }

    private function triggerCallbacks(): self
    {
        if ($this->throwable !== null) {
            foreach ($this->callbacks as [, $onRejected]) {
                $onRejected($this->throwable);
            }
        } else {
            foreach ($this->callbacks as [$onResolved]) {
                $onResolved($this->value);
            }
        }
        // Callbacks must be triggered only once!
        $this->callbacks = [];

        return $this;
    }

    private function settle()
    {
        if ($this->isSettled) {
            throw new \LogicException('Cannot resolve/reject a promise already settled.');
        }

        $this->isSettled = true;
    }
}
