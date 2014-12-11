<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Plugin;

use PHPCI;
use PHPCI\Builder;
use PHPCI\Model\Build;

/**
 * PHPTAL Lint Plugin - Provides access to PHPTAL lint functionality.
 * @author       Stephen Ball <phpci@stephen.rebelinblue.com>
 * @package      PHPCI
 * @subpackage   Plugins
 */
class PhpTalLint implements PHPCI\Plugin
{
    protected $directories;
    protected $recursive = true;
    protected $suffixes;
    protected $ignore;
    protected $phpci;
    protected $build;
    protected $tales;
    protected $allowed_warnings;
    protected $failedPaths = array();

    /**
     * Standard Constructor
     *
     * @param Builder $phpci
     * @param Build   $build
     * @param array   $options
     */
    public function __construct(Builder $phpci, Build $build, array $options = array())
    {
        $this->phpci = $phpci;
        $this->build = $build;
        $this->directories = array('');
        $this->suffixes = array('zpt');
        $this->ignore = $phpci->ignore;

        $this->allowed_warnings = 0;
        $this->allowed_errors = 0; 

        if (!empty($options['directory'])) {
            $this->directories = array($options['directory']);
        }

        if (!empty($options['directories'])) {
            $this->directories = $options['directories'];
        }

        if (isset($options['suffixes'])) {
            $this->suffixes = (array)$options['suffixes'];
        }

        if (isset($options['allowed_warnings'])) {
            $this->allowed_warnings = $options['allowed_warnings'];
        }

        if (isset($options['allowed_errors'])) {
            $this->allowed_errors = $options['allowed_errors'];
        }

        if (array_key_exists('tales', $options)) {
            $this->tales = $options['tales'];
        }
    }

    public function execute()
    {
        $this->phpci->quiet = true;
        $success = true;

        $this->phpci->logExecOutput(false);

        foreach ($this->directories as $dir) {
            $this->lintDirectory($dir);
        }

        $this->phpci->quiet = false;

        $this->phpci->logExecOutput(true);

        $errors = 0;
        $warnings = 0;

        foreach ($this->failedPaths as $path) {
            if ($path['type'] == 'error') {
                $errors++;
            } else {
                $warnings++;
            }
        }

        $this->build->storeMeta('phptallint-warnings', $warnings);
        $this->build->storeMeta('phptallint-errors', $errors);
        $this->build->storeMeta('phptallint-data', $this->failedPaths);

        if ($this->allowed_warnings != -1 && $warnings > $this->allowed_warnings) {
            $success = false;
        }

        if ($this->allowed_errors != -1 && $errors > $this->allowed_errors) {
            $success = false;
        }

        return $success;
    }

    /**
     * Lint an item (file or directory) by calling the appropriate method.
     * @param $php
     * @param $item
     * @param $itemPath
     * @return bool
     */
    protected function lintItem($item, $itemPath)
    {
        $success = true;

        if ($item->isFile() && in_array(strtolower($item->getExtension()), $this->suffixes) && !$this->lintFile($itemPath)) {
            $success = false;
        } elseif ($item->isDir() && $this->recursive && !$this->lintDirectory($itemPath . '/')) {
            $success = false;
        }

        return $success;
    }

    protected function lintDirectory($path)
    {
        $success = true;
        $directory = new \DirectoryIterator($this->phpci->buildPath . $path);

        foreach ($directory as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $path . $item->getFilename();

            if (in_array($itemPath, $this->ignore)) {
                continue;
            }

            if (!$this->lintItem($item, $itemPath)) {
                $success = false;
            }
        }

        return $success;
    }

    protected function lintFile($path)
    {
        $success = true;

        list($suffixes, $tales) = $this->getFlags();

        // FIXME: Find a way to clean this up
        $lint = dirname(__FILE__) . '/../../vendor/phptal/phptal/tools/phptal_lint.php';
        $cmd = '/usr/bin/env php ' . $lint . ' %s %s "%s"';

        $this->phpci->executeCommand($cmd, $suffixes, $tales, $this->phpci->buildPath . $path);

        $output = $this->phpci->getLastOutput();

        // FIXME: This is very messy, clean it up
        if (preg_match('/Found (.+?) (error|warning)/i', $output, $matches)) {

            $rows = explode(PHP_EOL, $output);

            unset($rows[0]);
            unset($rows[1]);
            unset($rows[2]);
            unset($rows[3]);

            foreach ($rows as $row) {
                $name = basename($path);

                $message = str_replace('(use -i to include your custom modifier functions)', '', str_replace($name . ': ', '', $row));
                $parts = explode(' (line ', $message);
                
                $message = trim($parts[0]);
                $line = str_replace(')', '', $parts[1]);

                $this->failedPaths[] = array(
                    'file' => $path,
                    'line' => $line,
                    'type' => $matches[2],
                    'message' => $message
                );
            }

            $success = false;
        }

        return $success;
    }

    protected function getFlags() {
        $tales = '';
        if (!empty($this->tales)) {
            $tales = ' -i ' . $this->phpci->buildPath . $this->tales;
        }

        $suffixes = '';
        if (count($this->suffixes)) {
            $suffixes = ' -e ' . implode(',', $this->suffixes);
        }

        return array($suffixes, $tales);
    }
}
