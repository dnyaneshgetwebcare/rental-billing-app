<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Api\Shared\Presenter\AsIsPresenter;
use App\Api\Shared\Presenter\FailPresenter;
use App\Api\Shared\Presenter\PresenterInterface;
use App\Api\Shared\Presenter\SuccessPresenter;
use App\Api\Shared\Presenter\ValidationResultPresenter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Result;

final readonly class ResponseFactory
{
    public function __construct(
        private DataResponseFactoryInterface $dataResponseFactory,
        private ResponseFactoryInterface $psrResponseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function success(
        array|object|null $data = null,
        PresenterInterface $presenter = new AsIsPresenter(),
        int $httpCode = Status::OK,
    ): ResponseInterface {
        return $this->dataResponseFactory
            ->createResponse((new SuccessPresenter($presenter))->present($data))
            ->withStatus($httpCode);
    }

    public function created(array|object|null $data = null): ResponseInterface
    {
        return $this->success($data, httpCode: Status::CREATED);
    }

    public function fail(
        string $message,
        array|object|null $data = null,
        int|string|null $code = null,
        int $httpCode = Status::BAD_REQUEST,
        PresenterInterface $presenter = new AsIsPresenter(),
    ): ResponseInterface {
        return $this->dataResponseFactory
            ->createResponse(
                (new FailPresenter($message, $code, $presenter))->present($data),
            )
            ->withStatus($httpCode);
    }

    public function notFound(string $message = 'Not found.'): ResponseInterface
    {
        return $this->fail($message, code: 'not_found', httpCode: Status::NOT_FOUND);
    }

    public function failValidation(Result $result): ResponseInterface
    {
        return $this->fail(
            'Validation failed.',
            $result,
            code: 'validation_failed',
            httpCode: Status::UNPROCESSABLE_ENTITY,
            presenter: new ValidationResultPresenter(),
        );
    }

    /** Binary download (PDF, XLSX). */
    public function file(string $content, string $mime, string $filename, bool $inline = false): ResponseInterface
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'file';
        return $this->psrResponseFactory->createResponse()
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) strlen($content))
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streamFactory->createStream($content));
    }
}
