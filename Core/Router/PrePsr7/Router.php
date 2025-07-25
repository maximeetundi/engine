<?php

namespace Minds\Core\Router\PrePsr7;

use Minds\Core\Di\Di;
use Minds\Core\Router\Exceptions\UnverifiedEmailException;
use Minds\Core\Router\PrePsr7\Middleware\RouterMiddleware;
use Minds\Core\Session;
use Minds\Helpers;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\ServerRequestFactory;
use Minds\Core\Security;
use Minds\Core\page;
use Minds\Core\Referrals\ReferralCookie;
use Minds\Core\Router\Dispatcher;
use Minds\Core\Router\Exceptions\UnauthorizedException;
use Minds\Core\Router\Middleware\Kernel\ContentSecurityPolicyMiddleware;
use Minds\Core\Router\Middleware\Kernel\FrameSecurityMiddleware;
use Minds\Core\Supermind\AutoSupermindRouterMiddleware;

/**
 * Minds Core Router.
 */
class Router
{
    // these are core pages, other pages are registered by plugins
    public static $routes = [
        '/archive/thumbnail' => 'Minds\\Controllers\\fs\\v1\\thumbnail',
        '/api/v1/archive/thumbnails' => 'Minds\\Controllers\\api\\v1\\media\\thumbnails',

        '/oauth2/token' => 'Minds\\Controllers\\oauth2\\token',
        '/oauth2/implicit' => 'Minds\\Controllers\\oauth2\\implicit',
        '/icon' => 'Minds\\Controllers\\icon',
        '//icon' => 'Minds\\Controllers\\icon',
        '/api' => 'Minds\\Controllers\\api\\api',
        '/fs' => 'Minds\\Controllers\\fs\\fs',
        '/thumbProxy' => 'Minds\\Controllers\\thumbProxy',
        '/wall' => 'Minds\\Controllers\\Legacy\\wall',
        //  "/app" => "minds\\pages\\app",
        '/emails/unsubscribe' => 'Minds\\Controllers\\emails\\unsubscribe',
        '/checkout' => '\\Minds\\Controllers\\checkout',
    ];

    /**
     * Route the pages
     * (fallback to elgg page handler if we fail).
     *
     * @param string $uri
     * @param string $method
     *
     * @return null|mixed
     */
    public function route($uri = null, $method = null)
    {
        if ((!$uri) && (isset($_SERVER['REDIRECT_ORIG_URI']))) {
            $uri = strtok($_SERVER['REDIRECT_ORIG_URI'], '?');
        }

        if (!$uri) {
            $uri = strtok($_SERVER['REQUEST_URI'], '?');
        }

        $this->detectContentType();

        $route = rtrim($uri, '/');
        $segments = explode('/', $route);
        $method = $method ? $method : strtolower($_SERVER['REQUEST_METHOD']);

        if ($method == 'post') {
            $this->postDataFix();
        }

        $request = ServerRequestFactory::fromGlobals();
        $response = new JsonResponse([]);

        /**
         * Some PSR7 middlewares work
         * We will use them to get the headers
         */
        $psr7Middleware = [
            new FrameSecurityMiddleware(),
            new ContentSecurityPolicyMiddleware(),
        ];
        $dispatcher = new Dispatcher();
        foreach ($psr7Middleware as $middleware) {
            $dispatcher->pipe($middleware);
        }
        $response = $dispatcher->handle($request, $dispatcher);
        foreach ($response->getHeaders() as $k => $v) {
            header("$k: {$v[0]}");
        }

        /** @var RouterMiddleware[] $prePsr7Middleware */
        $prePsr7Middleware = [
            new AutoSupermindRouterMiddleware(),
        ];

        foreach ($prePsr7Middleware as $middleware) {
            $result = $middleware->onRequest($request, $response);

            if ($result === false) {
                return null;
            }
        }

        if ($request->getMethod() === 'OPTIONS') {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Accept,Authorization,Cache-Control,Content-Type,DNT,If-Modified-Since,Keep-Alive,Origin,User-Agent,X-Mx-ReqToken,X-Requested-With,X-No-Cache');

            return null;
        }

        // Sessions
        // TODO: Support middleware
        try {
            $session = Di::_()->get('Sessions\Manager');
            $session->withRouterRequest($request);
        } catch (UnauthorizedException $e) {
            http_response_code(403);
            header('HTTP/1.1 401 Unauthorized', true, 401);
            echo json_encode([
                'status' => 'error',
                'logged_in' => false,
            ]);
            exit;
        }

        // OAuth Middleware
        // TODO: allow interface to bypass
        // TODO: Support middleware
        if (!Session::isLoggedIn()) { // Middleware will resolve this
            Session::withRouterRequest($request, $response);
        }

        // XSRF Cookie - may be able to remove now with OAuth flow
        Security\XSRF::setCookie();

        Di::_()->get('Email\RouterHooks')
            ->withRouterRequest($request);

        Di::_()->get(ReferralCookie::class)
            ->withRouterRequest($request)
            ->create();

        $loop = count($segments);
        while ($loop >= 0) {
            $offset = $loop - 1;
            if ($loop < count($segments)) {
                $slug_length = strlen($segments[$offset + 1] . '/');
                $route_length = strlen($route);
                $route = substr($route, 0, $route_length - $slug_length);
            }

            if (isset(self::$routes[$route])) {
                $handler = new self::$routes[$route]();
                $pages = array_splice($segments, $loop) ?: [];
                if (method_exists($handler, $method)) {
                    // Set the request
                    if (method_exists($handler, 'setRequest')) {
                        $handler->setRequest($request);
                    }
                    // Set the response
                    if (method_exists($handler, 'setResponse')) {
                        $handler->setResponse($response);
                    }

                    try {
                        return $handler->$method($pages);
                    } catch (UnverifiedEmailException $e) {
                        http_response_code(403);

                        header('HTTP/1.1 403 Forbidden', true, 403);
                        echo json_encode([
                            'status' => 'error',
                            'message' => $e->getMessage(),
                            'must_verify' => true
                        ]);

                        exit;
                    }
                } else {
                    return null;
                }
            }
            --$loop;
        }

        if (!$this->legacyRoute($uri)) {
            Di::_()->get('I18n\Manager')->serveIndex();
        }

        return null;
    }

    /**
     * Legacy Router fallback.
     *
     * @param string $uri
     *
     * @return bool
     */
    public function legacyRoute($uri)
    {
        $path = explode('/', substr($uri, 1));
        $handler = array_shift($path);
        $page = implode('/', $path);

        new page(); //just to load init etc

        return false;
    }

    /**
     * Detects request content type and apply the corresponding polyfills.
     */
    public function detectContentType()
    {
        if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json') {
            //\elgg_set_viewtype('json');
            if (strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
                $this->postDataFix();
            }
        }
    }

    /**
     * Populates $_POST and $_REQUEST with request's JSON payload.
     */
    public function postDataFix()
    {
        $postdata = file_get_contents('php://input');
        $request = json_decode($postdata, true);
        if ($request) {
            foreach ($request as $k => $v) {
                $_POST[$k] = $v;
                $_REQUEST[$k] = $v;
            }
        }
    }

    /**
     * Return vars for request
     * @return array
     */
    public static function getPutVars()
    {
        $postdata = file_get_contents('php://input');
        $request = json_decode($postdata, true);
        return $request;
    }

    /**
     * Register routes.
     *
     * @param array $routes - an array of routes to handlers
     *
     * @return array - the array of all your routes
     */
    public static function registerRoutes($routes = [])
    {
        return self::$routes = array_merge(self::$routes, $routes);
    }
}
