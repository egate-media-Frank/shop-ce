<?php

/**
 * This file is part of O3-Shop.
 *
 * O3-Shop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3.
 *
 * O3-Shop is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with O3-Shop.  If not, see <http://www.gnu.org/licenses/>
 *
 * @copyright  Copyright (c) 2026 O3-Shop (https://www.o3-shop.com)
 * @license    https://www.gnu.org/licenses/gpl-3.0  GNU General Public License 3 (GPLv3)
 */

declare(strict_types=1);

namespace OxidEsales\EshopCommunity\Tests\Unit\Internal\Domain\Captcha\Verifier;

use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\VerificationResult;
use PHPUnit\Framework\TestCase;

class VerificationResultTest extends TestCase
{
    public function testSuccessResultCarriesScoreAndAction(): void
    {
        $r = new VerificationResult(true, 0.9, 'contact', []);
        $this->assertTrue($r->isSuccess());
        $this->assertSame(0.9, $r->getScore());
        $this->assertSame('contact', $r->getAction());
        $this->assertSame([], $r->getErrorCodes());
    }

    public function testFailureResultDefaults(): void
    {
        $r = new VerificationResult(false, null, null, ['timeout-or-duplicate']);
        $this->assertFalse($r->isSuccess());
        $this->assertNull($r->getScore());
        $this->assertNull($r->getAction());
        $this->assertSame(['timeout-or-duplicate'], $r->getErrorCodes());
    }
}
