<?php
/**
 * Kiboko CI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/kiboko-labs/ci/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace Kiboko\Component\ContinuousIntegration\Helper;

/**
 * Unix/Linux specific extension of the CommandExecutor class.
 * @package PHPCI\Helper
 */
class UnixCommandExecutor extends BaseCommandExecutor
{
    /**
     * Uses 'which' to find a system binary by name.
     * @param string $binary
     * @return null|string
     */
    protected function findGlobalBinary($binary)
    {
        return trim(shell_exec('which ' . $binary));
    }
}
