<?php

declare(strict_types=1);

namespace Drupal\Tests\frankenphp_profiler\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test installation and uninstallation.
 */
#[Group('frankenphp_profiler')]
#[RunTestsInSeparateProcesses]
class FrankenphpProfilerInstallTest extends KernelTestBase {

  private const string MODULE_NAME = 'frankenphp_profiler';

  /**
   * Test that the module can be installed and uninstalled.
   */
  public function testInstallUninstall(): void {
    \Drupal::service('module_installer')->install([self::MODULE_NAME]);
    $this::assertSame(0, $this->config('core.extension')->get('module.' . self::MODULE_NAME));

    \Drupal::service('module_installer')->uninstall([self::MODULE_NAME]);
    $this::assertNotSame(0, $this->config('core.extension')->get('module.' . self::MODULE_NAME));

    // Try installing and uninstalling again.
    \Drupal::service('module_installer')->install([self::MODULE_NAME]);
    $this::assertSame(0, $this->config('core.extension')->get('module.' . self::MODULE_NAME));

    \Drupal::service('module_installer')->uninstall([self::MODULE_NAME]);
    $this::assertNotSame(0, $this->config('core.extension')->get('module.' . self::MODULE_NAME));
  }

}
