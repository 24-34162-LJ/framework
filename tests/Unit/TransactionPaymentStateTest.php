<?php

/*
 * FILE PURPOSE
 * File   : tests/Unit/TransactionPaymentStateTest.php
 * Feature: Testing - Transactions
 * Purpose: Tests the transaction payment state (No bills / Unpaid / Partially paid / Fully paid),
 *          which is derived from all bills of the shipment and is separate from shipment progress.
 */

namespace Tests\Unit;

use App\Models\Transaction;
use PHPUnit\Framework\TestCase;

class TransactionPaymentStateTest extends TestCase
{
    public function test_no_bills(): void
    {
        $this->assertSame('none', Transaction::paymentState(0, 0, 0)['key']);
    }

    public function test_unpaid_partial_and_fully_paid(): void
    {
        $this->assertSame('unpaid', Transaction::paymentState(2, '1300.00', '0.00')['key']);
        $this->assertSame('partial', Transaction::paymentState(2, '1300.00', '300.00')['key']);
        $this->assertSame('paid', Transaction::paymentState(2, '1300.00', '1300.00')['key']);
    }

    public function test_overpayment_counts_as_fully_paid(): void
    {
        $this->assertSame('paid', Transaction::paymentState(1, '300.00', '350.00')['key']);
    }

    public function test_compares_in_cents_not_floats(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 as a float; it must still settle a 0.30 bill.
        $this->assertSame('paid', Transaction::paymentState(1, '0.30', 0.1 + 0.2)['key']);
        $this->assertSame('partial', Transaction::paymentState(1, '0.30', '0.29')['key']);
    }
}
