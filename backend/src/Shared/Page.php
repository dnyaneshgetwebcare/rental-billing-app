<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Http\Message\ServerRequestInterface;

final readonly class Page
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
    ) {}

    public static function fromRequest(ServerRequestInterface $request, int $defaultPerPage = 25): self
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($q['per_page'] ?? $defaultPerPage)));
        return new self($page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
