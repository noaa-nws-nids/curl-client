<?php
namespace Http\Client\Curl\Tests;

use Http\Client\Curl\CurlPromise;
use Http\Client\Curl\MultiRunner;
use Http\Client\Exception\TransferException;
use Http\Promise\Promise;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Tests for Http\Client\Curl\CurlPromise
 *
 * @covers Http\Client\Curl\CurlPromise
 */
class CurlPromiseTest extends BaseUnitTestCase
{
    /**
     * Test that promise call core methods
     */
    public function testCoreCalls()
    {
        $core = $this->createPromiseCore();
        $runner = $this->getMockBuilder(MultiRunner::class)->disableOriginalConstructor()
            ->setMethods(['wait'])->getMock();
        /** @var MultiRunner|\PHPUnit_Framework_MockObject_MockObject $runner */
        $promise = new CurlPromise($core, $runner);

        $arguments = null;
        $onFulfill = function () {
        };
        $onReject = function () {
        };
        $core->expects(static::once())->method('addPromise')->will($this->returnCallback(function () use ($arguments) {
            $arguments = func_get_args();
            var_dump($arguments);
        }));
        $value = $promise->then($onFulfill, $onReject);
        var_dump($arguments);
        static::assertInstanceOf(Promise::class, $value);
        static::assertEquals($promise, $arguments[0]);
        static::assertEquals($value, $arguments[1]);
        static::assertEquals($onFulfill, $arguments[2]);
        static::assertEquals($onReject, $arguments[3]);
    }

    public function testCoreCallWaitFulfilled()
    {
        $core = $this->createPromiseCore();
        $runner = $this->getMockBuilder(MultiRunner::class)->disableOriginalConstructor()
            ->setMethods(['wait'])->getMock();
        /** @var MultiRunner|\PHPUnit_Framework_MockObject_MockObject $runner */
        $runner->expects(static::once())->method('wait')->with($core);

        $stream = $this->getMockBuilder(StreamInterface::class)->disableOriginalConstructor()->getMock();
        $stream->expects(static::once())->method('__toString')->willReturn('RESPONSE');

        $response = $this->getMockBuilder(ResponseInterface::class)->disableOriginalConstructor()->getMock();
        $response->expects(static::once())->method('getBody')->willReturn($stream);

        $promise = new CurlPromise($core, $runner);
        $promise->resolve($response);

        static::assertEquals('RESPONSE', (string) $promise->wait()->getBody());
        static::assertEquals(Promise::FULFILLED, $promise->getState());
    }

    public function testCoreCallWaitRejected()
    {
        $core = $this->createPromiseCore();
        $runner = $this->getMockBuilder(MultiRunner::class)->disableOriginalConstructor()
            ->setMethods(['wait'])->getMock();
        /** @var MultiRunner|\PHPUnit_Framework_MockObject_MockObject $runner */
        $runner->expects(static::once())->method('wait')->with($core);

        $promise = new CurlPromise($core, $runner);
        $promise->resolve(new TransferException());

        try {
            $promise->wait();
            static::fail('Expected TransferException to be thrown');
        } catch (TransferException $exception) {
            static::assertTrue(true);
        }

        static::assertEquals(Promise::REJECTED, $promise->getState());
    }

    public function testCoreCallWaitPending()
    {
        $core = $this->createPromiseCore();
        $runner = $this->getMockBuilder(MultiRunner::class)->disableOriginalConstructor()
            ->setMethods(['wait'])->getMock();
        /** @var MultiRunner|\PHPUnit_Framework_MockObject_MockObject $runner */
        $runner->expects(static::once())->method('wait')->with($core);

        $promise = new CurlPromise($core, $runner);

        try {
            $promise->wait();
            static::fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $exception) {
            static::assertEquals('Promise still pending after resolution', $exception->getMessage());
        }
    }
}
