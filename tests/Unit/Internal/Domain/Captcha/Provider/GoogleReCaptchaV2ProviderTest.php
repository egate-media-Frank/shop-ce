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

namespace OxidEsales\EshopCommunity\Tests\Unit\Internal\Domain\Captcha\Provider;

use OxidEsales\Eshop\Core\Request;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Configuration\CaptchaConfigurationInterface;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Provider\GoogleReCaptchaV2Provider;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\CaptchaVerifierInterface;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\VerificationResult;
use PHPUnit\Framework\TestCase;

class GoogleReCaptchaV2ProviderTest extends TestCase
{
    private function config(array $values): CaptchaConfigurationInterface
    {
        $config = $this->createMock(CaptchaConfigurationInterface::class);
        $config->method('getProviderSetting')->willReturnCallback(
            fn (string $providerId, string $key, $default = null) => $values[$key] ?? $default
        );
        return $config;
    }

    public function testIdAndConfigFields(): void
    {
        $provider = new GoogleReCaptchaV2Provider($this->config([]), $this->createMock(CaptchaVerifierInterface::class));
        $this->assertSame('google_recaptcha_v2', $provider->getId());
        $keys = array_map(fn ($f) => $f->getKey(), $provider->getConfigFields());
        $this->assertSame(['siteKey', 'secretKey'], $keys);
    }

    public function testIsConfiguredRequiresBothKeys(): void
    {
        $this->assertFalse((new GoogleReCaptchaV2Provider($this->config(['siteKey' => 'a']), $this->createMock(CaptchaVerifierInterface::class)))->isConfigured());
        $this->assertTrue((new GoogleReCaptchaV2Provider($this->config(['siteKey' => 'a', 'secretKey' => 'b']), $this->createMock(CaptchaVerifierInterface::class)))->isConfigured());
    }

    public function testRenderWidgetContainsSiteKey(): void
    {
        $provider = new GoogleReCaptchaV2Provider($this->config(['siteKey' => 'SITE123']), $this->createMock(CaptchaVerifierInterface::class));
        $this->assertStringContainsString('g-recaptcha', $provider->renderWidget('contact'));
        $this->assertStringContainsString('SITE123', $provider->renderWidget('contact'));
    }

    public function testVerifyDelegatesToVerifierWithToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('g-recaptcha-response')->willReturn('tok');

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())->method('verify')
            ->with('SECRET', 'tok', $this->anything(), null, null)
            ->willReturn(new VerificationResult(true));

        $provider = new GoogleReCaptchaV2Provider($this->config(['secretKey' => 'SECRET']), $verifier);
        $this->assertTrue($provider->verify($request, 'contact'));
    }

    public function testVerifyShortCircuitsOnEmptyToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->willReturn('');
        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->never())->method('verify');

        $provider = new GoogleReCaptchaV2Provider($this->config(['secretKey' => 'SECRET']), $verifier);
        $this->assertFalse($provider->verify($request, 'contact'));
    }
}
