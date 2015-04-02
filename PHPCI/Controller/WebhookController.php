<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014-2015, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Controller;

use b8;
use b8\Store;
use PHPCI\BuildFactory;
use PHPCI\Service\BuildService;

/**
 * Webhook Controller - Processes webhook pings from BitBucket, Github, Gitlab, etc.
 *
 * @author       Dan Cryer <dan@block8.co.uk>
 * @author       Sami Tikka <stikka@iki.fi>
 * @author       Alex Russell <alex@clevercherry.com>
 * @author       Guillaume Perréal <adirelle@gmail.com>
 * @package      PHPCI
 * @subpackage   Web
 */
class WebhookController extends \b8\Controller
{
    /**
     * @var \PHPCI\Store\BuildStore
     */
    protected $buildStore;

    /**
     * @var \PHPCI\Store\ProjectStore
     */
    protected $projectStore;

    /**
     * @var \PHPCI\Service\BuildService
     */
    protected $buildService;

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init()
    {
        $this->buildStore = Store\Factory::getStore('Build');
        $this->projectStore = Store\Factory::getStore('Project');
        $this->buildService = new BuildService($this->buildStore);
    }

    /** Handle the action, Ensuring to return a JsonResponse.
     *
     * @param string $action
     * @param mixed $actionParams
     *
     * @return \b8\Http\Response
     */
    public function handleAction($action, $actionParams)
    {
        $response = new b8\Http\Response\JsonResponse();
        try {
            $data = parent::handleAction($action, $actionParams);
            if (isset($data['responseCode'])) {
                $response->setResponseCode($data['responseCode']);
                unset($data['responseCode']);
            }
            $response->setContent($data);
        } catch (\Exception $ex) {
            $response->setResponseCode(500);
            $response->setContent(array('status' => 'failed', 'error' => $ex->getMessage()));
        }
        return $response;
    }

    /**
     * Called by Bitbucket POST service.
     */
    public function bitbucket($project)
    {
        $payload = json_decode($this->getParam('payload'), true);

        $results = array();
        foreach ($payload['commits'] as $commit) {
            $email = $commit['raw_author'];
            $email = substr($email, 0, strpos($email, '>'));
            $email = substr($email, strpos($email, '<') + 1);

            $results[$commit['raw_node']] = $this->createBuild(
                $project,
                $commit['raw_node'],
                $commit['branch'],
                $email,
                $commit['message']
            );
        }

        return array('status' => 'ok', 'commits' => $results);
    }

    /**
     * Called by POSTing to /webhook/git/<project_id>?branch=<branch>&commit=<commit>
     *
     * @param string $project
     */
    public function git($project)
    {
        $branch = $this->getParam('branch', 'master');
        $commit = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer = $this->getParam('committer');

        return $this->createBuild($project, $commit, $branch, $committer, $commitMessage);
    }

    /**
     * Called by Github Webhooks:
     */
    public function github($project)
    {
        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $payload = json_decode(file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                $payload = json_decode($this->getParam('payload'), true);
                break;
            default:
                return array('status' => 'failed', 'error' => 'Content type not supported.', 'responseCode' => 401);
        }

        // Handle Pull Request web hooks:
        if (array_key_exists('pull_request', $payload)) {
            return $this->githubPullRequest($project, $payload);
        }

        // Handle Push web hooks:
        if (array_key_exists('commits', $payload)) {
            return $this->githubCommitRequest($project, $payload);
        }

        return array('status' => 'ignored', 'message' => 'Unusable payload.');
    }

    /**
     * Handle the payload when Github sends a commit webhook.
     *
     * @param $project
     * @param array $payload
     * @param b8\Http\Response\JsonResponse $response
     *
     * @return b8\Http\Response\JsonResponse
     */
    protected function githubCommitRequest($project, array $payload)
    {
        // Github sends a payload when you close a pull request with a
        // non-existant commit. We don't want this.
        if (array_key_exists('after', $payload) && $payload['after'] === '0000000000000000000000000000000000000000') {
            return array('status' => 'ignored');
        }

        if (isset($payload['commits']) && is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = array();
            foreach ($payload['commits'] as $commit) {
                if (!$commit['distinct']) {
                    $results[$commit['id']] = array('status' => 'ignored');
                    continue;
                }

                $branch = str_replace('refs/heads/', '', $payload['ref']);
                $committer = $commit['committer']['email'];
                $results[$commit['id']] = $this->createBuild(
                    $project,
                    $commit['id'],
                    $branch,
                    $committer,
                    $commit['message']
                );
            }
            return array('status' => 'ok', 'commits' => $results);
        }

        if (substr($payload['ref'], 0, 10) == 'refs/tags/') {
            // If we don't, but we're dealing with a tag, add that instead:
            $branch = str_replace('refs/tags/', 'Tag: ', $payload['ref']);
            $committer = $payload['pusher']['email'];
            $message = $payload['head_commit']['message'];
            return $this->createBuild($project, $payload['after'], $branch, $committer, $message);
        }

        return array('status' => 'ignored', 'message' => 'Unusable payload.');
    }

