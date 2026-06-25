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

namespace OxidEsales\EshopCommunity\Internal\Domain\Captcha\Provider;

use OxidEsales\Eshop\Core\Request;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Configuration\CaptchaConfigurationInterface;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Field\CaptchaConfigField;
use OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier\CaptchaVerifierInterface;

final class GoogleReCaptchaV3Provider implements CaptchaProviderInterface
{
    public const ID = 'google_recaptcha_v3';
    private const TOKEN_FIELD = 'recaptcha_token';
    private const DEFAULT_THRESHOLD = '0.5';

    /** @var CaptchaConfigurationInterface */
    private $configuration;
    /** @var CaptchaVerifierInterface */
    private $verifier;

    public function __construct(CaptchaConfigurationInterface $configuration, CaptchaVerifierInterface $verifier)
    {
        $this->configuration = $configuration;
        $this->verifier = $verifier;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getTitle(): string
    {
        return 'O3_CAPTCHA_PROVIDER_GOOGLE_V3';
    }

    public function getConfigFields(): array
    {
        return [
            new CaptchaConfigField('siteKey', 'O3_CAPTCHA_SITE_KEY', CaptchaConfigField::TYPE_TEXT),
            new CaptchaConfigField('secretKey', 'O3_CAPTCHA_SECRET_KEY', CaptchaConfigField::TYPE_PASSWORD),
            new CaptchaConfigField('scoreThreshold', 'O3_CAPTCHA_SCORE_THRESHOLD', CaptchaConfigField::TYPE_NUMBER, self::DEFAULT_THRESHOLD),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    public function getHeadScript(): ?string
    {
        if ($this->siteKey() === '') {
            return null;
        }
        $site = rawurlencode($this->siteKey());
        return '<script src="https://www.google.com/recaptcha/api.js?render=' . $site . '"></script>';
    }

    public function renderWidget(string $formId): string
    {
        if ($this->siteKey() === '') {
            return '';
        }
        $site = htmlspecialchars($this->siteKey(), ENT_QUOTES);
        $action = preg_replace('/[^a-zA-Z0-9_]/', '', $formId);
        $field = self::TOKEN_FIELD;

        return <<<HTML
<input type="hidden" name="{$field}" value="">
<script>
(function () {
    var s = document.currentScript;
    var form = s ? s.closest('form') : null;
    if (!form) { return; }
    form.addEventListener('submit', function (e) {
        if (form.dataset.o3CaptchaDone === '1') { return; }
        e.preventDefault();
        grecaptcha.ready(function () {
            grecaptcha.execute('{$site}', {action: '{$action}'}).then(function (token) {
                var input = form.querySelector('input[name="{$field}"]');
                if (input) { input.value = token; }
                form.dataset.o3CaptchaDone = '1';
                form.submit();
            });
        });
    });
})();
</script>
HTML;
    }

    public function verify(Request $request, string $formId): bool
    {
        $token = trim((string) $request->getRequestEscapedParameter(self::TOKEN_FIELD));
        if ($token === '') {
            return false;
        }
        $action = preg_replace('/[^a-zA-Z0-9_]/', '', $formId);
        $result = $this->verifier->verify($this->secretKey(), $token, $this->remoteIp(), $action, $this->threshold());
        return $result->isSuccess();
    }

    private function siteKey(): string
    {
        return (string) $this->configuration->getProviderSetting(self::ID, 'siteKey', '');
    }

    private function secretKey(): string
    {
        return (string) $this->configuration->getProviderSetting(self::ID, 'secretKey', '');
    }

    private function threshold(): float
    {
        return (float) $this->configuration->getProviderSetting(self::ID, 'scoreThreshold', self::DEFAULT_THRESHOLD);
    }

    private function remoteIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
