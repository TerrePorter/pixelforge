<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits, so a request we accept is one Pixelforge will accept.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to '.self::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.required' => 'Remove empty reference image fields.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $references = $this->input('reference_images', []);

        // Ignore blank rows the user left in the form.
        if (is_array($references)) {
            $this->merge([
                'reference_images' => array_values(array_filter(
                    array_map(fn ($url) => is_string($url) ? trim($url) : $url, $references),
                    fn ($url) => $url !== '' && $url !== null,
                )),
            ]);
        }
    }

    public function size(): ImageSize
    {
        return ImageSize::from($this->string('size')->value());
    }

    public function quality(): ImageQuality
    {
        return ImageQuality::from($this->string('quality')->value());
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> $references */
        $references = $this->validated('reference_images');

        return $references;
    }
}
