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

namespace OxidEsales\EshopCommunity\Internal\Domain\Captcha\Verifier;

use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod;

final class GoogleReCaptchaVerifier implements CaptchaVerifierInterface
{
    /** @var RequestMethod|null */
    private $requestMethod;

    public function __construct(?RequestMethod $requestMethod = null)
    {
        $this->requestMethod = $requestMethod;
    }

    public function verify(
        string $secret,
        string $token,
        ?string $remoteIp,
        ?string $expectedAction = null,
        ?float $scoreThreshold = null
    ): VerificationResult {
        $recaptcha = new ReCaptcha($secret, $this->requestMethod);

        if ($expectedAction !== null) {
            $recaptcha->setExpectedAction($expectedAction);
        }
        if ($scoreThreshold !== null) {
            $recaptcha->setScoreThreshold($scoreThreshold);
        }

        $response = $recaptcha->verify($token, $remoteIp);

        $score = $response->getScore() !== null ? (float) $response->getScore() : null;
        $action = $response->getAction() !== '' ? (string) $response->getAction() : null;

        return new VerificationResult(
            $response->isSuccess(),
            $score,
            $action,
            $response->getErrorCodes()
        );
    }
}
