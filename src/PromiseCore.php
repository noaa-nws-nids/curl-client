<?php
namespace Http\Client\Curl;

use Http\Client\Exception;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared promises core.
 *
 * @license http://opensource.org/licenses/MIT MIT
 *
 * @author  Михаил Красильников <m.krasilnikov@yandex.ru>
 */
class PromiseCore
{
    /**
     * HTTP request
     *
     * @var RequestInterface
     */
    private $request;

    /**
     * cURL handle
     *
     * @var resource
     */
    private $handle;

    /**
     * Response builder
     *
     * @var ResponseBuilder
     */
    private $responseBuilder;

    /**
     * Exception
     *
     * @var Exception|null
     */
    private $exception = null;

    /**
     * Callbacks.
     *
     * @var array
     */
    private $promises = [];

    private $settled = false;

    /**
     * Create shared core.
     *
     * @param RequestInterface $request HTTP request
     * @param resource         $handle  cURL handle
     * @param ResponseBuilder  $responseBuilder
     */
    public function __construct(
        RequestInterface $request,
        $handle,
        ResponseBuilder $responseBuilder
    ) {
        assert('is_resource($handle)');
        assert('get_resource_type($handle) === "curl"');

        $this->request = $request;
        $this->handle = $handle;
        $this->responseBuilder = $responseBuilder;
    }

    public function addPromise(CurlPromise $promise, CurlPromise $parent = null, callable $onFulfilled = null, callable $onRejected = null)
    {
        if (!$this->settled) {
            $this->promises[] = [$promise, $parent, $onFulfilled, $onRejected];
        } else {
            $this->resolve($promise, $parent, $onFulfilled, $onRejected);
        }
    }

    private function settle()
    {
        $promises = $this->promises;

        while ($promises) {
            list($promise, $parent, $onFulfilled, $onRejected) = array_shift($promises);

            if ($promise->getState() !== Promise::PENDING) {
                continue;
            }

            $this->resolve($promise, $parent, $onFulfilled, $onRejected);
        }

        $this->promises = [];
        $this->settled = true;
    }

    private function resolve(CurlPromise $promise, CurlPromise $parent = null, callable $onFulfilled = null, callable $onRejected = null)
    {
        $response = null;
        $exception = null;

        if ($parent) {
            try {
                $response = $parent->wait(true);
            } catch (Exception $exception) {
                $exception = $exception;
            }
        } else {
            $response = $this->getResponse();
            $exception = $this->exception;
        }

        try {
            if ($exception && $onRejected) {
                $response = call_user_func($onRejected, $exception);
            } elseif ($exception) {
                $promise->resolve($exception);
                return;
            } elseif ($response && $onFulfilled) {
                $response = call_user_func($onFulfilled, $response);
            }
            $promise->resolve($response);
        } catch (Exception $exception) {
            $promise->resolve($exception);
        }
    }

    /**
     * Return cURL handle
     *
     * @return resource
     */
    public function getHandle()
    {
        return $this->handle;
    }

    /**
     * Return request
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Return the value of the promise (fulfilled).
     *
     * @return ResponseInterface Response Object only when the Promise is fulfilled.
     */
    public function getResponse()
    {
        return $this->responseBuilder->getResponse();
    }

    /**
     * Get the reason why the promise was rejected.
     *
     * If the exception is an instance of Http\Client\Exception\HttpException it will contain
     * the response object with the status code and the http reason.
     *
     * @return Exception Exception Object only when the Promise is rejected.
     *
     * @throws \LogicException When the promise is not rejected.
     */
    public function getException()
    {
        if (null === $this->exception) {
            throw new \LogicException('Promise is not rejected');
        }

        return $this->exception;
    }

    /**
     * Fulfill promise.
     */
    public function fulfill()
    {
        if ($this->settled) {
            return;
        }

        $response = $this->responseBuilder->getResponse();
        try {
            $response->getBody()->seek(0);
            $this->settle();
        } catch (\RuntimeException $e) {
            $exception = new Exception\TransferException($e->getMessage(), $e->getCode(), $e);
            $this->reject($exception);
        }
    }

    /**
     * Reject promise.
     *
     * @param Exception $exception Reject reason.
     */
    public function reject(Exception $exception)
    {
        if ($this->settled) {
            return;
        }

        $this->exception = $exception;
        $this->settle();
    }
}
