<?php

use Slim\Factory\AppFactory;
use App\Helpers\Env;

require __DIR__ . '/../vendor/autoload.php'; // In localhost
// require '../../.nagatheme_ai_api/vendor/autoload.php'; // In production

// Load environment settings
Env::load(__DIR__ . '/../.env'); // In localhost
// Env::load('../../.nagatheme_ai_api/.env'); // In production

// Create App
$app = AppFactory::create();

// Add error middleware

$displayErrorDetails = Env::getBool('DISPLAY_ERROR_DETAILS', false);
$logErrors           = Env::getBool('LOG_ERRORS', false);
$logErrorDetails     = Env::getBool('LOG_ERROR_DETAILS', false);

$app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);

// Set the App base path to the current directory
$app->setBasePath(dirname($_SERVER['SCRIPT_NAME']));

// CORS Configuration
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Register routes
require __DIR__ . '/../src/routes.php'; // In localhost
// require '../../.nagatheme_ai_api/src/routes.php'; // In production

$app->run();
