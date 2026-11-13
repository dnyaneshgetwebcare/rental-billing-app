<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\ExceptionResponderFactory;
use App\Shared\ApiException;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use HttpSoft\Message\ServerRequest;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yiisoft\DataResponse\DataStream\DataStream;
use Yiisoft\Di\Container;
use Yiisoft\ErrorHandler\Exception\UserException;
use Yiisoft\ErrorHandler\Middleware\ExceptionResponder;
use Yiisoft\Injector\Injector;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class ExceptionResponderFactoryTest extends Unit
{
    public function testApiValidationException(): void
    {
        $response = $this->respond(ApiException::validation(['lines.0.rate' => 'Required.']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            [
                'status' => 'failed',
                'error' => ['code' => 'validation_failed', 'message' => 'Validation failed.', 'fields' => ['lines.0.rate' => ['Required.']]],
            ],
            $this->data($response),
        );
    }

    public function testApiNotFoundAndForbidden(): void
    {
        $notFound = $this->respond(ApiException::notFound('Invoice'));
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('not_found', $this->data($notFound)['error']['code']);

        $forbidden = $this->respond(ApiException::forbidden());
        $this->assertSame(403, $forbidden->getStatusCode());
        $this->assertSame('forbidden', $this->data($forbidden)['error']['code']);
    }

    public function testInputValidationException(): void
    {
        $response = $this->respond(new InputValidationException((new Result())->addError('error1', valuePath: ['name'])));

        $this->assertSame(
            ['status' => 'failed', 'error' => ['code' => 'validation_failed', 'message' => 'Validation failed.', 'fields' => ['name' => ['error1']]]],
            $this->data($response),
        );
    }

    public function testUserException(): void
    {
        $response = $this->respond(new UserException('Hello, Exception!'));

        $this->assertSame(['status' => 'failed', 'error' => ['code' => 0, 'message' => 'Hello, Exception!']], $this->data($response));
    }

    public function testOtherThrowableIsRethrown(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Hello, Exception!');
        $this->respond(new LogicException('Hello, Exception!'));
    }

    private function respond(Throwable $exception): ResponseInterface
    {
        $handler = new class ($exception) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $exception) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
        return $this->createExceptionResponder()->process(new ServerRequest(), $handler);
    }

    private function data(ResponseInterface $response): mixed
    {
        $body = $response->getBody();
        $this->assertInstanceOf(DataStream::class, $body);
        return $body->getData();
    }

    private function createExceptionResponder(): ExceptionResponder
    {
        return (new ExceptionResponderFactory(
            new PsrResponseFactory(),
            ResponseFactoryTest::createResponseFactory(),
            new Injector(new Container()),
        ))->create();
    }
}
