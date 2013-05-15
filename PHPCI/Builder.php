<?php

namespace PHPCI;
use PHPCI\Model\Build;
use b8\Store;
use Symfony\Component\Yaml\Parser as YamlParser;

class Builder
{
	public $buildPath;
	public $ignore	= array();

	protected $ciDir;
	protected $directory;
	protected $success	= true;
	protected $log		= '';
	protected $verbose	= false;
	protected $plugins	= array();
	protected $build;
	protected $logCallback;

	public function __construct(Build $build, $logCallback = null)
	{
		$this->build = $build;
		$this->store = Store\Factory::getStore('Build');

		if(!is_null($logCallback) && is_callable($logCallback))
		{
			$this->logCallback = $logCallback;
		}
	}

	public function execute()
	{
		$this->build->setStatus(1);
		$this->build->setStarted(new \DateTime());
		$this->build = $this->store->save($this->build);
		$this->build->sendStatusPostback();

		if($this->setupBuild())
		{
			$this->executePlugins('setup');
			$this->executePlugins('test');

			$this->log('');

			$this->executePlugins('complete');

			if($this->success)
			{
				$this->executePlugins('success');
				$this->logSuccess('BUILD SUCCESSFUL!');
				$this->build->setStatus(2);
			}
			else
			{
				$this->executePlugins('failure');
				$this->logFailure('BUILD FAILED!');
				$this->build->setStatus(3);
			}

			$this->log('');
		}
		else
		{
			$this->build->setStatus(3);
		}

		$this->removeBuild();

		$this->build->sendStatusPostback();
		$this->build->setFinished(new \DateTime());
		$this->build->setLog($this->log);
		$this->build->setPlugins(json_encode($this->plugins));
		$this->store->save($this->build);
	}

	public function executeCommand($command)
	{
		$this->log('Executing: ' . $command, '	');

		$output	= '';
		$status	= 0;
		exec($command, $output, $status);

		if(!empty($output) && ($this->verbose || $status != 0))
		{
			$this->log($output, '		');
		}

		return ($status == 0) ? true : false;
	}

	protected function log($message, $prefix = '')
	{

		if(is_array($message))
		{
			$cb = $this->logCallback;

			$message = array_map(function($item) use ($cb, $prefix)
			{
				if(is_callable($cb))
				{
					$cb($prefix . $item);
				}

				$this->log .= $prefix . $item . PHP_EOL;
			}, $message);
		}
		else
		{
			$message = $prefix . $message;

			$this->log .= $message . PHP_EOL;

			if(isset($this->logCallback) && is_callable($this->logCallback))
			{
				$cb = $this->logCallback;
				$cb($message);
			}
		}

		$this->build->setLog($this->log);
		$this->build->setPlugins(json_encode($this->plugins));
		$this->build = $this->store->save($this->build);
	}

	protected function logSuccess($message)
	{
		$this->log("\033[0;32m" . $message . "\033[0m");
	}

	protected function logFailure($message)
	{
		$this->log("\033[0;31m" . $message . "\033[0m");
	}

