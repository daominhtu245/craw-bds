<?php

/*
|--------------------------------------------------------------------------
| Expat Lead Radar configuration
|--------------------------------------------------------------------------
| Every threshold, weight and AI model name lives here and is read from .env.
| See SPEC.md for the meaning of each value.
*/

return [

    // Times are stored in UTC (config/app.php). This is only for display.
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh')),

    // First admin account created by AdminUserSeeder.
    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@leadradar.local'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    'ingest' => [
        'token' => env('INGEST_TOKEN'),
        'max_batch' => (int) env('INGEST_MAX_BATCH', 50),
        'rate_limit_per_minute' => (int) env('INGEST_RATE_LIMIT', 120),
        'context_max_chars' => 1000,
    ],

    // SPEC 8
    'prefilter' => [
        'max_age_days' => (int) env('PREFILTER_MAX_AGE_DAYS', 14),
        'min_length' => (int) env('PREFILTER_MIN_LENGTH', 15),
        'duplicate_window_days' => (int) env('PREFILTER_DUPLICATE_DAYS', 30),
        // Share of Latin words carrying a Vietnamese diacritic (word-based: a
        // per-character ratio is ~25% even for plain Vietnamese text).
        'vi_word_ratio' => (float) env('PREFILTER_VI_RATIO', 0.30),
        'negative_score_threshold' => (int) env('PREFILTER_NEGATIVE_THRESHOLD', 2),
        'strong_positive_weight' => (int) env('PREFILTER_STRONG_POSITIVE_WEIGHT', 2),
        'cache_ttl_seconds' => 600,
        // SPEC 20.4: minimal housing vocabulary for step 7 (matched as word prefixes).
        'housing_terms' => [
            'apartment', 'flat', 'house', 'villa', 'studio', 'room', 'bedroom', 'rent', 'lease', 'landlord', 'condo',
            'accommodation',
            'квартир', 'жиль', 'дом', 'аренд', 'студи', 'комнат', 'сниму', 'снять',
        ],
    ],

    // SPEC 9
    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2026-10-21'),
        'model_classify' => env('AI_MODEL_CLASSIFY', 'claude-haiku-5-5'),
        'model_escalate' => env('AI_MODEL_ESCALATE', 'claude-sonnet-5-5'),
        'model_draft' => env('AI_MODEL_DRAFT', 'claude-sonnet-5-5'),
        'effort_classify' => env('AI_EFFORT_CLASSIFY', 'low'),
        'effort_draft' => env('AI_EFFORT_DRAFT', 'medium'),
        'max_tokens_classify' => (int) env('AI_MAX_TOKENS_CLASSIFY', 4000),
        'max_tokens_draft' => (int) env('AI_MAX_TOKENS_DRAFT', 8000),
        'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.6),
        'daily_budget_usd' => (float) env('AI_DAILY_BUDGET_USD', 5),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 30),
        'max_retries' => 3,
        // Server-side refusal fallback ("fallbacks": "default"). Only models that accept it on the Claude API.
        'fallback_models' => array_filter(explode(',', (string) env('AI_FALLBACK_MODELS', 'claude-sonnet-5-5,claude-opus-5-5,claude-opus-5,claude-fable-5-1'))),
        'fallback_beta' => 'server-side-fallback-2026-07-01',
        'prompt_versions' => [
            'classify' => (int) env('AI_PROMPT_CLASSIFY_VERSION', 1),
            'reply' => (int) env('AI_PROMPT_REPLY_VERSION', 1),
        ],
        // USD per 1M tokens, for the cost widget and the daily budget.
        'pricing' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        ],
    ],

    'leads' => [
        'expires_after_days' => (int) env('LEAD_EXPIRES_DAYS', 60),
        'requirement_tags' => [
            'pet_friendly', 'furnished', 'pool', 'gym', 'bathtub', 'kitchen', 'workspace', 'fast_wifi',
            'near_beach', 'quiet', 'sea_view', 'river_view', 'family', 'school_nearby', 'parking',
            'balcony', 'washing_machine',
        ],
        'known_agent_threshold' => (int) env('KNOWN_AGENT_THRESHOLD', 3),
    ],

    // SPEC 10
    'scoring' => [
        'tenant_demand' => 10,
        'stage_urgent' => 25,
        'stage_pre_arrival_soon' => 25,
        'pre_arrival_window_days' => 60,
        'stage_in_city' => 15,
        'duration_6_plus' => 20,
        'duration_3_to_5' => 10,
        'budget_in_inventory_range' => 15,
        'budget_tolerance' => 0.15,
        'area_matched' => 10,
        'listing_matched' => 10,
        'listing_match_min' => 60,
        'fresh_post' => 10,
        'fresh_post_hours' => 12,
        'low_confidence_penalty' => -10,
        'low_confidence_below' => 0.75,
        'leaving_handover_score' => 50,
    ],

    // SPEC 11
    'matching' => [
        'max_results' => 5,
        'min_score' => 40,
        'area_match' => 30,
        'area_unspecified' => 15,
        'price_within_budget' => 25,
        'price_slightly_over' => 10,
        'price_over_tolerance' => 0.10,
        'bedrooms_or_type' => 20,
        'term_ok' => 10,
        'requirement_each' => 5,
        'requirement_max' => 15,
    ],

    // SPEC 13
    'notify' => [
        'bot_token' => env('TG_BOT_TOKEN'),
        'team_chat_id' => env('TG_TEAM_CHAT_ID'),
        'api_base' => env('TG_API_BASE', 'https://api.telegram.org'),
        'min_score' => (int) env('NOTIFY_MIN_SCORE', 60),
        'quiet_hours' => env('QUIET_HOURS', '23-07'),
        // Filament lead page (Phase 3). {id} is replaced with the lead id.
        'dashboard_lead_url' => env('DASHBOARD_LEAD_URL', rtrim((string) env('APP_URL', 'http://localhost:8080'), '/').'/admin/leads/{id}'),
    ],

];
