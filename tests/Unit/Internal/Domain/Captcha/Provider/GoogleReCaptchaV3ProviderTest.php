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
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Provider\GoogleReCaptchaV3Provider;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\CaptchaVerifierInterface;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\VerificationResult;
use PHPUnit\Framework\TestCase;

class GoogleReCaptchaV3ProviderTest extends TestCase
{
    private function config(array $values): CaptchaConfigurationInterface
    {
        $config = $this->createMock(CaptchaConfigurationInterface::class);
        $config->method('getProviderSetting')->willReturnCallback(
            fn (string $providerId, string $key, $default = null) => $values[$key] ?? $default
        );
        return $config;
    }

    public function testConfigFieldsIncludeThresholdWithDefault(): void
    {
        $provider = new GoogleReCaptchaV3Provider($this->config([]), $this->createMock(CaptchaVerifierInterface::class));
        $fields = [];
        foreach ($provider->getConfigFields() as $f) {
            $fields[$f->getKey()] = $f;
        }
        $this->assertArrayHasKey('scoreThreshold', $fields);
        $this->assertSame('0.5', $fields['scoreThreshold']->getDefault());
    }

    public function testWidgetCarriesPerFormActionAndHiddenField(): void
    {
        $provider = new GoogleReCaptchaV3Provider($this->config(['siteKey' => 'SITE']), $this->createMock(CaptchaVerifierInterface::class));
        $html = $provider->renderWidget('newsletter');
        $this->assertStringContainsString("{action: 'newsletter'}", $html);
        $this->assertStringContainsString('recaptcha_token', $html);
        $this->assertStringContainsString('SITE', $html);
    }

    public function testHeadScriptCarriesRenderSiteKey(): void
    {
        $provider = new GoogleReCaptchaV3Provider($this->config(['siteKey' => 'SITE']), $this->createMock(CaptchaVerifierInterface::class));
        $this->assertStringContainsString('render=SITE', (string) $provider->getHeadScript());
    }

    public function testVerifyPassesActionAndThreshold(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getRequestEscapedParameter')->with('recaptcha_token')->willReturn('tok');

        $verifier = $this->createMock(CaptchaVerifierInterface::class);
        $verifier->expects($this->once())->method('verify')
            ->with('SECRET', 'tok', $this->anything(), 'contact', 0.5)
            ->willReturn(new VerificationResult(true, 0.9, 'contact'));

        $provider = new GoogleReCaptchaV3Provider($this->config(['secretKey' => 'SECRET', 'scoreThreshold' => '0.5']), $verifier);
        $this->assertTrue($provider->verify($request, 'contact'));
    }
}