	protected function setupBuild()
	{
		$commitId			= $this->build->getCommitId();
		$url				= $this->build->getProject()->getGitUrl();
		$key				= $this->build->getProject()->getGitKey();
		$type				= $this->build->getProject()->getType();
		$reference			= $this->build->getProject()->getReference();
		$reference			= substr($reference, -1) == '/' ? substr($reference, 0, -1) : $reference;
		$buildId			= 'project' . $this->build->getProject()->getId() . '-build' . $this->build->getId();
		$yamlParser			= new YamlParser();

		$this->ciDir		= realpath(dirname(__FILE__) . '/../') . '/';
		$this->buildPath	= $this->ciDir . 'build/' . $buildId . '/';

		switch ($type)
		{
			case 'local':
				$this->buildPath = substr($this->buildPath, 0, -1);

				if(!is_file($reference . '/phpci.yml'))
				{
					$this->logFailure('Project does not contain a phpci.yml file.');
					return false;
				}

				$yamlFile = file_get_contents($reference . '/phpci.yml');
				$this->config = $yamlParser->parse($yamlFile);

				if(array_key_exists('build_settings', $this->config)
					&& is_array($this->config['build_settings'])
					&& array_key_exists('prefer_symlink', $this->config['build_settings'])
					&& true === $this->config['build_settings']['prefer_symlink'])
				{
					if(is_link($this->buildPath) && is_file($this->buildPath))
					{
						unlink($this->buildPath);
					}

					$this->log(sprintf('Symlinking: %s to %s',$reference, $this->buildPath));
					if ( !symlink($reference, $this->buildPath) )
					{
						$this->logFailure('Failed to symlink.');
						return false;
					}
				}
				else
				{
					$this->executeCommand(sprintf("cp -Rf %s %s/", $reference, $this->buildPath));
				}

				$this->buildPath .= '/';
			break;

			case 'github':
			case 'bitbucket':
				mkdir($this->buildPath, 0777, true);

				if(!empty($key))
				{
					// Do an SSH clone:
					$keyFile			= $this->ciDir . 'build/' . $buildId . '.key';
					file_put_contents($keyFile, $key);
					chmod($keyFile, 0600);
					$this->executeCommand('ssh-agent ssh-add '.$keyFile.' && git clone -b ' .$this->build->getBranch() . ' ' .$url.' '.$this->buildPath.' && ssh-agent -k');
					unlink($keyFile);
				}
				else
				{
					// Do an HTTP clone:
					$this->executeCommand('git clone -b ' .$this->build->getBranch() . ' ' .$url.' '.$this->buildPath);
				}

				if(!is_file($this->buildPath . 'phpci.yml'))
				{
					$this->logFailure('Project does not contain a phpci.yml file.');
					return false;
				}

				$yamlFile = file_get_contents($this->buildPath . 'phpci.yml');
				$this->config = $yamlParser->parse($yamlFile);
			break;
		}

		if(!isset($this->config['build_settings']['verbose']) || !$this->config['build_settings']['verbose'])
		{
			$this->verbose = false;
		}
		else
		{
			$this->verbose = true;
		}

		if(isset($this->config['build_settings']['ignore']))
		{
			$this->ignore = $this->config['build_settings']['ignore'];
		}

		$this->log('Set up build: ' . $this->buildPath);

		return true;
	}

	protected function removeBuild()
	{
		$this->log('Removing build.');
		shell_exec('rm -Rf ' . $this->buildPath);
	}

	protected function executePlugins($stage)
	{
		// Ignore any stages for which we don't have plugins set:
		if(!array_key_exists($stage, $this->config) || !is_array($this->config[$stage]))
		{
			return;
		}

		foreach($this->config[$stage] as $plugin => $options)
		{
			$this->log('');
			$this->log('RUNNING PLUGIN: ' . $plugin);

			// Is this plugin allowed to fail?
			if($stage == 'test' && !isset($options['allow_failures']))
			{
				$options['allow_failures'] = false;
			}

			$class = str_replace('_', ' ', $plugin);
			$class = ucwords($class);
			$class = 'PHPCI\\Plugin\\' . str_replace(' ', '', $class);

			if(!class_exists($class))
			{
				$this->logFailure('Plugin does not exist: ' . $plugin);

				if($stage == 'test')
				{
					$this->plugins[$plugin] = false;

					if(!$options['allow_failures'])
					{
						$this->success = false;
					}
				}

				continue;
			}

			try
			{
				$obj = new $class($this, $options);

				if(!$obj->execute())
				{
					if($stage == 'test')
					{
						$this->plugins[$plugin] = false;

						if(!$options['allow_failures'])
						{
							$this->success = false;
						}
					}

					$this->logFailure('PLUGIN STATUS: FAILED');
					continue;
				}
			}
			catch(\Exception $ex)
			{
				$this->logFailure('EXCEPTION: ' . $ex->getMessage());

				if($stage == 'test')
				{
					$this->plugins[$plugin] = false;

					if(!$options['allow_failures'])
					{
						$this->success = false;
					}
				}

				$this->logFailure('PLUGIN STATUS: FAILED');
				continue;
			}

			if($stage == 'test')
			{
				$this->plugins[$plugin] = true;
			}

			$this->logSuccess('PLUGIN STATUS: SUCCESS!');
		}
	}
}