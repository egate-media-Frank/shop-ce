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
 * @copyright  Copyright (c) 2022 O3-Shop (https://www.o3-shop.com)
 * @license    https://www.gnu.org/licenses/gpl-3.0  GNU General Public License 3 (GPLv3)
 */

namespace OxidEsales\EshopCommunity\Tests\Unit\Core;

use Composer\IO\IOInterface;
use OxidEsales\EshopCommunity\Core\MigrationsRunner;

class MigrationsRunnerTest extends \OxidTestCase
{
    /**
     * Build a runner with all seams overridden. Flags decide guard outcomes;
     * $migrateReturn controls the migration result (int) or a Throwable to throw.
     */
    private function makeRunner(
        bool $configExists = true,
        bool $canConnect = true,
        bool $launched = true,
        $migrateReturn = 0
    ): MigrationsRunner {
        return new class ($configExists, $canConnect, $launched, $migrateReturn) extends MigrationsRunner {
            public bool $migrated = false;
            public bool $bootstrapped = false;
            private bool $configExists;
            private bool $canConnect;
            private bool $launched;
            private $migrateReturn;

            public function __construct($configExists, $canConnect, $launched, $migrateReturn)
            {
                $this->configExists = $configExists;
                $this->canConnect = $canConnect;
                $this->launched = $launched;
                $this->migrateReturn = $migrateReturn;
            }

            protected function configFileExists(): bool
            {
                return $this->configExists;
            }

            protected function canConnectToDatabase(): bool
            {
                return $this->canConnect;
            }

            protected function isShopLaunched(): bool
            {
                return $this->launched;
            }

            protected function loadShopBootstrap(): void
            {
                $this->bootstrapped = true;
            }

            protected function runMigrations(IOInterface $io): int
            {
                $this->migrated = true;
                if ($this->migrateReturn instanceof \Throwable) {
                    throw $this->migrateReturn;
                }
                return (int) $this->migrateReturn;
            }
        };
    }

    public function testRunIsStaticAndAcceptsComposerEvent(): void
    {
        $reflection = new \ReflectionMethod(MigrationsRunner::class, 'run');
        $this->assertTrue($reflection->isStatic(), 'run must be static');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Composer\Script\Event::class, $params[0]->getType()->getName());
    }

    public function testSkipsWhenConfigFileMissing(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->atLeastOnce())->method('writeError');

        $runner = $this->makeRunner($configExists = false);
        $runner->process($io);

        $this->assertFalse($runner->migrated, 'Migrations must not run without a config file');
    }

    public function testSkipsWhenDatabaseUnreachable(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->atLeastOnce())->method('writeError');

        $runner = $this->makeRunner(true, $canConnect = false);
        $runner->process($io);

        $this->assertFalse($runner->migrated, 'Migrations must not run when DB is unreachable');
    }

    public function testSkipsWhenShopNotLaunched(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->atLeastOnce())->method('writeError');

        $runner = $this->makeRunner(true, true, $launched = false);
        $runner->process($io);

        $this->assertFalse($runner->migrated, 'Migrations must not run when shop is not launched');
    }

    public function testRunsMigrationsWhenGuardPasses(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('write');

        $runner = $this->makeRunner(true, true, true, 0);
        $runner->process($io);

        $this->assertTrue($runner->bootstrapped, 'Shop bootstrap must be loaded before migrating');
        $this->assertTrue($runner->migrated, 'Migrations must run when the guard passes');
    }

    public function testThrowsWhenMigrationReturnsNonZero(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->atLeastOnce())->method('writeError');

        $runner = $this->makeRunner(true, true, true, 5);

        $this->expectException(\RuntimeException::class);
        $runner->process($io);
    }

    public function testRethrowsWhenMigrationThrows(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->atLeastOnce())->method('writeError');

        $runner = $this->makeRunner(true, true, true, new \LogicException('boom'));

        $this->expectException(\RuntimeException::class);
        $runner->process($io);
    }
}
