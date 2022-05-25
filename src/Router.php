<?php

namespace EasyMVC;

use Exception;
use JetBrains\PhpStorm\NoReturn;
use Mobile_Detect;

/**
 * Class Router (PHP version 7.4)
 *
 * @author      Rudy Mas <rudy.mas@rmsoft.be>
 * @copyright   2022, rmsoft.be. (http://www.rmsoft.be/)
 * @license     https://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @version     7.4.1.0
 * @package     Tiger
 */
class Router
{
    /**
     * @var array $parameters
     * This contains the URL stripped down to an array of parameters
     * Example: http://www.test.be/user/5
     * Becomes:
     * Array
     * {
     *  [0] => GET
     *  [1] => user
     *  [2] => 5
     * }
     */
    private array $parameters = [];

    /**
     * @var string $body
     * This contains the body of the request
     */
    private string $body;

    /**
     * @var array $routes ;
     * This contains all the routes of the website
     * $routes[n]['route'] = the route to check against
     * $routes[n]['action'] = the controller/function to load
     * $routes[n]['args'] = array of argument(s) which you pass to the controller
     * $routes[n]['repositories'] = array of repositories which you pass to the function (or __constructor)
     * $routes[n]['device'] = when mobile detection is active, this will decide how website calls will be handled
     *                          auto = detects device and redirects according parameters set (DEFAULT)
     *                          mobile = always redirect to mobile app
     *                          web = always redirect to website (PHP)
     *                          api = API will always be handled by the PHP API
     */
    private array $routes = [];

    /**
     * @var string $default
     * The default route to be used
     */
    private string $default = '/';

    /**
     * @var string
     * The default path to the mobile app
     * Default 'http://yourwebsite.com/m' (Also depending on the BASE_URL)
     */
    private string $defaultMobileApp = '/m';

    /**
     * @var bool
     * Is mobile detection active or not
     */
    private bool $mobileDetection = false;

    /**
     * @var Core
     * Needed for injecting tiger_core into the Framework
     */
    private Core $Core;

    /**
     * @var Mobile_Detect
     */
    private Mobile_Detect $Detect;

    /**
     * Router constructor.
     * @param Core $Core
     */
    public function __construct(Core $Core)
    {
        $this->Core = $Core;
        $this->Detect = new Mobile_Detect();
    }

    /**
     * function addRoute(...)
     * This will add a route to the system
     *
     * @param string $method The method of the request (GET/PUT/POST/...)
     * @param string $route A route for the system (/blog/page/1)
     * @param string $action The action script that has to be used
     * @param array $args The arguments to pass to the controller
     * @param array $repositories The repositories to pass to the action method
     * @param string $device Route is for which device
     *                  auto = auto-detect and redirect if needed
     *                  web / api = always use the PHP version
     *                  mobile = always redirect to the mobile app (forward URI)
     * @return bool Returns FALSE if route already exists, TRUE if it is added
     */
    public function addRoute(
        string $method,
        string $route,
        string $action,
        array $args = [],
        array $repositories = [],
        string $device = 'auto'
    ): bool {
        $route = strtoupper($method) . rtrim($route, '/');
        if ($this->isRouteSet($route)) {
            return false;
        } else {
            $args['Core'] = $this->Core;
            $this->routes[] = array(
                'route' => $route,
                'action' => $action,
                'args' => $args,
                'repositories' => $repositories,
                'device' => $device
            );
            return true;
        }
    }

    /**
     * @param string $page The page to redirect to
     */
    public function setDefault(string $page): void
    {
        $this->default = $page;
    }

    /**
     * @param string $linkMobileApp
     */
    public function setDefaultMobileApp(string $linkMobileApp): void
    {
        $this->defaultMobileApp = $linkMobileApp;
    }

    /**
     * @param bool $status
     */
    public function setMobileDetection(bool $status): void
    {
        $this->mobileDetection = $status;
    }

    /**
     * function execute()
     * This will process the URL and execute the controller and function when the URL is a correct route
     *
     * @throws Exception Will throw an exception when the route isn't configured (Error Code 404)
     * @return boolean Returns TRUE if page has been found
     */
    public function execute(): bool
    {
        $this->checkFunctions();
        $this->processURL();
        if ($this->parameters['0'] == 'OPTIONS') {
            $this->respondOnOptionsRequest();
        }
        $this->processBody();
        $variables = [];
        foreach ($this->routes as $value) {
            $testRoute = explode('/', $value['route']);
            if (!(count($this->parameters) == count($testRoute))) {
                continue;
            }
            for ($x = 0; $x < count($testRoute); $x++) {
                if ($this->isItAVariable($testRoute[$x])) {
                    $key = trim($testRoute[$x], '{}');
                    $variables[$key] = str_replace('__', '/', $this->parameters[$x]);
                } elseif (strtolower($testRoute[$x]) != strtolower($this->parameters[$x])) {
                    break 1;
                }
                $variables['headers'] = apache_request_headers();
                if ($x == count($testRoute) - 1) {
                    $this->processMobile($value, $this->parameters);
                    $function2Execute = explode(':', $value['action']);
                    $action = '\\Controllers\\' . $function2Execute[0] . 'Controller';
                    if (count($function2Execute) == 2) {
                        $controller = new $action($value['args']);
                        $arguments = [];
                        if (!empty($value['repositories'])) {
                            foreach ($value['repositories'] as $repositoryToLoad) {
                                $repositoryLoader = explode(':', $repositoryToLoad);
                                $repository = '\\Repositories\\' . $repositoryLoader[0] . 'Repository';
                                if (count($repositoryLoader) == 2) {
                                    $arguments[] = new $repository($this->Core->DB[$repositoryLoader[1]], null);
                                } else {
                                    if (empty($this->Core->DB['DBconnect'])) {
                                        $arguments[] = new $repository(null);
                                    } else {
                                        $arguments[] = new $repository($this->Core->DB['DBconnect'], null);
                                    }
                                }
                            }
                        }
                        $arguments[] = $variables;
                        $arguments[] = $this->body;
                        call_user_func_array([$controller, $function2Execute[1]], $arguments);
                    } else {
                        new $action($value['args'], $variables, $this->body);
                    }
                    return true;
                }
            }
        }
        header('Location: ' . $this->default);
        exit;
    }

