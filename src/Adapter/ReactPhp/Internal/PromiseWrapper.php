<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Exception\CancellationException;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /** @var callable */
    private $cancelCallback;

    /**
     * @var \React\Promise\CancellablePromiseInterface
     */
    private $reactPromise;

    /** @var bool */
    private $isHandled;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    public function cancel(CancellationException $exception): void
    {
        ($this->cancelCallback)($exception);
    }

    public static function createUnhandled(\React\Promise\CancellablePromiseInterface $reactPromise, FailingPromiseCollection $failingPromiseCollection, callable $cancelCallback)
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = false;
        $promiseWrapper->reactPromise = $reactPromise;
        $promiseWrapper->cancelCallback= $cancelCallback;
        $promiseWrapper->reactPromise->then(
            null,
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection) {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    public static function createHandled(\React\Promise\CancellablePromiseInterface $reactPromise, callable $cancelCallback)
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = true;
        $promiseWrapper->reactPromise = $reactPromise;
        $promiseWrapper->cancelCallback = $cancelCallback;

        return $promiseWrapper;
    }

    public function getReactPromise(): \React\Promise\CancellablePromiseInterface
    {
        return $this->reactPromise;
    }

    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
