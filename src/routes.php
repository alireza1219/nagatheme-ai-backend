<?php

namespace App;

use App\Controllers\CommentController;
use App\Controllers\ContentGeneratorController;
use App\Controllers\ImageAltController;
use App\Controllers\ImageGenerationController;
use App\Controllers\WritingAssistantController;

//  Nagatheme AI - API Routes

// API Version 1 routes:
$app->group('/api/v1', function ($group) {
    // Comment Reply
    $group->post('/comment/reply', [CommentController::class, 'reply']);

    // Content Generator
    $group->post('/content/generate',          [ContentGeneratorController::class, 'generate']);
    $group->post('/content/meta-description',  [ContentGeneratorController::class, 'meta_description']);
    $group->post('/content/excerpt',           [ContentGeneratorController::class, 'excerpt']);

    // Image Alt
    $group->post('/image/alt',        [ImageAltController::class, 'generate']);
    $group->post('/image/alt/batch',  [ImageAltController::class, 'batch']);

    // Image Generation
    $group->post('/image/generate', [ImageGenerationController::class, 'generate']);

    // Writing Assistant
    $group->post('/writing-assistant/chat', [WritingAssistantController::class, 'chat']);
});
