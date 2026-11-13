<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accounting;

use App\Accounting\PostingService;
use App\Shared\ApiException;
use Codeception\Test\Unit;

final class PostingRulesTest extends Unit
{
    public function testCleanDropsZeroLinesAndFlipsNegatives(): void
    {
        $lines = PostingService::clean([
            ['account_id' => 1, 'debit' => '100'],
            ['account_id' => 2, 'credit' => '0'],
            ['account_id' => 3, 'debit' => '-0.40'],
            ['account_id' => 4, 'credit' => '99.60'],
        ]);

        $this->assertCount(3, $lines);
        $this->assertSame(['account_id' => 1, 'debit' => '100.00', 'credit' => '0.00'], $lines[0]);
        $this->assertSame('0.00', $lines[1]['debit']);
        $this->assertSame('0.40', $lines[1]['credit']);
        $this->assertSame('99.60', $lines[2]['credit']);
    }

    public function testCleanNetsDebitAndCreditOnSameLine(): void
    {
        $lines = PostingService::clean([['account_id' => 1, 'debit' => '10', 'credit' => '25']]);
        $this->assertSame('0.00', $lines[0]['debit']);
        $this->assertSame('15.00', $lines[0]['credit']);
    }

    public function testBalancedVoucherPasses(): void
    {
        PostingService::assertBalanced(PostingService::clean([
            ['account_id' => 1, 'debit' => '29501'],
            ['account_id' => 2, 'credit' => '25000'],
            ['account_id' => 3, 'credit' => '2250'],
            ['account_id' => 4, 'credit' => '2250'],
            ['account_id' => 5, 'credit' => '1'],
        ]));
        $this->assertTrue(true);
    }

    public function testUnbalancedVoucherIsRejected(): void
    {
        try {
            PostingService::assertBalanced(PostingService::clean([
                ['account_id' => 1, 'debit' => '10'],
                ['account_id' => 2, 'credit' => '9'],
            ]));
            $this->fail('Expected validation error');
        } catch (ApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertStringContainsString('must be equal', $e->fields['lines'][0]);
        }
    }

    public function testEmptyVoucherIsRejected(): void
    {
        $this->expectException(ApiException::class);
        PostingService::assertBalanced(PostingService::clean([['account_id' => 1, 'debit' => '0']]));
    }
}
