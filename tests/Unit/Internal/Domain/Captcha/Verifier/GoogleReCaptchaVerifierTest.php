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

use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\GoogleReCaptchaVerifier;
use PHPUnit\Framework\TestCase;
use ReCaptcha\RequestMethod;
use ReCaptcha\RequestParameters;

class GoogleReCaptchaVerifierTest extends TestCase
{
    private function verifierReturning(string $json): GoogleReCaptchaVerifier
    {
        $transport = new class ($json) implements RequestMethod {
            private $json;
            public function __construct(string $json)
            {
                $this->json = $json;
            }
            public function submit(RequestParameters $params): string
            {
                return $this->json;
            }
        };

        return new GoogleReCaptchaVerifier($transport);
    }

    public function testV2SuccessMapsToSuccessResult(): void
    {
        $result = $this->verifierReturning('{"success":true}')->verify('secret', 'token', '203.0.113.1');
        $this->assertTrue($result->isSuccess());
    }

    public function testV3SuccessWithSufficientScoreAndMatchingAction(): void
    {
        $result = $this->verifierReturning('{"success":true,"score":0.9,"action":"contact"}')
            ->verify('secret', 'token', null, 'contact', 0.5);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(0.9, $result->getScore());
    }

    public function testV3FailsWhenScoreBelowThreshold(): void
    {
        $result = $this->verifierReturning('{"success":true,"score":0.1,"action":"contact"}')
            ->verify('secret', 'token', null, 'contact', 0.5);
        $this->assertFalse($result->isSuccess());
    }

    public function testV3FailsWhenActionMismatch(): void
    {
        $result = $this->verifierReturning('{"success":true,"score":0.9,"action":"newsletter"}')
            ->verify('secret', 'token', null, 'contact', 0.5);
        $this->assertFalse($result->isSuccess());
    }

    public function testTransportFailureMapsToFailure(): void
    {
        $result = $this->verifierReturning('{"success":false,"error-codes":["timeout-or-duplicate"]}')
            ->verify('secret', 'token', null);
        $this->assertFalse($result->isSuccess());
        $this->assertContains('timeout-or-duplicate', $result->getErrorCodes());
    }

    public function testV3ZeroScoreIsPreservedAndEmptyActionMapsToNull(): void
    {
        $result = $this->verifierReturning('{"success":true,"score":0.0}')
            ->verify('secret', 'token', null);
        $this->assertSame(0.0, $result->getScore());
        $this->assertNull($result->getAction());
    }
}