    /**
     * function processURL()
     * This will process the URL and extract the parameters from it.
     */
    private function processURL(): void
    {
        $defaultPath = '';
        $basePath = explode('?', urldecode($_SERVER['REQUEST_URI']));
        $requestURI = explode('/', rtrim($basePath[0], '/'));
        $requestURI[0] = strtoupper($_SERVER['REQUEST_METHOD']);
        $scriptName = explode('/', $_SERVER['SCRIPT_NAME']);
        $sizeofRequestURI = sizeof($requestURI);
        $sizeofScriptName = sizeof($scriptName);
        for ($x = 0; $x < $sizeofRequestURI && $x < $sizeofScriptName; $x++) {
            if (strtolower($requestURI[$x]) == strtolower($scriptName[$x])) {
                $defaultPath .= '/' . $requestURI[$x];
                unset($requestURI[$x]);
            }
        }
        $this->default = $defaultPath . $this->default;
        if (!$this->isFullUrl($this->defaultMobileApp)) {
            $this->defaultMobileApp = $defaultPath . $this->defaultMobileApp;
        }
        $this->parameters = array_values($requestURI);
    }

    /**
     * function processBody()
     * This will process the body of a REST request
     */
    private function processBody(): void
    {
        $this->body = file_get_contents('php://input');
    }

    /**
     * function processMobile()
     * This will check if the user is using a mobile device and if needed, redirect him to the mobile app
     *
     * @param array $value
     * @param array $parameters
     */
    private function processMobile(array $value, array $parameters): void
    {
        if ($this->mobileDetection) {
            switch ($value['device']) {
                case 'mobile':
                    $path = '';
                    for ($x = 1; $x < count($parameters); $x++) {
                        $path .= '/' . $parameters[$x];
                    }
                    header('Location: ' . $this->defaultMobileApp . $path);
                    break;
                case 'auto':
                    if ($this->Detect->isMobile()) {
                        header('Location: ' . $this->defaultMobileApp);
                    }
                    break;
                default:
            }
        }
    }

    /**
     * function isRouteSet($route)
     * This will test if a route already exists and returns TRUE if it is set, FALSE if it isn't set
     *
     * @param string $newRoute The new route to be tested
     * @return bool Returns TRUE if it is set, FALSE if it isn't set
     */
    private function isRouteSet(string $newRoute): bool
    {
        return in_array($newRoute, $this->routes);
    }

    /**
     * function isItAVariable($input)
     * Checks if this part of the route is a variable
     *
     * @param string $input Part of the route to be tested
     * @return bool Return TRUE is a variable, FALSE if not
     */
    private function isItAVariable(string $input): bool
    {
        return preg_match("/^{(.+)}$/", $input);
    }

    /**
     * function isFullUrl($input)
     * Checks if it is a full URL or not
     *
     * @param string $input
     * @return bool
     */
    private function isFullUrl(string $input): bool
    {
        return preg_match("/^http[s]?:\/\//", $input);
    }

    /**
     * Getter for $parameters
     *
     * @return array Returns an array of the parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Getter for $body
     *
     * @return string Returns the body of the request
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Send confirmation for an OPTIONS request
     */
    #[NoReturn] private function respondOnOptionsRequest(): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: *');
        exit;
    }

    /**
     * Checks if a certain function exists on the server, or not.
     * If needed then add this because else the router wouldn't work on a NGINX server!
     */
    private function checkFunctions(): void
    {
        if (!function_exists('apache_request_headers')) {
            function apache_request_headers(): array
            {
                $arh = array();
                $rx_http = '/\AHTTP_/';
                foreach ($_SERVER as $key => $val) {
                    if (preg_match($rx_http, $key)) {
                        $arh_key = preg_replace($rx_http, '', $key);
                        $rx_matches = explode('_', $arh_key);
                        if (count($rx_matches) > 0 and strlen($arh_key) > 2) {
                            foreach ($rx_matches as $ak_key => $ak_val) {
                                $rx_matches[$ak_key] = ucfirst($ak_val);
                            }
                            $arh_key = implode('-', $rx_matches);
                        }
                        $arh[$arh_key] = $val;
                    }
                }
                return ($arh);
            }
        }
    }
}
