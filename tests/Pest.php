<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A Pixelforge generation object, as sent in webhooks and API responses.
 *
 * A succeeded one uses the docs' usage example (41 text, 3300 output, 2950 reasoning
 * tokens), which costs exactly $0.169080 at the published rates.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteGeneration(string $status, array $overrides = []): array
{
    $base = [
        'id' => 'gen_test',
        'object' => 'generation',
        'status' => $status,
        'prompt' => 'a lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-20T14:00:00Z',
        'started_at' => $status === 'queued' ? null : '2026-11-20T14:00:05Z',
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-20T14:00:20Z' : null,
    ];

    if ($status === 'succeeded') {
        $base['usage'] = [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'total_tokens' => 6291,
        ];
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/gen_test.png?sig=abc';
        $base['image_url_expires_at'] = '2026-11-21T14:00:20Z';
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
    }

    return array_replace($base, $overrides);
}
