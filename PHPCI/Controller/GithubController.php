<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

namespace PHPCI\Controller;

use b8;
use b8\Store;
use PHPCI\Model\Build;

/**
* Github Controller - Processes webhook pings from Github.
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Web
*/
class GithubController extends \PHPCI\Controller
{
    /**
     * @var \PHPCI\Store\BuildStore
     */
    protected $buildStore;

    public function init()
    {
        $this->buildStore = Store\Factory::getStore('Build');
    }

    /**
    * Called by Github Webhooks:
    */
    public function webhook($project)
    {
        $payload    = json_decode($this->getParam('payload'), true);

        // Github sends a payload when you close a pull request with a
        // non-existant commit. We don't want this.
        if ($payload['after'] === '0000000000000000000000000000000000000000') {
            die('OK');
        }

        try {
            $build      = new Build();
            $build->setProjectId($project);
            $build->setCommitId($payload['after']);
            $build->setStatus(Build::STATUS_NEW);
            $build->setLog('');
            $build->setCreated(new \DateTime());
            $build->setBranch(str_replace('refs/heads/', '', $payload['ref']));

            if (!empty($payload['pusher']['email'])) {
                $build->setCommitterEmail($payload['pusher']['email']);
            }

        } catch (\Exception $ex) {
            header('HTTP/1.1 400 Bad Request');
            header('Ex: ' . $ex->getMessage());
            die('FAIL');
        }

        try {
            $build = $this->buildStore->save($build);
            $build->sendStatusPostback();
        } catch (\Exception $ex) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Ex: ' . $ex->getMessage());
            die('FAIL');
        }
        
        die('OK');
    }
}
