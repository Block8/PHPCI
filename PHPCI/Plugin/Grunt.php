<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Plugin;

/**
* Grunt Plugin - Provides access to grunt functionality.
* @author       Tobias Tom <t.tom@succont.de>
* @package      PHPCI
* @subpackage   Plugins
*/
class Grunt extends AbstractPlugin
{
    protected $directory;
    protected $task;
    protected $preferDist;
    protected $grunt;
    protected $gruntfile;

    /**
     * Configure the plugin.
     *
     * @param array $options
     */
    protected function setOptions(array $options)
    {
        $this->directory = $this->buildPath;
        $this->task = null;
        $this->grunt = $this->phpci->findBinary('grunt');
        $this->gruntfile = 'Gruntfile.js';

        // Handle options:
        if (isset($options['directory'])) {
            $this->directory = $this->buildPath . '/' . $options['directory'];
        }

        if (isset($options['task'])) {
            $this->task = $options['task'];
        }

        if (isset($options['grunt'])) {
            $this->grunt = $options['grunt'];
        }

        if (isset($options['gruntfile'])) {
            $this->gruntfile = $options['gruntfile'];
        }
    }

    /**
    * Executes grunt and runs a specified command (e.g. install / update)
    */
    public function execute()
    {
        // if npm does not work, we cannot use grunt, so we return false
        $cmd = 'cd %s && npm install';
        if (IS_WIN) {
            $cmd = 'cd /d %s && npm install';
        }
        if (!$this->phpci->executeCommand($cmd, $this->directory)) {
            return false;
        }

        // build the grunt command
        $cmd = 'cd %s && ' . $this->grunt;
        if (IS_WIN) {
            $cmd = 'cd /d %s && ' . $this->grunt;
        }
        $cmd .= ' --no-color';
        $cmd .= ' --gruntfile %s';
        $cmd .= ' %s'; // the task that will be executed

        // and execute it
        return $this->phpci->executeCommand($cmd, $this->directory, $this->gruntfile, $this->task);
    }
}
