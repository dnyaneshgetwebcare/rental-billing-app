<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Shared\ApiException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\ErrorHandler\Exception\UserException;
use Yiisoft\ErrorHandler\Middleware\ExceptionResponder;
use Yiisoft\Injector\Injector;
use Yiisoft\Input\Http\InputValidationException;

final readonly class ExceptionResponderFactory
{
    public function __construct(
        private ResponseFactoryInterface $psrResponseFactory,
        private ResponseFactory $apiResponseFactory,
        private Injector $injector,
    ) {}

    public function create(): ExceptionResponder
    {
        return new ExceptionResponder(
            [
                ApiException::class => $this->apiException(...),
                InputValidationException::class => $this->inputValidationException(...),
                Throwable::class => $this->throwable(...),
            ],
            $this->psrResponseFactory,
            $this->injector,
        );
    }

    private function apiException(ApiException $exception): ResponseInterface
    {
        return $this->apiResponseFactory->fail(
            $exception->getMessage(),
            $exception->fields === [] ? null : $exception->fields,
            code: $exception->errorCode,
            httpCode: $exception->httpStatus,
        );
    }

    private function inputValidationException(InputValidationException $exception): ResponseInterface
    {
        return $this->apiResponseFactory->failValidation($exception->getResult());
    }

    private function throwable(Throwable $exception): ResponseInterface
    {
        if (UserException::isUserException($exception)) {
            $code = $exception->getCode();
            return $this->apiResponseFactory->fail(
                $exception->getMessage(),
                code: is_int($code) ? $code : null,
            );
        }
        throw $exception;
    }
}
