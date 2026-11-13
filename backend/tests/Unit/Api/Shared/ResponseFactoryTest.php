<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\ResponseFactory;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use HttpSoft\Message\StreamFactory;
use Yiisoft\DataResponse\DataStream\DataStream;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\Validator\Result;

final class ResponseFactoryTest extends Unit
{
    public function testSuccess(): void
    {
        $response = self::createResponseFactory()->success(['name' => 'test']);

        $body = $response->getBody();
        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'success', 'data' => ['name' => 'test']], $body->getData());
    }

    public function testCreated(): void
    {
        $this->assertSame(201, self::createResponseFactory()->created(['id' => 1])->getStatusCode());
    }

    public function testFail(): void
    {
        $response = self::createResponseFactory()->fail('error text');

        $body = $response->getBody();
        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['status' => 'failed', 'error' => ['code' => 'error', 'message' => 'error text']], $body->getData());
    }

    public function testNotFound(): void
    {
        $response = self::createResponseFactory()->notFound();

        $body = $response->getBody();
        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['status' => 'failed', 'error' => ['code' => 'not_found', 'message' => 'Not found.']], $body->getData());
    }

    public function testFailValidation(): void
    {
        $result = (new Result())
            ->addError('error1', valuePath: ['name'])
            ->addError('error2', valuePath: ['name'])
            ->addError('error3', valuePath: ['age']);
        $response = self::createResponseFactory()->failValidation($result);

        $body = $response->getBody();
        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            [
                'status' => 'failed',
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Validation failed.',
                    'fields' => ['name' => ['error1', 'error2'], 'age' => ['error3']],
                ],
            ],
            $body->getData(),
        );
    }

    public function testFileDownloadHeaders(): void
    {
        $response = self::createResponseFactory()->file('%PDF-1.7', 'application/pdf', 'Invoice ABC/26-27/0001.pdf', inline: true);

        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="Invoice_ABC_26-27_0001.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('8', $response->getHeaderLine('Content-Length'));
        $this->assertSame('%PDF-1.7', (string) $response->getBody());
    }

    public static function createResponseFactory(): ResponseFactory
    {
        return new ResponseFactory(
            new DataResponseFactory(new PsrResponseFactory()),
            new PsrResponseFactory(),
            new StreamFactory(),
        );
    }
}
