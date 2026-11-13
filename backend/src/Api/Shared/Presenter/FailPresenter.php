<?php

declare(strict_types=1);

namespace App\Api\Shared\Presenter;

/**
 * Error body: {"status": "failed", "error": {"code": "...", "message": "...", "fields": {"name": ["..."]}}}
 *
 * @implements PresenterInterface<mixed>
 */
final readonly class FailPresenter implements PresenterInterface
{
    public function __construct(
        private string $message = 'Unknown error.',
        private int|string|null $code = null,
        private PresenterInterface $presenter = new AsIsPresenter(),
    ) {}

    public function present(mixed $value): mixed
    {
        $error = [
            'code' => $this->code ?? 'error',
            'message' => $this->message,
        ];
        if ($value !== null) {
            $error['fields'] = $this->presenter->present($value);
        }
        return [
            'status' => 'failed',
            'error' => $error,
        ];
    }
}
