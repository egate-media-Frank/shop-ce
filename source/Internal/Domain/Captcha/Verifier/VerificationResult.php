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

final class VerificationResult
{
    /** @var bool */
    private $success;
    /** @var float|null */
    private $score;
    /** @var string|null */
    private $action;
    /** @var string[] */
    private $errorCodes;

    /** @param string[] $errorCodes */
    public function __construct(bool $success, ?float $score = null, ?string $action = null, array $errorCodes = [])
    {
        $this->success = $success;
        $this->score = $score;
        $this->action = $action;
        $this->errorCodes = $errorCodes;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    /** @return string[] */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }
}
