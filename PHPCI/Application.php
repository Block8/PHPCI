<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2014, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         https://www.phptesting.org/
*/

namespace PHPCI;

use b8;
use b8\Exception\HttpException;
use b8\Http\Request;
use b8\Http\Response;
use b8\Http\Response\RedirectResponse;
use b8\Http\Router;
use b8\View;
use PHPCI\Store\UserStore;
use PHPCI\Store\ProjectStore;
use Symfony\Component\DependencyInjection\Container;

/**
* PHPCI Front Controller
* @author   Dan Cryer <dan@block8.co.uk>
*/
class Application extends b8\Application
{
    /**
     * @var \PHPCI\Controller
     */
    protected $controller;

    /**
     * @var \PHPCI\Store\UserStore
     */
    protected $userStore;

    /**
     * @var \PHPCI\Store\ProjectStore
     */
    protected $projectStore;

    /**
     * Create the PHPCI web application.
     *
     * @param Config       $config
     * @param Request      $request
     * @param Response     $response
     * @param UserStore    $userStore
     * @param ProjectStore $projectStore
     * @param Container    $container
     */
    public function __construct(
        Config $config,
        Request $request,
        Response $response,
        UserStore $userStore,
        ProjectStore $projectStore,
        Container $container
    ) {
        $this->config = $config;
        $this->response = $response;
        $this->request = $request;
        $this->userStore = $userStore;
        $this->projectStore = $projectStore;
        $this->container = $container;

        $this->router = new Router($this, $this->request, $this->config);

        $this->init();
    }

    /**
     * Initialise PHPCI - Handles session verification, routing, etc.
     */
    public function init()
    {
        $request =& $this->request;
        $route = '/:controller/:action';
        $opts = array('controller' => 'Home', 'action' => 'index');

        // Inlined as a closure to fix "using $this when not in object context" on 5.3
        $validateSession = function () {
            if (!empty($_SESSION['phpci_user_id'])) {
                $user = $this->userStore->getByPrimaryKey($_SESSION['phpci_user_id']);

                if ($user) {
                    $_SESSION['phpci_user'] = $user;
                    return true;
                }

                unset($_SESSION['phpci_user_id']);
            }

            return false;
        };

        $skipAuth = array($this, 'shouldSkipAuth');

        // Handler for the route we're about to register, checks for a valid session where necessary:
        $routeHandler = function (&$route, Response &$response) use (&$request, $validateSession, $skipAuth) {
            $skipValidation = in_array($route['controller'], array('session', 'webhook', 'build-status'));

            if (!$skipValidation && !$validateSession() && (!is_callable($skipAuth) || !$skipAuth())) {
                if ($request->isAjax()) {
                    $response->setResponseCode(401);
                    $response->setContent('');
                } else {
                    $_SESSION['phpci_login_redirect'] = substr($request->getPath(), 1);
                    $response = new RedirectResponse($response);
                    $response->setHeader('Location', PHPCI_URL.'session/login');
                }

                return false;
            }

            return true;
        };

        $this->router->clearRoutes();
        $this->router->register($route, $opts, $routeHandler);
    }

    /**
     * Handle an incoming web request.
     *
     * @return b8\b8\Http\Response|Response
     */
    public function handleRequest()
    {
        try {
            $this->response = parent::handleRequest();
        } catch (HttpException $ex) {
            $this->config->set('page_title', 'Error');

            $view = new View('exception');
            $view->exception = $ex;

            $this->response->setResponseCode($ex->getErrorCode());
            $this->response->setContent($view->render());
        } catch (\Exception $ex) {
            $this->config->set('page_title', 'Error');

            $view = new View('exception');
            $view->exception = $ex;

            $this->response->setResponseCode(500);
            $this->response->setContent($view->render());
        }

        if ($this->response->hasLayout() && $this->controller->layout) {
            $this->setLayoutVariables($this->controller->layout);
            $this->controller->layout->content = $this->response->getContent();
            $this->response->setContent($this->controller->layout->render());
        }

        return $this->response;
    }

    /**
     * @return \PHPCI\Controller
     */
    public function getController()
    {
        if (empty($this->controller)) {
            $this->controller = $this->container->get($this->getControllerId($this->route));
        }

        return $this->controller;
    }

    /**
     * Check if the specified controller exist.
     *
     * @param  array $route
     *
     * @return boolean
     */
    public function controllerExists($route)
    {
        return $this->container->has($this->getControllerId($route));
    }

    /**
     * Create controller service Id based on specified route.
     *
     * @param  array $route
     *
     * @return string
     */
    protected function getControllerId($route)
    {
        return 'application.controller.' . strtolower($route['controller']);
    }

    /**
     * Injects variables into the layout before rendering it.
     *
     * @param View $layout
     */
    protected function setLayoutVariables(View &$layout)
    {
        $layout->projects = $this->projectStore->getWhere(
            array('archived' => (int)isset($_GET['archived'])),
            50,
            0,
            array(),
            array('title' => 'ASC')
        );
    }

    /**
     * Check whether we should skip auth (because it is disabled)
     *
     * @return bool
     */
    protected function shouldSkipAuth()
    {
        $state = (bool) $this->config->get('phpci.authentication_settings.state', false);
        $userId = $this->config->get('phpci.authentication_settings.user_id', 0);

        if (false !== $state && 0 != (int)$userId) {
            $user = $this->userStore->getByPrimaryKey($userId);

            if ($user) {
                $_SESSION['phpci_user'] = $user;
                return true;
            }
        }

        return false;
    }
}
