<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2015, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Command;

use PHPCI\Config;
use Monolog\Logger;
use PHPCI\BuildFactory;
use PHPCI\Helper\Lang;
use PHPCI\Logging\OutputLogHandler;
use PHPCI\Service\BuildService;
use PHPCI\Store\BuildStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Console
*/
class RebuildQueueCommand extends Command
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param \Monolog\Logger $logger
     * @param string $name
     */
    public function __construct(Logger $logger, $name = null)
    {
        parent::__construct($name);
        $this->logger = $logger;
    }

    protected function configure()
    {
        $this
            ->setName('phpci:rebuild-queue')
            ->setDescription('Rebuilds the PHPCI worker queue.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        // For verbose mode we want to output all informational and above
        // messages to the symphony output interface.
        if ($input->hasOption('verbose') && $input->getOption('verbose')) {
            $this->logger->pushHandler(
                new OutputLogHandler($this->output, Logger::INFO)
            );
        }

        $store = BuildStore::load();
        $result = $store->getByStatus(0);

        $this->logger->addInfo(Lang::get('found_n_builds', $result->count));

        $buildService = new BuildService($store);

        foreach ($result as $build) {
            $this->logger->addInfo('Added build #' . $build->getId() . ' to queue.');
            $buildService->addBuildToQueue(BuildFactory::getBuild($build));
        }
    }
}