    /**
     * Handle the payload when Github sends a Pull Request webhook.
     *
     * @param $projectId
     * @param array $payload
     */
    protected function githubPullRequest($projectId, array $payload)
    {
        // We only want to know about open pull requests:
        if (!in_array($payload['action'], array('opened', 'synchronize', 'reopened'))) {
            return array('status' => 'ok');
        }

        $headers = array();
        $token = \b8\Config::getInstance()->get('phpci.github.token');

        if (!empty($token)) {
            $headers[] = 'Authorization: token ' . $token;
        }

        $url    = $payload['pull_request']['commits_url'];
        $http   = new \b8\HttpClient();
        $http->setHeaders($headers);
        $response = $http->get($url);

        // Check we got a success response:
        if (!$response['success']) {
            throw new Exception('Could not get commits, failed API request.');
        }

        $results = array();
        foreach ($response['body'] as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['sha'];
            if ($id != $payload['pull_request']['head']['sha']) {
                $results[$id] = array('status' => 'ignored', 'message' => 'not branch head');
                continue;
            }

            $branch = str_replace('refs/heads/', '', $payload['pull_request']['base']['ref']);
            $committer = $commit['commit']['author']['email'];
            $message = $commit['commit']['message'];

            $extra = array(
                'build_type' => 'pull_request',
                'pull_request_id' => $payload['pull_request']['id'],
                'pull_request_number' => $payload['number'],
                'remote_branch' => $payload['pull_request']['head']['ref'],
                'remote_url' => $payload['pull_request']['head']['repo']['clone_url'],
            );

            $results[$id] = $this->createBuild($projectId, $id, $branch, $committer, $message, $extra);
        }

        return array('status' => 'ok', 'commits' => $results);
    }

    /**
     * Called by Gitlab Webhooks:
     */
    public function gitlab($project)
    {
        $payloadString = file_get_contents("php://input");
        $payload = json_decode($payloadString, true);

        // build on merge request events
        if (isset($payload['object_kind']) && $payload['object_kind'] == 'merge_request') {
            $attributes = $payload['object_attributes'];
            if ($attributes['state'] == 'opened' || $attributes['state'] == 'reopened') {
                $branch = $attributes['source_branch'];
                $commit = $attributes['last_commit'];
                $committer = $commit['author']['email'];

                return $this->createBuild($project, $commit['id'], $branch, $committer, $commit['message']);
            }
        }

        // build on push events
        if (isset($payload['commits']) && is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = array();
            foreach ($payload['commits'] as $commit) {
                $branch = str_replace('refs/heads/', '', $payload['ref']);
                $committer = $commit['author']['email'];
                $results[$commit['id']] =
                    $this->createBuild($project, $commit['id'], $branch, $committer, $commit['message']);
            }
            return array('status' => 'ok', 'commits' => $results);
        }

        return array('status' => 'ignored', 'message' => 'Unusable payload.');
    }

    /**
     * Wrapper for creating a new build.
     *
     * @param int $projectId
     * @param string $commitId
     * @param string $branch
     * @param string $committer
     * @param string $commitMessage
     * @param array $extra
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function createBuild($projectId, $commitId, $branch, $committer, $commitMessage, array $extra = null)
    {
        // Check if a build already exists for this commit ID:
        $builds = $this->buildStore->getByProjectAndCommit($projectId, $commitId);

        if ($builds['count']) {
            return array(
                'status' => 'ignored',
                'message' => sprintf('Duplicate of build #%d', $builds['items'][0]->getId())
            );
        }

        $project = $this->projectStore->getById($projectId);

        if (empty($project)) {
            throw new \Exception('Project does not exist:' . $projectId);
        }

        // If not, create a new build job for it:
        $build = $this->buildService->createBuild($project, $commitId, $branch, $committer, $commitMessage, $extra);
        $build = BuildFactory::getBuild($build);

        // Send a status postback if the build type provides one:
        $build->sendStatusPostback();

        return array('status' => 'ok', 'buildID' => $build->getID());
    }
}
