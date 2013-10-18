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
use b8\Form;
use b8\HttpClient;
use PHPCI\Controller;
use PHPCI\Model\Build;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

/**
 * Settings Controller
 * @author       Dan Cryer <dan@block8.co.uk>
 * @package      PHPCI
 * @subpackage   Web
 */
class SettingsController extends Controller
{
    protected $settings;

    public function init()
    {
        parent::init();

        $parser = new Parser();
        $yaml = file_get_contents(APPLICATION_PATH . 'PHPCI/config.yml');
        $this->settings = $parser->parse($yaml);
    }

    public function index()
    {
        $this->view->settings = $this->settings;
        $this->view->github = $this->getGithubForm();

        if (!empty($this->settings['phpci']['github']['token'])) {
            $this->view->githubUser = $this->getGithubUser($this->settings['phpci']['github']['token']);
        }

        return $this->view->render();
    }

    public function github()
    {
        $this->settings['phpci']['github']['id'] = $this->getParam('githubid', '');
        $this->settings['phpci']['github']['secret'] = $this->getParam('githubsecret', '');

        $this->storeSettings();

        header('Location: ' . PHPCI_URL . 'settings?saved=1');
        die;
    }

    /**
     * Github redirects users back to this URL when t
     */
    public function githubCallback()
    {
        $code = $this->getParam('code', null);
        $github = $this->settings['phpci']['github'];

        if (!is_null($code)) {
            $http = new HttpClient();
            $url  = 'https://github.com/login/oauth/access_token';
            $params = array('client_id' => $github['id'], 'client_secret' => $github['secret'], 'code' => $code);
            $resp = $http->post($url, $params);

            if ($resp['success']) {
                parse_str($resp['body'], $resp);

                $this->settings['phpci']['github']['token'] = $resp['access_token'];
                $this->storeSettings();

                header('Location: ' . PHPCI_URL . 'settings?linked=1');
                die;
            }
        }


        header('Location: ' . PHPCI_URL . 'settings?linked=2');
        die;
    }

    protected function storeSettings()
    {
        $dumper = new Dumper();
        $yaml = $dumper->dump($this->settings);
        file_put_contents(APPLICATION_PATH . 'PHPCI/config.yml', $yaml);
    }

    protected function getGithubForm()
    {
        $form = new Form();
        $form->setMethod('POST');
        $form->setAction(PHPCI_URL . 'settings/github');
        $form->addField(new Form\Element\Csrf('csrf'));

        $field = new Form\Element\Text('githubid');
        $field->setRequired(true);
        $field->setPattern('[a-zA-Z0-9]+');
        $field->setLabel('Application ID');
        $field->setClass('form-control');
        $field->setContainerClass('form-group');
        $field->setValue($this->settings['phpci']['github']['id']);
        $form->addField($field);

        $field = new Form\Element\Text('githubsecret');
        $field->setRequired(true);
        $field->setPattern('[a-zA-Z0-9]+');
        $field->setLabel('Application Secret');
        $field->setClass('form-control');
        $field->setContainerClass('form-group');
        $field->setValue($this->settings['phpci']['github']['secret']);
        $form->addField($field);


        $field = new Form\Element\Submit();
        $field->setValue('Save &raquo;');
        $field->setClass('btn btn-success pull-right');
        $form->addField($field);

        return $form;
    }

    protected function getGithubUser($token)
    {
        $http = new HttpClient('https://api.github.com');
        $user = $http->get('/user', array('access_token' => $token));

        return $user['body'];
    }
}
