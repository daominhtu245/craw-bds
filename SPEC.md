# SPEC — Expat Lead Radar (Đà Nẵng)

> Hệ thống lắng nghe mạng xã hội để tìm **người nước ngoài có nhu cầu thuê nhà dài hạn tại Đà Nẵng**. Hệ thống phân loại bằng AI, chấm điểm lead, khớp với kho căn hộ và báo lead nóng cho team qua Telegram.
>
> Phiên bản spec: 1.0 — 2026-11-09
> Ngôn ngữ tài liệu: tiếng Việt. Code, tên bảng, biến và commit: tiếng Anh.

---

## Mục lục

0. [Cách dùng tài liệu này với Claude Code](#0-cách-dùng-tài-liệu-này-với-claude-code)
1. [Mục tiêu và phạm vi](#1-mục-tiêu-và-phạm-vi)
2. [Người dùng và vai trò](#2-người-dùng-và-vai-trò)
3. [Kiến trúc tổng thể](#3-kiến-trúc-tổng-thể)
4. [Cấu trúc repo](#4-cấu-trúc-repo)
5. [Mô hình dữ liệu](#5-mô-hình-dữ-liệu)
6. [Collectors (thu thập dữ liệu)](#6-collectors-thu-thập-dữ-liệu)
7. [Ingest API](#7-ingest-api)
8. [Tiền lọc rule-based](#8-tiền-lọc-rule-based)
9. [AI phân loại](#9-ai-phân-loại)
10. [Chấm điểm lead](#10-chấm-điểm-lead)
11. [Khớp kho căn hộ](#11-khớp-kho-căn-hộ)
12. [AI soạn câu trả lời](#12-ai-soạn-câu-trả-lời)
13. [Thông báo Telegram](#13-thông-báo-telegram)
14. [Dashboard quản trị (Filament)](#14-dashboard-quản-trị-filament)
15. [Quyền riêng tư và lưu trữ dữ liệu](#15-quyền-riêng-tư-và-lưu-trữ-dữ-liệu)
16. [Cấu hình (.env)](#16-cấu-hình-env)
17. [Yêu cầu phi chức năng](#17-yêu-cầu-phi-chức-năng)
18. [Kiểm thử và đánh giá AI](#18-kiểm-thử-và-đánh-giá-ai)
19. [Lộ trình triển khai và tiêu chí nghiệm thu](#19-lộ-trình-triển-khai-và-tiêu-chí-nghiệm-thu)
20. [Phụ lục: dữ liệu seed](#20-phụ-lục-dữ-liệu-seed)

---

## 0. Cách dùng tài liệu này với Claude Code

1. Tạo thư mục repo trống, đặt `SPEC.md` và `CLAUDE.md` vào gốc repo.
2. Mở Claude Code tại thư mục đó và làm **từng phase một** (mục 19). Không yêu cầu build toàn bộ hệ thống trong một lần.
3. Prompt mẫu cho phase đầu:

```
Đọc SPEC.md và CLAUDE.md. Thực hiện Phase 1 trong mục 19.
Trước khi code, liệt kê ngắn các file sẽ tạo. Khi xong, chạy test và
đối chiếu với tiêu chí nghiệm thu của Phase 1.
```

4. Sau mỗi phase: chạy thử, commit, rồi chuyển sang phase tiếp theo bằng prompt tương tự.
5. Những chỗ ghi **[VERIFY]** là thông tin cần Claude Code tự kiểm tra lại, ví dụ cấu trúc HTML thực tế hoặc input schema của actor Apify. Không được đoán.

---

## 1. Mục tiêu và phạm vi

### 1.1 Mục tiêu

- Phát hiện **trong vòng vài phút** các bài hoặc tin nhắn của người nước ngoài đang tìm thuê nhà dài hạn (≥ 3 tháng) tại Đà Nẵng.
- Tách **nhu cầu thật** khỏi tin rao của môi giới và chủ nhà, với độ chính xác mục tiêu ≥ 85%.
- Biến mỗi nhu cầu thành **lead có cấu trúc**: ngân sách, khu vực, thời điểm đến, thời gian thuê, yêu cầu đặc biệt.
- Giúp sale **trả lời nhanh và đúng ngôn ngữ** bằng bản nháp do AI soạn sẵn, kèm căn hộ phù hợp.
- Phát hiện tin **"sắp rời đi, cần người thuê lại"** để làm nguồn hàng.

### 1.2 Trong phạm vi (MVP → V1)

| Hạng mục | Chi tiết |
|---|---|
| Nguồn | Telegram (group/chat public mà tài khoản listener đã join), Facebook group public (qua Apify), forum Expat.com mục Đà Nẵng |
| Ngôn ngữ | Tiếng Anh, tiếng Nga (bắt buộc). Tiếng Hàn và tiếng Trung: AI vẫn phân loại nếu gặp, nhưng không cần keyword riêng |
| Địa bàn | Đà Nẵng (bao gồm khu Hội An nếu người dùng nhắc cùng Đà Nẵng) |
| Đầu ra | Dashboard Filament, Telegram bot báo lead, bản nháp trả lời |

### 1.3 Ngoài phạm vi (KHÔNG làm)

- ❌ Tệp khách người Việt. Bài tiếng Việt bị loại ở bước tiền lọc, trừ khi được viết bởi người nước ngoài bằng tiếng Anh hoặc tiếng Nga.
- ❌ Tự động gửi tin nhắn, comment hoặc DM. Mọi tương tác do người thật thực hiện.
- ❌ Thu thập số điện thoại, email, ảnh đại diện, hay lập hồ sơ cá nhân theo người.
- ❌ Tự động join group, tạo tài khoản, hay vượt CAPTCHA.
- ❌ Reddit (để Phase sau, cần quyền API thương mại).
- ❌ Bán, xuất hoặc chia sẻ dữ liệu lead ra ngoài hệ thống.

---

## 2. Người dùng và vai trò

| Vai trò | Quyền |
|---|---|
| `admin` | Toàn quyền: nguồn, keyword, cài đặt, người dùng, xóa dữ liệu |
| `sales` | Xem và nhận lead, đổi trạng thái, ghi chú, dùng bản nháp trả lời, xem kho căn hộ |

Luồng làm việc của sale:

1. Nhận thông báo Telegram.
2. Mở lead trên dashboard.
3. Đọc tóm tắt và các căn gợi ý.
4. Chỉnh bản nháp rồi trả lời **thủ công** tại bài gốc.
5. Cập nhật trạng thái lead.

---

## 3. Kiến trúc tổng thể

```
┌──────────────────────── collectors (Python 3.12) ─────────────────────────┐
│  collector-telegram   Telethon, realtime NewMessage listener              │
│  collector-facebook   Apify actor, chạy theo lịch mỗi 60 phút             │
│  collector-forum      httpx + selectolax, crawl Expat.com mỗi 60 phút     │
└───────────────┬───────────────────────────────────────────────────────────┘
                │ POST /api/ingest/items  (batch, Bearer INGEST_TOKEN)
                ▼
┌──────────────────────── app (Laravel + Filament) ─────────────────────────┐
│ IngestController → lưu raw_items (idempotent) → PrefilterJob              │
│ PrefilterJob   → dedupe, ngôn ngữ, keyword → ClassifyJob (nếu pass)       │
│ ClassifyJob    → Claude API (tool use, JSON) → leads                      │
│ ScoreLead      → tính score → MatchListings → DraftReplyJob (nếu ≥ ngưỡng)│
│ NotifyLeadJob  → Telegram Bot API → group chat của team                   │
│ Scheduler      → dọn dữ liệu, tỷ giá, health check collectors             │
└───────────────┬───────────────────────────────────────────────────────────┘
                ▼
        MySQL 8.4   +   Redis 7 (queue, cache, rate-limit)
```

**Nguyên tắc thiết kế:**

- Collectors chỉ **thu và chuẩn hóa**, không chứa logic nghiệp vụ. Mọi logic nằm trong Laravel.
- Mọi bước đều **idempotent**. Nhận trùng item thì bỏ qua, chạy lại job không tạo lead trùng.
- Mỗi collector **độc lập**. Một nguồn chết thì các nguồn khác vẫn chạy.
- Toàn bộ chạy bằng `docker compose` trên một máy (local hoặc VPS 2 vCPU / 4 GB RAM).

---

## 4. Cấu trúc repo

```
expat-lead-radar/
├── SPEC.md
├── CLAUDE.md
├── docker-compose.yml
├── .env.example
├── app/                          # Laravel project
│   ├── app/
│   │   ├── Http/Controllers/Api/IngestController.php
│   │   ├── Jobs/ (PrefilterItemJob, ClassifyItemJob, DraftReplyJob, NotifyLeadJob)
│   │   ├── Services/
│   │   │   ├── Ai/ClaudeClient.php
│   │   │   ├── Ai/LeadClassifier.php
│   │   │   ├── Ai/ReplyDrafter.php
│   │   │   ├── Prefilter/Prefilter.php
│   │   │   ├── Scoring/LeadScorer.php
│   │   │   ├── Matching/ListingMatcher.php
│   │   │   ├── Currency/CurrencyConverter.php
│   │   │   └── Notify/TelegramNotifier.php
│   │   ├── Models/ ...
│   │   ├── Enums/ (LeadIntent, LeadStage, LeadStatus, SourceType)
│   │   └── Filament/ (Resources, Pages, Widgets)
│   ├── config/leadradar.php      # mọi ngưỡng, trọng số, model
│   ├── database/migrations, seeders
│   ├── resources/prompts/        # prompt dạng file .md, version hóa
│   └── tests/ (Unit, Feature, Fixtures/samples.jsonl)
└── collectors/
    ├── pyproject.toml
    ├── common/ (ingest_client.py, schema.py, logging.py)
    ├── telegram/ (listener.py, login.py, config.py)
    ├── facebook/ (run.py)
    ├── forum/ (expat_com.py)
    └── tests/
```

---

## 5. Mô hình dữ liệu

MySQL 8.4, charset `utf8mb4_unicode_ci` (bắt buộc để lưu tiếng Nga, tiếng Hàn và emoji). Mọi bảng có `id` (bigint), `created_at`, `updated_at` trừ khi ghi khác.

### 5.1 `sources`: các nguồn cụ thể (một group, một forum)

| Cột | Kiểu | Ghi chú |
|---|---|---|
| type | enum(`telegram`,`facebook`,`forum`,`reddit`) | |
| name | varchar(255) | Tên hiển thị, ví dụ "Da Nang Forum (RU)" |
| identifier | varchar(255) | Username/ID của Telegram, URL group FB, URL forum |
| language_hint | varchar(8) null | `en`, `ru`… |
| is_active | bool | |
| rules_note | text null | Luật của group, ví dụ "cấm quảng cáo, chỉ DM khi được mời". Hiển thị cho sale |
| last_item_at | datetime null | Dùng cho health check |
| meta | json null | Cấu hình riêng (maxItems…) |

Unique: (`type`, `identifier`).

### 5.2 `raw_items`: mọi item thu về (post, comment, tin nhắn)

| Cột | Kiểu | Ghi chú |
|---|---|---|
| source_id | FK sources | |
| external_id | varchar(191) | ID gốc trên nền tảng |
| parent_external_id | varchar(191) null | Nếu là comment hoặc reply |
| url | varchar(1024) | Link tới bài hoặc tin nhắn gốc |
| author_handle | varchar(191) null | Username công khai (chỉ để sale biết trả lời ai). KHÔNG lưu SĐT hay email |
| author_key | char(64) | sha256(source_type + author id gốc). Dùng để nhận diện môi giới lặp lại |
| text | mediumtext | Nội dung gốc |
| context_text | text null | Nội dung bài cha hoặc tin được reply (cắt 1.000 ký tự) |
| content_hash | char(64) | sha256(normalize(text)) |
| language | varchar(8) null | Kết quả detect |
| posted_at | datetime | |
| status | enum(`new`,`filtered_out`,`queued`,`classified`,`error`) | |
| filter_reason | varchar(64) null | `duplicate`, `lang_vi`, `negative_kw`, `no_positive_kw`, `too_short`, `known_agent`, `too_old` |
| classification | json null | Output thô của AI |
| ai_model | varchar(64) null | |
| ai_input_tokens / ai_output_tokens | int null | Theo dõi chi phí |

- Unique: (`source_id`, `external_id`).
- Index: `content_hash`, `author_key`, `status`, `posted_at`.

### 5.3 `leads`

| Cột | Kiểu | Ghi chú |
|---|---|---|
| raw_item_id | FK unique | 1 raw_item → tối đa 1 lead |
| intent | enum(`tenant_demand`,`leaving_handover`) | Chỉ hai loại này được tạo lead |
| stage | enum(`pre_arrival`,`in_city_searching`,`urgent`,`unknown`) | |
| language | varchar(8) | |
| summary_vi | varchar(500) | Tóm tắt tiếng Việt cho sale |
| arrival_date | date null | |
| duration_months | smallint null | |
| property_type | enum(`studio`,`1br`,`2br`,`3br_plus`,`house`,`villa`,`room`,`unknown`) | |
| bedrooms_min | tinyint null | |
| areas | json | Mảng slug khu vực, ví dụ `["an-thuong","my-khe"]` |
| budget_min_usd / budget_max_usd | int null | Đã quy đổi |
| budget_raw | varchar(100) null | Nguyên văn, ví dụ "15 млн донгов" |
| household | varchar(255) null | |
| requirements | json | Mảng tag chuẩn hóa (mục 9.4) |
| confidence | decimal(3,2) | |
| score | tinyint | 0–100 |
| status | enum(`new`,`assigned`,`contacted`,`viewing`,`negotiating`,`won`,`lost`,`invalid`,`archived`) | |
| assigned_to | FK users null | |
| notified_at | datetime null | |
| expires_at | datetime | Mặc định posted_at + 60 ngày (mục 15) |

Index: `status`, `score`, `stage`, `created_at`.

### 5.4 Các bảng phụ

- **`lead_activities`**: lead_id, user_id null, type (`status_change`, `note`, `reply_sent`, `ai_draft`, `system`), body text, meta json.
- **`reply_drafts`**: lead_id, language, body text, listing_ids json, model, created_by (system/user).
- **`listings`** (kho căn hộ, nhập tay ở MVP):
  - title, area_slug, property_type, bedrooms, price_usd, min_term_months, pet_friendly bool, furnished bool, features json, available_from date, url null, is_active.
- **`lead_listing_matches`**: lead_id, listing_id, match_score tinyint, unique (lead_id, listing_id).
- **`keywords`**: language, kind (`positive`,`negative`,`area`), term, weight tinyint default 1, is_regex bool, is_active.
- **`areas`**: slug, name_en, name_vi, aliases json (gồm biến thể tiếng Anh, tiếng Việt không dấu, tiếng Nga, tiếng Hàn).
- **`known_agents`**: author_key unique, reason, created_by. Tự động thêm khi một author có ≥ 3 item bị AI gán `agent` hoặc `landlord_offer`.
- **`settings`** (key-value): tỷ giá, ngưỡng thông báo, giờ yên lặng…
- **`users`**: dùng mặc định của Laravel, thêm cột `role` và `telegram_username` null.

---

## 6. Collectors (thu thập dữ liệu)

### 6.1 Schema chuẩn hóa chung (Python, pydantic)

```python
class IngestItem(BaseModel):
    source_type: Literal["telegram", "facebook", "forum"]
    source_identifier: str          # khớp sources.identifier
    external_id: str
    parent_external_id: str | None = None
    url: str
    author_id: str | None = None    # ID gốc, collector KHÔNG gửi lên; chỉ dùng để tính author_key
    author_key: str | None = None   # sha256 tính sẵn ở collector
    author_handle: str | None = None
    text: str
    context_text: str | None = None
    posted_at: datetime             # UTC, ISO 8601
```

- Collector tự tính `author_key` và **không gửi `author_id` thô** lên server.
- Gửi theo batch tối đa 50 item. Retry tối đa 5 lần với exponential backoff khi gặp lỗi 5xx hoặc lỗi mạng.
- Khi API không phản hồi, buffer tạm vào file `spool/*.jsonl` và gửi lại sau.

### 6.2 collector-telegram (ưu tiên số 1)

- **Thư viện:** Telethon. **Xác thực:** `TG_API_ID`, `TG_API_HASH` (lấy tại my.telegram.org), session lưu trong volume `./data/telegram.session`.
- **Đăng nhập lần đầu:** script tương tác `python -m telegram.login`. Người dùng tự nhập số điện thoại và mã OTP. Claude Code KHÔNG tự động hóa bước này.
- **Tài khoản:** dùng **tài khoản riêng**, chỉ đọc. Người dùng tự join group bằng tay. Code không gọi bất kỳ API join hoặc gửi tin nào.
- **Danh sách group:** lấy từ `GET /api/collector/sources?type=telegram` (Laravel trả danh sách đang active), refresh mỗi 10 phút. Chỉ xử lý tin từ những chat có trong danh sách.
- **Realtime:** handler `events.NewMessage(chats=<danh sách>)`.
  - Bỏ qua tin không có text, tin từ bot, tin forward từ channel quảng cáo (có `fwd_from` + `channel_post`).
  - Nếu tin là reply thì lấy tin gốc (`get_reply_message`) làm `context_text`.
  - `external_id` = `f"{chat_id}:{message_id}"`.
  - `url`: nếu chat có username thì `https://t.me/{username}/{message_id}`; nếu không thì `https://t.me/c/{internal_id}/{message_id}`. internal_id là chat_id bỏ tiền tố `-100`. **[VERIFY]**
- **Backfill khi khởi động:** đọc tối đa 200 tin gần nhất trong 48 giờ của mỗi group. Server tự dedupe.
- **Xử lý giới hạn:** bắt `FloodWaitError` và sleep đúng số giây yêu cầu. Log cảnh báo.
- **Heartbeat:** mỗi 5 phút gọi `POST /api/collector/heartbeat` với `{collector: "telegram", ok: true}`.

### 6.3 collector-facebook

- Dùng `apify-client` (Python) để chạy actor crawl bài viết Facebook group public.
- Actor ID cấu hình qua `APIFY_FB_ACTOR`. Input schema của actor **[VERIFY]** bằng cách đọc README của actor đó. Tham số tối thiểu: danh sách URL group, giới hạn số bài, lọc theo ngày.
- Chạy theo lịch mỗi `FB_POLL_MINUTES` (mặc định 60). Lấy bài đăng sau thời điểm `max(last_item_at, now - 48h)`.
- Map các trường của dataset sang `IngestItem`. Comment để Phase 3 (actor riêng, chỉ lấy comment của bài đã thành lead hoặc bài có từ khóa).
- Ghi lại chi phí mỗi lần chạy (từ `usageTotalUsd` của run nếu có) vào log.

### 6.4 collector-forum (Expat.com, mục Đà Nẵng)

- URL gốc: `https://www.expat.com/en/forum/asia/vietnam/da-nang/`
- **Trước khi code phải đọc `robots.txt` và tuân thủ.** Nếu forum bị disallow thì dừng và báo lại, không crawl.
- Rate limit: 1 request / 5 giây, kèm User-Agent rõ ràng có email liên hệ (từ `.env`).
- Mỗi lần chạy:
  1. Đọc 2 trang danh sách thread đầu tiên.
  2. Với thread cập nhật trong 7 ngày gần nhất, đọc bài đầu tiên và các reply mới.
  3. Mỗi bài là một `IngestItem`; reply có `parent_external_id` = ID thread.
- Selector HTML: **[VERIFY]**. Tải thử trang thật, lưu fixture HTML vào `collectors/tests/fixtures/` rồi viết parser và test dựa trên fixture đó.
- Chỉ đọc forum. Không đọc mục Housing (toàn tin rao).

### 6.5 Reddit (tắt, Phase 4)

Chỉ tạo stub interface. Không triển khai cho đến khi có quyền API thương mại.

---

## 7. Ingest API

Tất cả endpoint nằm dưới `/api`, xác thực bằng `Authorization: Bearer {INGEST_TOKEN}`, rate limit 120 req/phút.

| Method | Path | Mô tả |
|---|---|---|
| POST | `/api/ingest/items` | Body `{items: IngestItem[]}` (≤ 50). Trả `{accepted, duplicates, rejected: [{index, error}]}` |
| GET | `/api/collector/sources?type=` | Danh sách source active của loại đó |
| POST | `/api/collector/heartbeat` | Ghi nhận collector còn sống |

**Xử lý ingest:**

1. Validate từng item.
2. Tìm `source` theo (type, identifier). Nếu chưa có thì tự tạo với `is_active=true`, log cảnh báo.
3. `insertOrIgnore` theo unique (`source_id`, `external_id`).
4. Với item mới, dispatch `PrefilterItemJob` (queue `prefilter`).
5. Cập nhật `sources.last_item_at`.

---

## 8. Tiền lọc rule-based

`PrefilterItemJob` chạy theo thứ tự, gặp lý do loại đầu tiên thì dừng và ghi `filter_reason`.

| # | Bước | Quy tắc |
|---|---|---|
| 1 | `too_old` | `posted_at` cũ hơn 14 ngày |
| 2 | `too_short` | Văn bản sau chuẩn hóa ít hơn 15 ký tự |
| 3 | `duplicate` | `content_hash` đã tồn tại trong 30 ngày (bắt người đăng cùng nội dung vào nhiều group) |
| 4 | `known_agent` | `author_key` có trong `known_agents` |
| 5 | `lang_vi` | Ngôn ngữ phát hiện là `vi` (dùng thư viện detect ngôn ngữ phía PHP, hoặc heuristic: > 30% ký tự có dấu tiếng Việt) |
| 6 | `negative_kw` | Điểm keyword âm ≥ 2 **và** không có keyword dương mạnh nào |
| 7 | `no_positive_kw` | Không có keyword dương nào **và** không nhắc tới nhà ở (bộ từ tối thiểu: apartment, flat, house, studio, rent, квартир, жиль, аренд, сниму…) |

- **Chuẩn hóa văn bản:** lowercase, bỏ URL, gộp khoảng trắng, bỏ emoji khi so khớp keyword (văn bản gốc giữ nguyên).
- Keyword và alias khu vực nạp từ DB, cache 10 phút.
- Item qua được hết các bước: `status=queued`, dispatch `ClassifyItemJob` (queue `ai`).
- **Mục tiêu:** loại ≥ 70% item trước khi gọi AI. Thống kê tỷ lệ lọc theo `filter_reason` trên dashboard.

---

## 9. AI phân loại

### 9.1 Gọi API

- `ClaudeClient` dùng Laravel HTTP client gọi Messages API của Anthropic (`POST https://api.anthropic.com/v1/messages`, header `x-api-key`, `anthropic-version`). Kiểm tra định dạng request mới nhất tại docs.claude.com **[VERIFY]**.
- Model mặc định: `AI_MODEL_CLASSIFY` (gợi ý `claude-haiku-5-5`). Nếu `confidence < 0.6` thì gọi lại một lần với `AI_MODEL_ESCALATE` (gợi ý `claude-sonnet-5-5`).
- **Bắt buộc dùng tool use** để nhận JSON có cấu trúc: định nghĩa một tool `record_classification` với `input_schema` như mục 9.3 và ép `tool_choice` vào tool này.
- Timeout 30 giây, retry 3 lần khi gặp 429 hoặc 5xx (tôn trọng header retry-after). Queue `ai` chạy tối đa 4 worker song song.
- Lưu `classification`, `ai_model` và token vào `raw_items`.

### 9.2 Prompt hệ thống (`resources/prompts/classify.v1.md`)

```
You classify social media posts and chat messages for a long-term rental agency
in Da Nang, Vietnam that serves FOREIGNERS (expats, digital nomads, retirees).

Decide what the author wants, and extract structured details.
The text may be in English, Russian, Korean, Chinese or other languages.
Context (the parent post or replied-to message) may be provided; use it only
to understand the message, classify the MESSAGE itself.

Intent definitions:
- tenant_demand: the author (or their family/partner) personally needs a place to rent in
  Da Nang (or Hoi An together with Da Nang). Includes asking for recommendations of apartments,
  areas, or agents for their own move.
- leaving_handover: the author is leaving and wants someone to take over their lease/apartment.
- landlord_offer: an owner or tenant advertises a property for rent (not a handover).
- agent: a broker/agency advertises services or multiple listings.
- short_term: the need is under 1 month (hotel/Airbnb style).
- other_city: the need is outside Da Nang/Hoi An.
- other: anything else (general questions, visa, bikes, chit-chat).

Rules:
- If the author is offering, it is NEVER tenant_demand.
- "Moving to Da Nang in January, which area is best for families?" IS tenant_demand.
- stage: pre_arrival if they are not in Da Nang yet; urgent if they need a place within 7 days;
  in_city_searching if already in Da Nang; otherwise unknown.
- Convert relative dates using the message date: {{posted_at}}.
- Do not invent details. Use null when unknown.
- summary_vi: one Vietnamese sentence (max 200 chars) for the sales team.
- Never output phone numbers, emails or full names.
```

User message gồm: `posted_at`, tên source, `context_text` (nếu có), `text`.

### 9.3 `input_schema` của tool `record_classification`

```json
{
  "type": "object",
  "required": ["intent", "confidence", "language", "summary_vi"],
  "properties": {
    "intent": {"enum": ["tenant_demand","leaving_handover","landlord_offer","agent","short_term","other_city","other"]},
    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    "language": {"type": "string", "description": "ISO 639-1"},
    "stage": {"enum": ["pre_arrival","in_city_searching","urgent","unknown"]},
    "arrival_date": {"type": ["string","null"], "description": "YYYY-MM-DD"},
    "duration_months": {"type": ["integer","null"]},
    "property_type": {"enum": ["studio","1br","2br","3br_plus","house","villa","room","unknown"]},
    "bedrooms_min": {"type": ["integer","null"]},
    "areas_mentioned": {"type": "array", "items": {"type": "string"}},
    "budget": {
      "type": ["object","null"],
      "properties": {
        "min": {"type": ["number","null"]},
        "max": {"type": ["number","null"]},
        "currency": {"enum": ["USD","VND","RUB","EUR","KRW","AUD","GBP","OTHER"]},
        "period": {"enum": ["month","total","unknown"]},
        "raw": {"type": "string"}
      }
    },
    "household": {"type": ["string","null"]},
    "requirements": {"type": "array", "items": {"type": "string"}},
    "summary_vi": {"type": "string", "maxLength": 200}
  }
}
```

### 9.4 Hậu xử lý (`LeadClassifier`)

1. Chỉ tạo `lead` khi `intent ∈ {tenant_demand, leaving_handover}` **và** `confidence ≥ 0.6`. Các trường hợp khác đặt `raw_items.status=classified`, không tạo lead.
2. **Map khu vực:** so `areas_mentioned` với `areas.aliases` (không phân biệt hoa thường, bỏ dấu). Khu vực không khớp được ghi log để bổ sung alias.
3. **Quy đổi ngân sách** sang USD/tháng bằng `CurrencyConverter` (tỷ giá trong `settings`, cập nhật tay hoặc theo job). `period=total` mà có `duration_months` thì chia ra theo tháng.
4. **Chuẩn hóa requirements** về bộ tag cố định: `pet_friendly`, `furnished`, `pool`, `gym`, `bathtub`, `kitchen`, `workspace`, `fast_wifi`, `near_beach`, `quiet`, `sea_view`, `river_view`, `family`, `school_nearby`, `parking`, `balcony`, `washing_machine`. Tag không khớp thì bỏ qua.
5. **Cập nhật `known_agents`:** nếu `intent ∈ {agent, landlord_offer}`, đếm số item cùng `author_key`; khi đạt ≥ 3 thì thêm vào `known_agents`.
6. Tạo lead → `LeadScorer` → `ListingMatcher` → nếu `score ≥ NOTIFY_MIN_SCORE` thì dispatch `DraftReplyJob`, sau đó `NotifyLeadJob`.

---

## 10. Chấm điểm lead

`LeadScorer` là hàm thuần (pure function) để dễ test. Trọng số đặt trong `config/leadradar.php`.

| Tiêu chí | Điểm |
|---|---|
| intent = `tenant_demand` | +10 |
| stage = `urgent` | +25 |
| stage = `pre_arrival` và arrival_date trong 60 ngày tới | +25 |
| stage = `in_city_searching` | +15 |
| duration_months ≥ 6 | +20 |
| duration_months từ 3 đến 5 | +10 |
| Ngân sách trong khoảng giá kho (min–max `listings.price_usd` đang active), ±15% | +15 |
| Có ít nhất 1 khu vực khớp `areas` | +10 |
| Có ít nhất 1 listing khớp với match_score ≥ 60 | +10 |
| Bài đăng dưới 12 giờ | +10 |
| confidence < 0.75 | −10 |

- Giới hạn kết quả trong khoảng 0–100.
- `leaving_handover` không chấm theo công thức này: đặt `score = 50` cố định và gắn tag "Nguồn hàng" trên dashboard.

---

## 11. Khớp kho căn hộ

`ListingMatcher` trả về tối đa 5 listing, sắp theo `match_score` giảm dần (chỉ lấy ≥ 40).

| Tiêu chí | Điểm |
|---|---|
| area_slug nằm trong lead.areas (hoặc lead không nêu khu vực: +15) | +30 |
| Giá ≤ budget_max_usd (nếu vượt dưới 10%: +10) | +25 |
| bedrooms ≥ bedrooms_min hoặc cùng property_type | +20 |
| min_term_months ≤ duration_months (hoặc duration không rõ) | +10 |
| Mỗi requirement thỏa mãn (pet_friendly, furnished, features…) | +5, tối đa +15 |

Kết quả lưu vào `lead_listing_matches`.

---

## 12. AI soạn câu trả lời

- `DraftReplyJob` dùng model `AI_MODEL_DRAFT` (gợi ý `claude-sonnet-5-5`) và prompt `resources/prompts/reply.v1.md`.
- **Đầu vào:** text gốc, ngôn ngữ, tóm tắt lead, tối đa 3 listing khớp (tiêu đề, khu vực, giá, đặc điểm chính, url), `sources.rules_note`, thông tin agency từ settings (tên, website).
- **Yêu cầu với bản nháp:**
  - Viết đúng ngôn ngữ của người đăng.
  - 60–120 từ, giọng thân thiện, hữu ích trước rồi mới giới thiệu.
  - Trả lời đúng câu hỏi của họ (ví dụ khu nào hợp gia đình, mức giá thực tế).
  - Nhắc tối đa 3 căn khớp.
  - Gợi ý xem căn qua video call hoặc trực tiếp.
- **Không được:** chèn số điện thoại, hứa hẹn sai sự thật, gây áp lực ("only today"), hay vi phạm `rules_note` (ví dụ group cấm link thì bỏ link).
- Tạo **hai biến thể**: `public_reply` (trả lời công khai dưới bài) và `dm` (chỉ dùng khi người đăng mời nhắn riêng). Lưu vào `reply_drafts`.
- Sale chỉnh sửa trên dashboard rồi bấm "Copy". Hệ thống **không bao giờ tự gửi**.

---

## 13. Thông báo Telegram

- Bot riêng (`TG_BOT_TOKEN`) gửi vào group chat của team (`TG_TEAM_CHAT_ID`).
- Gửi khi lead mới có `score ≥ NOTIFY_MIN_SCORE` (mặc định 60) hoặc `intent = leaving_handover`.
- Không gửi lại lead đã có `notified_at`.
- Mẫu tin nhắn (parse mode HTML, escape đúng cách):

```
🔥 Lead 82 điểm · 🇷🇺 RU · Telegram: Da Nang Forum
Cặp đôi người Nga đến 15/12, thuê 6 tháng căn 1PN ở An Thượng, ≤ 450$, nuôi mèo
⏱ Đăng 6 phút trước · Giai đoạn: chưa sang VN
🏠 Khớp 3 căn (tốt nhất: Sea Breeze 1BR – 420$)
[Mở bài gốc]  [Mở trên dashboard]
```

- **Giờ yên lặng** (`QUIET_HOURS`, ví dụ `23-07`): vẫn lưu lead nhưng gom lại thành một tin tóm tắt lúc hết giờ yên lặng. Riêng lead `urgent` vẫn gửi ngay.
- Heartbeat: nếu một collector không gửi heartbeat quá 30 phút, hoặc source quan trọng không có item mới quá 24 giờ, gửi cảnh báo ⚠️ vào group.

---

## 14. Dashboard quản trị (Filament)

Dùng bản Filament ổn định mới nhất tương thích với phiên bản Laravel. Giao diện tiếng Việt.

### 14.1 Leads (màn hình chính)

- **Bảng:** score (badge màu), summary_vi, ngôn ngữ (cờ), stage, khu vực, ngân sách USD, thời gian thuê, source, thời gian đăng (relative), status, người phụ trách.
- **Bộ lọc:** status, score ≥ X, stage, ngôn ngữ, khu vực (multi), khoảng ngân sách, duration ≥ X, source type, intent, thời gian đăng (6h / 24h / 3 ngày / 7 ngày), "của tôi".
- Mặc định sắp theo score giảm dần, rồi đến created_at.
- **Hành động nhanh:** Nhận lead, đổi status, đánh dấu `invalid` (kèm lý do; dữ liệu này dùng để cải thiện prompt), "Là môi giới" (thêm author vào `known_agents`).
- Badge đếm lead `new` trên menu, tự refresh (polling 30 giây).

### 14.2 Chi tiết lead

- Văn bản gốc, context, link bài gốc (mở tab mới), `rules_note` của group.
- Các trường AI đã trích xuất, cho phép sửa tay.
- Danh sách listing khớp kèm match_score.
- Bản nháp trả lời (2 biến thể), có nút "Tạo lại", "Copy" và ô chỉnh sửa.
- Timeline hoạt động; khi đổi status bắt buộc nhập ghi chú cho các trạng thái `lost` và `invalid`.

### 14.3 Các màn hình khác

- **Listings:** CRUD kho căn hộ.
- **Sources:** CRUD, bật/tắt, `rules_note`, health (last_item_at, số item và lead trong 7 ngày).
- **Keywords và Areas:** CRUD, kèm ô "test nhanh" (dán một đoạn text để xem kết quả prefilter).
- **Raw items:** tra cứu, lọc theo `filter_reason`, nút "Phân loại lại".
- **Settings:** tỷ giá, ngưỡng, giờ yên lặng, thông tin agency.

### 14.4 Dashboard widgets

- Lead mới hôm nay / 7 ngày, theo nguồn và theo ngôn ngữ.
- Phễu: item thu → qua prefilter → lead → contacted → won.
- Tỷ lệ `invalid` (đo độ chính xác AI thực tế).
- Chi phí AI ước tính trong ngày (token × đơn giá trong config).

---

## 15. Quyền riêng tư và lưu trữ dữ liệu

Hệ thống tuân theo nguyên tắc tối thiểu hóa dữ liệu (căn cứ Luật Bảo vệ dữ liệu cá nhân 91/2025/QH15, hiệu lực từ 01/01/2026).

- **Chỉ lưu:** nội dung bài, link gốc, username công khai và `author_key` (hash). Không lưu SĐT, email, ảnh, profile, hay ID người dùng dạng thô.
- Nếu text gốc có SĐT hoặc email thì **che bằng regex** trước khi lưu (`[phone]`, `[email]`). Áp dụng cho cả `text` và `context_text`.
- **Job dọn dữ liệu chạy hằng ngày:**
  - `raw_items` có status `filtered_out` hoặc `classified` mà không thành lead: xóa sau 14 ngày.
  - `leads` có status `new`, `assigned`, `lost`, `invalid` đã quá `expires_at` (60 ngày): xóa lead, raw_item, drafts và matches.
  - `leads` có status `won`: giữ lại, nhưng ẩn danh hóa `text` sau 180 ngày.
- **Xóa theo yêu cầu:** admin tìm theo `author_handle` và bấm "Xóa toàn bộ dữ liệu người này".
- Không có chức năng export hàng loạt dữ liệu lead ra CSV.
- Log ứng dụng không ghi nội dung text đầy đủ (chỉ ghi id và 80 ký tự đầu).

---

## 16. Cấu hình (.env)

```dotenv
# Laravel
APP_URL=http://localhost:8080
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=leadradar
DB_USERNAME=leadradar
DB_PASSWORD=
QUEUE_CONNECTION=redis
REDIS_HOST=redis

# Bảo mật nội bộ
INGEST_TOKEN=                # chuỗi ngẫu nhiên dài, dùng chung cho collectors

# Anthropic
ANTHROPIC_API_KEY=
AI_MODEL_CLASSIFY=claude-haiku-5-5
AI_MODEL_ESCALATE=claude-sonnet-5-5
AI_MODEL_DRAFT=claude-sonnet-5-5
AI_DAILY_BUDGET_USD=5        # vượt ngân sách: tạm dừng queue ai và cảnh báo

# Telegram bot (thông báo)
TG_BOT_TOKEN=
TG_TEAM_CHAT_ID=
NOTIFY_MIN_SCORE=60
QUIET_HOURS=23-07
APP_TIMEZONE=Asia/Ho_Chi_Minh

# Telegram listener (collector)
TG_API_ID=
TG_API_HASH=
TG_SESSION_PATH=/data/telegram.session

# Apify
APIFY_TOKEN=
APIFY_FB_ACTOR=
FB_POLL_MINUTES=60

# Forum
FORUM_POLL_MINUTES=60
CRAWLER_USER_AGENT="ExpatLeadRadar/1.0 (+mailto:you@example.com)"

# Collectors → app
INGEST_URL=http://app/api
```

---

## 17. Yêu cầu phi chức năng

| Hạng mục | Yêu cầu |
|---|---|
| Độ trễ | Tin Telegram → thông báo team: p95 dưới 60 giây |
| Độ chính xác | Precision của `tenant_demand` ≥ 85% trên tập đánh giá (mục 18) |
| Chi phí AI | Ước tính dưới 5 USD/ngày ở quy mô ~2.000 item/ngày; có ngân sách cứng `AI_DAILY_BUDGET_USD` |
| Độ bền | Restart bất kỳ container nào không mất dữ liệu; queue retry; collector dùng spool khi API lỗi |
| Bảo mật | Dashboard bắt buộc đăng nhập; API dùng token; secret chỉ đặt trong `.env`; không commit session Telegram |
| Logging | Log JSON có trường `source`, `item_id`, `lead_id`, `job` |
| Múi giờ | Lưu UTC, hiển thị theo `Asia/Ho_Chi_Minh` |

---

## 18. Kiểm thử và đánh giá AI

### 18.1 Unit và Feature test (Laravel, Pest hoặc PHPUnit)

- `Prefilter`: mỗi `filter_reason` có ít nhất 2 case.
- `LeadScorer`, `ListingMatcher`, `CurrencyConverter`: test bảng giá trị (data provider).
- `IngestController`: idempotent (gửi trùng 2 lần ra 1 bản ghi), sai token trả 401, batch vượt 50 item trả 422.
- `LeadClassifier`: **mock** `ClaudeClient` bằng fixture JSON. Test không được gọi API thật.
- Che SĐT và email: test regex với định dạng số Việt Nam, Nga và quốc tế.

### 18.2 Collector test (pytest)

- Parser Expat.com chạy trên fixture HTML thật đã lưu.
- Hàm map của Telegram và Apify sang `IngestItem` (dùng object giả).

### 18.3 Bộ đánh giá AI

- File `app/tests/Fixtures/samples.jsonl` với **ít nhất 60 mẫu tự viết** (KHÔNG dùng bài thật của người khác), gồm:
  - 20 `tenant_demand` (EN/RU, đủ các stage),
  - 5 `leaving_handover`,
  - 15 `landlord_offer` hoặc `agent`,
  - 5 `short_term`,
  - 5 `other_city`,
  - 10 `other`.
- Mỗi dòng: `{"text": "...", "context": null, "posted_at": "...", "expected_intent": "...", "expected_stage": "..."}`.
- Lệnh `php artisan leadradar:eval` chạy toàn bộ mẫu qua AI thật, in confusion matrix cùng precision/recall của `tenant_demand`. Đạt yêu cầu khi precision ≥ 0.85 và recall ≥ 0.80.

---

## 19. Lộ trình triển khai và tiêu chí nghiệm thu

Mỗi phase là **một phiên làm việc với Claude Code**. Chỉ chuyển phase khi đạt hết tiêu chí nghiệm thu.

### Phase 1 — Nền móng (Laravel + DB + Ingest)

- docker-compose: `app` (php-fpm + nginx), `queue` (worker), `scheduler`, `mysql:8.4`, `redis:7`.
- Laravel, migration toàn bộ bảng ở mục 5, Enum, Model, seeder (areas, keywords ở mục 20, 1 admin, 5 listing mẫu).
- Ingest API (mục 7) kèm test.
- Lệnh `php artisan leadradar:ingest-file storage/samples.jsonl` để nạp item thử mà chưa cần collector.

**Nghiệm thu:** `docker compose up` chạy được; `php artisan test` xanh; nạp file mẫu vào thì `raw_items` có dữ liệu, gửi trùng không tạo bản ghi mới.

### Phase 2 — Bộ não (Prefilter + AI + Score + Match + Notify)

- Mục 8 → 11 và 13. Prompt đặt trong `resources/prompts/`.
- Lệnh `leadradar:eval` (mục 18.3) và file 60 mẫu.

**Nghiệm thu:** eval đạt ngưỡng; nạp file mẫu thì lead điểm cao xuất hiện trong group Telegram của team trong vòng 60 giây.

### Phase 3 — Dashboard

- Mục 12 và 14 (Filament: Leads, chi tiết lead, bản nháp trả lời, Listings, Sources, Keywords/Areas, Settings, widgets).

**Nghiệm thu:** sale đăng nhập, lọc lead, nhận lead, xem căn khớp, copy bản nháp, đổi trạng thái và có lịch sử hoạt động.

### Phase 4 — Collector Telegram

- Mục 6.1 và 6.2, kèm Dockerfile, script đăng nhập và README hướng dẫn lấy API ID.

**Nghiệm thu:** gửi một tin "Looking for 1BR in An Thuong from Dec, 6 months, budget 450$" vào một group test thì nhận được thông báo Telegram với link mở đúng tin nhắn gốc.

### Phase 5 — Collector Facebook và Forum

- Mục 6.3 và 6.4 (đọc README actor và robots.txt trước).

**Nghiệm thu:** chạy một vòng cho mỗi nguồn ra item hợp lệ; health trên dashboard hiển thị `last_item_at`.

### Phase 6 — Vận hành

- Job dọn dữ liệu (mục 15), che SĐT/email, ngân sách AI, heartbeat và cảnh báo, gom tin giờ yên lặng, README triển khai lên VPS.

**Nghiệm thu:** test retention xanh; tắt collector Telegram 30 phút thì team nhận cảnh báo.

### Phase 7 (tùy chọn, sau này)

Comment Facebook, Reddit (khi có quyền API), báo cáo chuyển đổi theo nguồn, tích hợp với nền tảng cho thuê chính (API đồng bộ listings).

---

## 20. Phụ lục: dữ liệu seed

### 20.1 Khu vực (`areas`)

> Đây là tên khu vực theo cách expat thường dùng, không phải đơn vị hành chính chính thức.

| slug | name_en | aliases (gợi ý, bổ sung dần) |
|---|---|---|
| an-thuong | An Thuong | an thượng, an thuong, "expat street", ан тхыонг, антхыонг |
| my-khe | My Khe | mỹ khê, my khe beach, ми кхе, микхе |
| son-tra | Son Tra | sơn trà, son tra peninsula, шонча, сон тра |
| ngu-hanh-son | Ngu Hanh Son | ngũ hành sơn, marble mountains, нгуханьшон, мраморные горы |
| hai-chau | Hai Chau | hải châu, city center, downtown, han river, хайчау, центр |
| thanh-khe | Thanh Khe | thanh khê, тхань кхе |
| lien-chieu | Lien Chieu | liên chiểu, nam o, льен тьеу |
| hoa-xuan | Hoa Xuan | hòa xuân, хоа суан |
| fpt-city | FPT City | fpt city, фпт сити |
| non-nuoc | Non Nuoc | non nước, non nuoc beach |
| hoi-an | Hoi An | hội an, an bang, хойан |

### 20.2 Keyword dương

- **en:** looking for, searching for, need a place, need an apartment, need a house, long term, long-term, monthly rent, moving to da nang, relocating, arriving in, any recommendations, recommend an agent, studio, 1br, 2br, one bedroom, two bedroom, pet friendly, where to rent
- **ru:** ищу, ищем, сниму, снимем, нужна квартира, нужен дом, долгосрок, на длительный срок, на полгода, на год, переезжаем, переезжаю, посоветуйте, подскажите, студию, однушку, двушку, с животными

### 20.3 Keyword âm

- **en:** for rent, available now, available from, contact me, dm for price, whatsapp, viewing anytime, price:, /month only, booking now
- **ru:** сдаю, сдам, сдается, свободна, свободен, пишите в лс, цена:, бронируйте, звоните

### 20.4 Từ nhận diện chủ đề nhà ở (cho bước 7 của prefilter)

apartment, flat, house, villa, studio, room, rent, lease, landlord, condo, квартир, жиль, дом, аренд, студи, комнат

### 20.5 Settings mặc định

```json
{
  "fx_per_usd": {"VND": 26000, "RUB": 80, "EUR": 0.92, "KRW": 1380, "AUD": 1.53, "GBP": 0.79},
  "notify_min_score": 60,
  "quiet_hours": "23-07",
  "agency_name": "YOUR AGENCY",
  "agency_website": "https://example.com"
}
```

> `fx_per_usd` = số đơn vị ngoại tệ đổi được 1 USD (USD = amount / rate). Đây chỉ là giá trị khởi tạo ước lượng, cần cập nhật tỷ giá thật trước khi chạy.
