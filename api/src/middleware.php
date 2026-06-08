<?php

use Slim\App;

return function (App $app) {
    // Auth Middleware
    $authMiddleware = function ($request, $response, $next) {
        $token = $request->getHeaderLine('X-Sync-Token');
        if (!$token) {
            $token = $request->getParam('token');
        }

        // Token Rahasia untuk Cron Job/Server-to-Server
        $secretToken = ots_env('SYNC_SECRET', 'OTS_SYNC_SECRET_2026');

        if (!isset($_SESSION['user_id']) && $token !== $secretToken) {
            // Check if it's an API request or a page request
            $path = $request->getUri()->getPath();
            if (strpos($path, '/api/') === 0 || strpos($path, '/run-sync') === 0) {
                return $response->withJson(['status' => 'error', 'message' => 'Unauthorized'], 401);
            }
            return $response->withRedirect('/admin/login');
        }
        return $next($request, $response);
    };

    $container = $app->getContainer();
    $container['authMiddleware'] = function ($c) use ($authMiddleware) {
        return $authMiddleware;
    };
};
