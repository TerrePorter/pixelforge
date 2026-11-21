export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

export type ImageQuality = 'low' | 'medium' | 'high';

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type TokenUsage = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

export type Generation = {
    id: number;
    status: GenerationStatus;
    waiting_reason: string | null;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[];
    /** USD decimal strings with 9 places. */
    estimated_min_charge: string;
    estimated_max_charge: string;
    charge: string | null;
    usage: TokenUsage | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
};

/** The GET /v1/pricing table, plus our markup. */
export type PricingTable = {
    rates: Record<keyof TokenUsage, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<ImageQuality, Record<ImageSize, number>>;
        reasoning: Record<ImageQuality, { min: number; max: number }>;
    };
    markup_basis_points: number;
};
