<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    // The full secret including its "whsec_" prefix is the HMAC key.
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhooks whose signed timestamp is further than this from now (replay protection).
    'webhook_tolerance_seconds' => 300,

    // How long an unfinished generation can go without an update before we poll it.
    'poll_after_seconds' => (int) env('PIXELFORGE_POLL_AFTER_SECONDS', 120),

    // Pixelforge allows this many queued + processing generations per account. We check our
    // own count first so waiting jobs don't burn the 60 requests/minute limit on certain 429s.
    'concurrency_limit' => 3,

    // How long a generation may wait for Pixelforge to accept it before we give up.
    'submit_for_minutes' => 15,

    // What users pay on top of Pixelforge's cost, in basis points (2500 = 25%).
    'markup_basis_points' => 2500,

    'pricing' => [
        'cache_seconds' => 3600,

        // Used only when GET /v1/pricing is unreachable, so estimates keep working.
        // Actual costs never use this: they come from the rates on each generation.
        'fallback' => [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ],
    ],

];
