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
        // Owner decision 2026-11-11: Vietnamese tenants are in scope. false = SPEC 8 step 5 (drop Vietnamese posts).
        'accept_vi' => (bool) env('PREFILTER_ACCEPT_VI', true),
        'negative_score_threshold' => (int) env('PREFILTER_NEGATIVE_THRESHOLD', 2),
        'strong_positive_weight' => (int) env('PREFILTER_STRONG_POSITIVE_WEIGHT', 2),
        'cache_ttl_seconds' => 600,
        // SPEC 20.4: minimal housing vocabulary for step 7 (matched as word prefixes).
        'housing_terms' => [
            'apartment', 'flat', 'house', 'villa', 'studio', 'room', 'bedroom', 'rent', 'lease', 'landlord', 'condo',
            'accommodation',
            'квартир', 'жиль', 'дом', 'аренд', 'студи', 'комнат', 'сниму', 'снять',
            'căn hộ', 'can ho', 'chung cư', 'chung cu', 'nhà nguyên căn', 'nha nguyen can', 'phòng trọ', 'phong tro',
            'thuê nhà', 'thue nha', 'thuê phòng', 'thue phong', 'thuê căn', 'thue can',
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
            'classify' => (int) env('AI_PROMPT_CLASSIFY_VERSION', 2),
            'reply' => (int) env('AI_PROMPT_REPLY_VERSION', 1),
        ],
        // USD per 1M tokens, for the cost widget and the daily budget.
        'pricing' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        ],
    ],

    // Classification engine. "auto": AI when ANTHROPIC_API_KEY is set and the daily
    // budget is not spent, keyword rules otherwise. "ai": AI only (jobs pause when
    // the budget is spent). "keyword": rules only, no API key needed.
    // The admin can override it on the Settings page (settings.classifier_mode).
    'classifier' => [
        'mode' => env('CLASSIFIER_MODE', 'auto'),
        'rules_version' => 'keyword-rules-v1',
        // Confidence = base + per_weight × winning keyword weight + per_field × extracted fields.
        'confidence' => [
            'base' => (float) env('RULES_CONFIDENCE_BASE', 0.45),
            'per_weight' => (float) env('RULES_CONFIDENCE_PER_WEIGHT', 0.1),
            'per_field' => (float) env('RULES_CONFIDENCE_PER_FIELD', 0.05),
            'max' => 0.9,
        ],
        // Requirement tag => terms (lowercase, matched as word prefixes).
        'requirement_terms' => [
            'pet_friendly' => ['pet', 'cat', 'dog', 'kitten', 'puppy', 'кошк', 'кот', 'собак', 'питом', 'животн', 'thú cưng', 'thu cung', 'nuôi mèo', 'nuoi meo', 'nuôi chó', 'nuoi cho'],
            'furnished' => ['furnished', 'мебел', 'меблир', 'nội thất', 'noi that'],
            'pool' => ['pool', 'бассейн', 'hồ bơi', 'ho boi', 'bể bơi', 'be boi'],
            'gym' => ['gym', 'спортзал', 'тренажер'],
            'bathtub' => ['bathtub', 'bath tub', 'ванн'],
            'kitchen' => ['kitchen', 'кухн', 'bếp', 'bep nau'],
            'workspace' => ['workspace', 'desk', 'work from home', 'рабоч'],
            'fast_wifi' => ['wifi', 'wi-fi', 'internet', 'интернет', 'вайфай'],
            'near_beach' => ['beach', 'near the sea', 'пляж', 'у моря', 'близко к морю', 'gần biển', 'gan bien'],
            'quiet' => ['quiet', 'тих', 'yên tĩnh', 'yen tinh'],
            'sea_view' => ['sea view', 'ocean view', 'вид на море', 'view biển', 'view bien'],
            'river_view' => ['river view', 'вид на реку'],
            'family' => ['family', 'kids', 'children', 'child', 'семь', 'дет', 'ребен', 'ребён', 'gia đình', 'gia dinh', 'con nhỏ', 'con nho'],
            'school_nearby' => ['school', 'школ', 'trường học', 'truong hoc'],
            'parking' => ['parking', 'garage', 'парков', 'chỗ để xe', 'cho de xe', 'đậu xe ô tô', 'dau xe o to'],
            'balcony' => ['balcony', 'балкон', 'ban công', 'ban cong'],
            'washing_machine' => ['washing machine', 'washer', 'laundry', 'стиральн', 'máy giặt', 'may giat'],
        ],
    ],

    // Reply drafts without AI (keyword mode, no key, or AI failure in "auto").
    // Admins edit them on the Settings page (settings.reply_templates); these are the defaults.
    // Placeholders: {agency} {website} {area} {budget} {duration} {arrival} {listing} {listing_price} {listings} {site_link}
    // [[ ... ]] is dropped when any placeholder inside it is empty.
    'drafts' => [
        'template_model' => 'template',
        'max_listings' => 3,
        'templates' => [
            'en' => [
                'public_reply' => "Hi! {agency} has a few places[[ in {area}]][[ within {budget}/month]] that could fit what you're looking for. Happy to send photos and details by private message.",
                'dm' => "Hi! I saw your post in the group[[ about a place in {area}]]. I'm with {agency}.\n[[Here are some options that match:\n{listings}\n]]Would you like photos or a quick video call?[[ You're arriving {arrival}, so we can arrange viewings before you land.]][[ More listings: {website}]][[\nPhotos, prices and a request form: {site_link}]]",
            ],
            'vi' => [
                'public_reply' => 'Chào bạn! Bên {agency} đang có vài căn phù hợp[[ ở khu {area}]][[ trong tầm {budget}/tháng]]. Mình gửi hình và thông tin chi tiết qua tin nhắn riêng nhé.',
                'dm' => "Chào bạn, mình thấy bài bạn đăng trong group[[ tìm căn ở khu {area}]]. Mình bên {agency}.
[[Đây là vài căn có thể hợp với bạn:
{listings}
]]Bạn muốn xem thêm hình hay hẹn đi xem trực tiếp không?[[ Bạn dự kiến đến {arrival}, mình có thể sắp xếp xem căn trước.]][[ Xem thêm căn: {website}]][[\nXem hình, giá và gửi yêu cầu: {site_link}]]",
            ],
            'ru' => [
                'public_reply' => 'Здравствуйте! У {agency} есть подходящие варианты[[ в районе {area}]][[ в пределах {budget} в месяц]]. Могу прислать фото и подробности в личные сообщения.',
                'dm' => "Здравствуйте! Увидел ваш пост в группе[[ про жильё в районе {area}]]. Я из {agency}.\n[[Вот варианты, которые могут подойти:\n{listings}\n]]Прислать фото или показать по видеосвязи?[[ Вы приезжаете {arrival}, можем организовать просмотр заранее.]][[ Другие объекты: {website}]][[\nФото, цены и форма заявки: {site_link}]]",
            ],
        ],
    ],

    // App #2: public apartment site + Telegram Mini App (owner decision 2026-11-11).
    'site' => [
        'enabled' => (bool) env('SITE_ENABLED', true),
        'locales' => ['vi', 'en', 'ru'],
        'default_locale' => env('SITE_DEFAULT_LOCALE', 'vi'),
        'per_page' => (int) env('SITE_PER_PAGE', 12),
        // A customer who fills in the form is a hot lead.
        'inquiry_score' => (int) env('INQUIRY_SCORE', 100),
        // Contacts typed into the form are stored encrypted and erased after this many days.
        'contact_retention_days' => (int) env('INQUIRY_CONTACT_DAYS', 90),
        'inquiry_rate_limit_per_hour' => (int) env('INQUIRY_RATE_LIMIT', 5),
        // Forms submitted faster than this are treated as bots.
        'min_fill_seconds' => (int) env('INQUIRY_MIN_FILL_SECONDS', 3),
        // Telegram Mini App initData older than this is rejected.
        'webapp_max_age_seconds' => (int) env('TG_WEBAPP_MAX_AGE', 86400),
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
