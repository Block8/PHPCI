<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

namespace PHPCI;

use PHPCI\Model\Build;
use b8\Store;
use b8\Config;

/**
* PHPCI Build Runner
* @author   Dan Cryer <dan@block8.co.uk>
*/
class Builder
{
    /**
    * @var string
    */
    public $buildPath;

    /**
    * @var string[]
    */
    public $ignore  = array();

    /**
    * @var string
    */
    protected $ciDir;

    /**
    * @var string
    */
    protected $directory;

    /**
    * @var bool
    */
    protected $success  = true;

    /**
    * @var string
    */
    protected $log      = '';

    /**
    * @var bool
    */
    protected $verbose  = true;

    /**
    * @var \PHPCI\Model\Build
    */
    protected $build;

    /**
    * @var callable
    */
    protected $logCallback;

    /**
    * @var array
    */
    protected $config;

    /**
     * @var string
     */
    protected $lastOutput;

    /**
     * An array of key => value pairs that will be used for 
     * interpolation and environment variables
     * @var array
     * @see setInterpolationVars()
     */
    protected $interpolation_vars = array();

    /**
     * @var \PHPCI\Store\BuildStore
     */
    protected $store;

    /**
     * @var bool
     */
    public $quiet = false;

    /**
    * Set up the builder.
    * @param \PHPCI\Model\Build
    * @param callable
    */
    public function __construct(Build $build, \Closure $logCallback)
    {
        $this->build = $build;
        $this->store = Store\Factory::getStore('Build');
        $this->logCallback = $logCallback;
    }

    /**
    * Set the config array, as read from phpci.yml
    * @param array
    */
    public function setConfigArray(array $config)
    {
        $this->config = $config;
    }

    /**
    * Access a variable from the phpci.yml file.
    * @param string
    */
    public function getConfig($key)
    {
        $rtn = null;

        if (isset($this->config[$key])) {
            $rtn = $this->config[$key];
        }

        return $rtn;
    }

    /**
     * Access a variable from the config.yml
     * @param $key
     * @return mixed
     */
    public function getSystemConfig($key)
    {
        return Config::getInstance()->get($key);
    }

    /**
     * @return string   The title of the project being built.
     */
    public function getBuildProjectTitle()
    {
        return $this->build->getProject()->getTitle();
    }

    /**
    * Run the active build.
    */
    public function execute()
    {
        // Update the build in the database, ping any external services.
        $this->build->setStatus(1);
        $this->build->setStarted(new \DateTime());
        $this->store->save($this->build);
        $this->build->sendStatusPostback();

        try {
            // Set up the build:
            $this->setupBuild();

            // Run the core plugin stages:
            foreach (array('setup', 'test', 'complete') as $stage) {
                $this->executePlugins($stage);
                $this->log('');
            }

            // Failed build? Execute failure plugins and then mark the build as failed.
            if (!$this->success) {
                $this->executePlugins('failure');
                throw new \Exception('BUILD FAILED!');
            }

            // If we got this far, the build was successful!
            if ($this->success) {
                $this->build->setStatus(2);
                $this->executePlugins('success');
                $this->logSuccess('BUILD SUCCESSFUL!');
            }

        } catch (\Exception $ex) {
            $this->logFailure($ex->getMessage());
            $this->build->setStatus(3);
        }
        
        // Clean up:
        $this->log('Removing build.');
        shell_exec(sprintf('rm -Rf "%s"', $this->buildPath));

        // Update the build in the database, ping any external services, etc.
        $this->build->sendStatusPostback();
        $this->build->setFinished(new \DateTime());
        $this->build->setLog($this->log);
        $this->store->save($this->build);
    }

    /**
    * Used by this class, and plugins, to execute shell commands. 
    */
    public function executeCommand()
    {
        $command = call_user_func_array('sprintf', func_get_args());

        if (!$this->quiet) {
            $this->log('Executing: ' . $command, '  ');
        }

        $status = 0;
        exec($command, $this->lastOutput, $status);

        if (!empty($this->lastOutput) && ($this->verbose || $status != 0)) {
            $this->log($this->lastOutput, '       ');
        }


        $rtn = false;

        if ($status == 0) {
            $rtn = true;
        }

        return $rtn;
    }

    /**
     * Returns the output from the last command run.
     */
    public function getLastOutput()
    {
        return implode(PHP_EOL, $this->lastOutput);
    }

    /**
    * Add an entry to the build log. 
    * @param string|string[]
    * @param string
    */
    public function log($message, $prefix = '')
    {
        if (!is_array($message)) {
            $message = array($message);
        }

        foreach ($message as $item) {
            call_user_func_array($this->logCallback, array($prefix . $item));
            $this->log .= $prefix . $item . PHP_EOL;
        }

        $this->build->setLog($this->log);
        $this->store->save($this->build);
    }

    /**
    * Add a success-coloured message to the log. 
    * @param string
    */
    public function logSuccess($message)
    {
        $this->log("\033[0;32m" . $message . "\033[0m");
    }

    /**
    * Add a failure-coloured message to the log. 
    * @param string
    */
    public function logFailure($message)
    {
        $this->log("\033[0;31m" . $message . "\033[0m");
    }
    
    /**
     * Replace every occurance of the interpolation vars in the given string
     * Example: "This is build %PHPCI_BUILD%" => "This is build 182"
     * @param string $input
     * @return string
     */
    public function interpolate($input)
    {
        $keys = array_keys($this->interpolation_vars);
        $values = array_values($this->interpolation_vars);
        return str_replace($keys, $values, $input);
    }

