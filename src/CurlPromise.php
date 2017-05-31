<?php
namespace Http\Client\Curl;

use Http\Client\Exception;
use Http\Promise\Promise;
use Psr\Http\Message\ResponseInterface;

/**
 * Promise represents a response that may not be available yet, but will be resolved at some point
 * in future. It acts like a proxy to the actual response.
 *
 * This interface is an extension of the promises/a+ specification https://promisesaplus.com/
 * Value is replaced by an object where its class implement a Psr\Http\Message\RequestInterface.
 * Reason is replaced by an object where its class implement a Http\Client\Exception.
 *
 * @license http://opensource.org/licenses/MIT MIT
 *
 * @author  Михаил Красильников <m.krasilnikov@yandex.ru>
 */
class CurlPromise implements Promise
{
    /**
     * Shared promise core
     *
     * @var PromiseCore
     */
    private $core;

    /**
     * Requests runner
     *
     * @var MultiRunner
     */
    private $runner;

    /**
     * Promise result
     *
     * @var ResponseInterface|Exception|null
     */
    private $result;

    /**
     * Promise state
     *
     * @var string
     */
    private $state = Promise::PENDING;

    /**
     * Create new promise.
     *
     * @param PromiseCore $core   Shared promise core
     * @param MultiRunner $runner Simultaneous requests runner
     */
    public function __construct(PromiseCore $core, MultiRunner $runner)
    {
        $this->core = $core;
        $this->runner = $runner;
    }

    /**
     * Add behavior for when the promise is resolved or rejected.
     *
     * If you do not care about one of the cases, you can set the corresponding callable to null
     * The callback will be called when the response or exception arrived and never more than once.
     *
     * @param callable $onFulfilled Called when a response will be available.
     * @param callable $onRejected  Called when an error happens.
     *
     * You must always return the Response in the interface or throw an Exception.
     *
     * @return Promise Always returns a new promise which is resolved with value of the executed
     *                 callback (onFulfilled / onRejected).
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null)
    {
        $promise = new self($this->core, $this->runner);
        $this->core->addPromise($promise, $this, $onFulfilled, $onRejected);

        return $promise;
    }

    /**
     * Get the state of the promise, one of PENDING, FULFILLED or REJECTED.
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Wait for the promise to be fulfilled or rejected.
     *
     * When this method returns, the request has been resolved and the appropriate callable has terminated.
     *
     * When called with the unwrap option
     *
     * @param bool $unwrap Whether to return resolved value / throw reason or not
     *
     * @return \Psr\Http\Message\ResponseInterface|null Resolved value, null if $unwrap is set to false
     *
     * @throws \Http\Client\Exception The rejection reason.
     */
    public function wait($unwrap = true)
    {
        $this->runner->wait($this->core);

        if ($this->state === Promise::PENDING) {
            throw new \RuntimeException('Promise still pending after resolution');
        }

        if ($unwrap) {
            if ($this->state === Promise::FULFILLED) {
                return $this->result;
            }
            throw $this->result;
        }
    }

    public function resolve($result)
    {
        if ($this->state !== Promise::PENDING) {
            throw new \RuntimeException('Cannot change existing promise result');
        } elseif ($result instanceof ResponseInterface) {
            $this->state = Promise::FULFILLED;
        } elseif ($result instanceof Exception) {
            $this->state = Promise::REJECTED;
        } else {
            throw new \RuntimeException('Promise resolution must implement ResponseInterface or Exception');
        }

        $this->result = $result;
    }
}
