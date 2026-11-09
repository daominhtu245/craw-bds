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

    'prefilter' => [
        'max_age_days' => (int) env('PREFILTER_MAX_AGE_DAYS', 14),
        'min_length' => (int) env('PREFILTER_MIN_LENGTH', 15),
        'duplicate_window_days' => (int) env('PREFILTER_DUPLICATE_DAYS', 30),
        'vi_diacritic_ratio' => (float) env('PREFILTER_VI_RATIO', 0.30),
        'negative_score_threshold' => (int) env('PREFILTER_NEGATIVE_THRESHOLD', 2),
        'strong_positive_weight' => (int) env('PREFILTER_STRONG_POSITIVE_WEIGHT', 2),
        'cache_ttl_seconds' => 600,
    ],

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'model_classify' => env('AI_MODEL_CLASSIFY', 'claude-haiku-5-5'),
        'model_escalate' => env('AI_MODEL_ESCALATE', 'claude-sonnet-5-5'),
        'model_draft' => env('AI_MODEL_DRAFT', 'claude-sonnet-5-5'),
        'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.6),
        'daily_budget_usd' => (float) env('AI_DAILY_BUDGET_USD', 5),
        'timeout_seconds' => 30,
        'max_retries' => 3,
    ],

    'leads' => [
        'expires_after_days' => (int) env('LEAD_EXPIRES_DAYS', 60),
    ],

    'notify' => [
        'bot_token' => env('TG_BOT_TOKEN'),
        'team_chat_id' => env('TG_TEAM_CHAT_ID'),
        'min_score' => (int) env('NOTIFY_MIN_SCORE', 60),
        'quiet_hours' => env('QUIET_HOURS', '23-07'),
    ],

];