    /**
     * Sets the variables that will be used for interpolation. This must be run 
     * from setupBuild() because prior to that, we don't know the buildPath
     */
    protected function setInterpolationVars()
    {
        $this->interpolation_vars = array();
        $this->interpolation_vars['%PHPCI%'] = 1;
        $this->interpolation_vars['%COMMIT%'] = $this->build->getCommitId();
        $this->interpolation_vars['%PROJECT%'] = $this->build->getProjectId();
        $this->interpolation_vars['%BUILD%'] = $this->build->getId();
        $this->interpolation_vars['%PROJECT_TITLE%'] = $this->getBuildProjectTitle();
        $this->interpolation_vars['%BUILD_PATH%'] = $this->buildPath;
        $this->interpolation_vars['%BUILD_URI%'] = PHPCI_URL . "build/view/" . $this->build->getId();
        $this->interpolation_vars['%PHPCI_COMMIT%'] = $this->interpolation_vars['%COMMIT%'];
        $this->interpolation_vars['%PHPCI_PROJECT%'] = $this->interpolation_vars['%PROJECT%'];
        $this->interpolation_vars['%PHPCI_BUILD%'] = $this->interpolation_vars['%BUILD%'];
        $this->interpolation_vars['%PHPCI_PROJECT_TITLE%'] = $this->interpolation_vars['%PROJECT_TITLE%'];
        $this->interpolation_vars['%PHPCI_BUILD_PATH%'] = $this->interpolation_vars['%BUILD_PATH%'];
        $this->interpolation_vars['%PHPCI_BUILD_URI%'] = $this->interpolation_vars['%BUILD_URI%'];

        putenv('PHPCI=1');
        putenv('PHPCI_COMMIT='.$this->interpolation_vars['%COMMIT%']);
        putenv('PHPCI_PROJECT='.$this->interpolation_vars['%PROJECT%']);
        putenv('PHPCI_BUILD='.$this->interpolation_vars['%BUILD%']);
        putenv('PHPCI_PROJECT_TITLE='.$this->interpolation_vars['%PROJECT_TITLE%']);
        putenv('PHPCI_BUILD_PATH='.$this->interpolation_vars['%BUILD_PATH%']);
        putenv('PHPCI_BUILD_URI='.$this->interpolation_vars['%BUILD_URI%']);
    }
    
    /**
    * Set up a working copy of the project for building.
    */
    protected function setupBuild()
    {
        $buildId            = 'project' . $this->build->getProject()->getId() . '-build' . $this->build->getId();
        $this->ciDir        = dirname(__FILE__) . '/../';
        $this->buildPath    = $this->ciDir . 'build/' . $buildId . '/';
        
        $this->setInterpolationVars();
        
        // Create a working copy of the project:
        if (!$this->build->createWorkingCopy($this, $this->buildPath)) {
            throw new \Exception('Could not create a working copy.');
        }

        // Does the project's phpci.yml request verbose mode?
        if (!isset($this->config['build_settings']['verbose']) || !$this->config['build_settings']['verbose']) {
            $this->verbose = false;
        }

        // Does the project have any paths it wants plugins to ignore?
        if (isset($this->config['build_settings']['ignore'])) {
            $this->ignore = $this->config['build_settings']['ignore'];
        }

        $this->logSuccess('Working copy created: ' . $this->buildPath);
        return true;
    }

    /**
    * Execute a the appropriate set of plugins for a given build stage.
    */
    protected function executePlugins($stage)
    {
        // Ignore any stages for which we don't have plugins set:
        if (!array_key_exists($stage, $this->config) || !is_array($this->config[$stage])) {
            return;
        }

        foreach ($this->config[$stage] as $plugin => $options) {
            $this->log('');
            $this->log('RUNNING PLUGIN: ' . $plugin);

            // Is this plugin allowed to fail?
            if ($stage == 'test' && !isset($options['allow_failures'])) {
                $options['allow_failures'] = false;
            }

            // Try and execute it:
            if ($this->executePlugin($plugin, $options)) {

                // Execution was successful:
                $this->logSuccess('PLUGIN STATUS: SUCCESS!');

            } else {

                // If we're in the "test" stage and the plugin is not allowed to fail,
                // then mark the build as failed:
                if ($stage == 'test' && !$options['allow_failures']) {
                    $this->success = false;
                }

                $this->logFailure('PLUGIN STATUS: FAILED');
            }
        }
    }

    /**
     * Executes a given plugin, with options and returns the result.
     */
    protected function executePlugin($plugin, $options)
    {
        // Figure out the class name and check the plugin exists:
        $class = str_replace('_', ' ', $plugin);
        $class = ucwords($class);
        $class = 'PHPCI\\Plugin\\' . str_replace(' ', '', $class);

        if (!class_exists($class)) {
            $this->logFailure('Plugin does not exist: ' . $plugin);
            return false;
        }

        $rtn = true;

        // Try running it:
        try {
            $obj = new $class($this, $this->build, $options);

            if (!$obj->execute()) {
                $rtn = false;
            }
        } catch (\Exception $ex) {
            $this->logFailure('EXCEPTION: ' . $ex->getMessage());
            $rtn = false;
        }

        return $rtn;
    }

    /**
     * Find a binary required by a plugin.
     * @param $binary
     * @return null|string
     */
    public function findBinary($binary)
    {
        if (is_string($binary)) {
            $binary = array($binary);
        }

        foreach ($binary as $bin) {
            // Check project root directory:
            if (is_file(PHPCI_DIR . $bin)) {
                return PHPCI_DIR . $bin;
            }

            // Check Composer bin dir:
            if (is_file(PHPCI_DIR . 'vendor/bin/' . $bin)) {
                return PHPCI_DIR . 'vendor/bin/' . $bin;
            }

            // Use "which"
            $which = trim(shell_exec('which ' . $bin));

            if (!empty($which)) {
                return $which;
            }
        }

        return null;
    }
}
