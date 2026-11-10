# Expat Lead Radar

Đặc tả đầy đủ: [SPEC.md](SPEC.md). Quy ước làm việc: [CLAUDE.md](CLAUDE.md). **Trạng thái và bàn giao: [docs/HANDOVER.md](docs/HANDOVER.md).**

## Chạy bằng Docker

```bash
cp .env.example .env
# Điền DB_PASSWORD, DB_ROOT_PASSWORD, INGEST_TOKEN, rồi tạo APP_KEY:
docker compose run --rm --no-deps app php artisan key:generate --show   # dán kết quả vào APP_KEY

docker compose up -d --build
docker compose exec app php artisan migrate --seed     # in mật khẩu admin nếu ADMIN_PASSWORD trống
docker compose exec app php artisan test
docker compose exec app php artisan leadradar:ingest-file storage/samples.jsonl
```

| Service | Vai trò |
|---|---|
| `app` | Laravel (php-fpm + nginx, image `serversideup/php:8.4-fpm-nginx`), cổng `8080`. Tự chạy migration khi khởi động |
| `queue` | Laravel Horizon: queue `notify`, `prefilter`, `default` và `ai` (tối đa `AI_QUEUE_WORKERS` = 4). UI tại `/horizon`, chỉ admin |
| `scheduler` | `php artisan schedule:work` |
| `mysql` | MySQL 8.4, `utf8mb4_unicode_ci` |
| `redis` | Redis 7, bật AOF để job không mất khi restart |

Code được build vào image. Sửa code thì chạy `docker compose up -d --build`.

## Chạy test không cần Docker

Cần PHP ≥ 8.3 và Composer. Test dùng SQLite in-memory, không gọi API ngoài.

```bash
cd app
composer install   # composer.json khai báo sẵn ext-pcntl/posix để cài được trên Windows
php artisan test
```

## Chạy dashboard local không cần Docker (để thử)

Dùng SQLite và queue `database` thay cho MySQL/Redis. Horizon không chạy được ở chế độ này; thay bằng `queue:work`.

```bash
cd app
# .env: DB_CONNECTION=sqlite, QUEUE_CONNECTION=database, CACHE_STORE=database,
#       ADMIN_EMAIL/ADMIN_PASSWORD, INGEST_TOKEN, APP_URL=http://127.0.0.1:8000
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoLeadSeeder        # 7 lead mẫu tự viết, không cần AI key
php artisan leadradar:ingest-file storage/samples.jsonl
php artisan serve --host=127.0.0.1 --port=8000     # cửa sổ 1
php artisan queue:work --queue=notify,prefilter,default,ai   # cửa sổ 2 (chế độ keyword cũng chạy trên queue ai, miễn phí)
```

Mở http://127.0.0.1:8000/admin.

## Phân loại bằng AI hoặc keyword

Không bắt buộc có `ANTHROPIC_API_KEY`. Chọn chế độ ở **Cài đặt → Phân loại tin** (mặc định lấy từ `CLASSIFIER_MODE`):

| Chế độ | Khi nào dùng AI | Ghi chú |
|---|---|---|
| `auto` (mặc định) | Có key và còn ngân sách ngày | Hết ngân sách hoặc AI lỗi thì tự chuyển sang keyword, team nhận 1 cảnh báo |
| `ai` | Luôn | Hết ngân sách thì hàng đợi AI tạm dừng tới 0h (như SPEC) |
| `keyword` | Không bao giờ | Không tốn phí; bản nháp trả lời lấy từ template |

Keyword classifier (`app/Services/Classify/RuleClassifier.php`):

- **Ý định**: cộng trọng số các keyword nhóm `Phân loại: …` (trang **Từ khóa**), nhóm có tổng cao nhất thắng. "Tìm thuê" chỉ được tính khi bài có nhắc tới nhà ở.
- **Giai đoạn**: keyword nhóm `Giai đoạn: …`, hoặc ngày đến nằm trong tương lai.
- **Ngân sách, số tháng, loại nhà, ngày đến, khu vực, yêu cầu**: đọc bằng regex tiếng Anh/Nga (`FieldExtractor`). Từ khóa cho từng yêu cầu nằm ở `config/leadradar.php` → `classifier.requirement_terms`.
- Sau bước phân loại, lead đi tiếp y hệt khi dùng AI: khớp căn, chấm điểm, báo Telegram.

Bản nháp trả lời khi không dùng AI (`app/Services/Drafts/TemplateDrafter.php`):

- Dùng khi ở chế độ `keyword`, khi chưa có key, hoặc ở chế độ `auto` khi hết ngân sách hay AI lỗi.
- Template EN/RU sửa ở **Cài đặt → Template bản nháp**; mặc định lấy từ `config/leadradar.php` → `drafts.templates`. Lead ngôn ngữ khác dùng template EN.
- Biến điền tự động: `{agency} {website} {area} {budget} {duration} {arrival} {listing} {listing_price} {listings}` (`{listings}` là tối đa 3 căn khớp nhất).
- Đoạn `[[ ... ]]` bị bỏ nếu có biến rỗng, ví dụ `[[ in {area}]]` biến mất khi lead không nêu khu vực.
- SĐT lỡ gõ vào template vẫn bị che. Nút **Tạo lại bản nháp** dùng AI hay template theo chế độ hiện tại.
- Thử ngay ở trang **Test prefilter** (hiện cả kết quả keyword). Đo độ chính xác: `php artisan leadradar:eval --engine=keyword`.

Độ chính xác đo được với `--engine=keyword`:

| Bộ mẫu | Precision | Recall | Ghi chú |
|---|---|---|---|
| `tests/Fixtures/samples.jsonl` (60) | 0.95 | 1.00 | Keyword mặc định được chọn dựa trên bộ này |
| `tests/Fixtures/samples_holdout.jsonl` (30), lần chạy đầu | 0.44 | 0.36 | Câu mới, keyword chưa từng gặp |
| `samples_holdout.jsonl` sau khi bổ sung từ đồng nghĩa | 0.92 | 1.00 | Không còn là bộ "mù" |

Con số thật trên bài đăng thật sẽ nằm giữa hai mức trên. Nên chạy keyword vài ngày, xem các lead bị đánh "Không hợp lệ" để bổ sung keyword, hoặc bật AI khi có key.

## Ingest API (SPEC 7)

Tất cả endpoint cần header `Authorization: Bearer $INGEST_TOKEN` và bị giới hạn 120 request/phút. Nếu `INGEST_TOKEN` để trống, mọi request đều bị từ chối.

| Method | Path | |
|---|---|---|
| POST | `/api/ingest/items` | `{items: IngestItem[]}`, tối đa 50 item. Trả `{accepted, duplicates, rejected: [{index, error}]}`. Batch vượt 50 item trả 422 |
| GET | `/api/collector/sources?type=telegram` | Danh sách source đang active |
| POST | `/api/collector/heartbeat` | `{collector, ok, meta?}` |

- `author_key = sha256("{source_type}:{author_id}")`, tính ở collector. Nếu client gửi `author_id` thô, server hash rồi bỏ, không lưu.
- SĐT và email trong `text` và `context_text` bị thay bằng `[phone]` / `[email]` trước khi lưu.
- Collector gọi app qua `http://app:8080/api`. Image serversideup lắng nghe cổng 8080, không phải 80.

## Khác biệt so với SPEC (đã chốt)

- `raw_items.author_key` cho phép null: item không xác định được tác giả thì không dùng chung một key.
- Thêm bảng `collector_heartbeats` để lưu heartbeat qua restart.
- `reply_drafts` có thêm cột `variant` (`public_reply` / `dm`, SPEC 12).
- Che SĐT/email làm ngay từ Phase 1, vì CLAUDE.md cấm lưu dữ liệu thô.
- Phase 2: chạy `ListingMatcher` trước `LeadScorer`, vì điểm lead phụ thuộc kết quả khớp căn.
- AI: dùng `tool_choice: auto` + `strict: true` thay vì ép `tool_choice` (Claude Sonnet 5.5 trả 400 khi ép). Không gọi tool thì hỏi lại 1 lần. Schema bỏ `minimum/maximum/maxLength` (strict không hỗ trợ), kiểm tra ở PHP (`Classification`).
- AI: API version `2026-10-21`, không gửi `temperature`. Model trong `AI_FALLBACK_MODELS` (mặc định Sonnet/Opus) được bật server-side refusal fallback (`fallbacks: "default"`).
- Prefilter `lang_vi`: tỷ lệ tính theo **từ** có dấu tiếng Việt (> 30%), không theo ký tự (văn bản tiếng Việt thuần chỉ ~25% ký tự có dấu).
- "Keyword dương mạnh" = keyword có `weight ≥ 2`.
- Facebook có thêm chế độ đọc bằng **phiên đăng nhập của chính bạn** (`facebook.session` + `facebook.browser`, Playwright), bên cạnh Apify. Chủ dự án quyết định ngày 2026-11-10: dùng cá nhân, phi thương mại. Cách này trái điều khoản và robots.txt của Facebook, tài khoản có thể bị checkpoint hoặc khóa. Giới hạn vẫn giữ: chỉ đọc (không click/gõ/like/comment/join), cuộn chậm, gặp login/checkpoint/CAPTCHA thì dừng (exit 2), cookie nằm trong `data/` (không commit). Đường dẫn JSON chưa kiểm chứng trên trang thật.
- Thêm chế độ phân loại bằng keyword (không cần API key) theo yêu cầu: `CLASSIFIER_MODE` / Cài đặt. `keywords.kind` đổi sang string để thêm nhóm `intent_*`, `stage_*`. Chưa có bot Telegram thì job báo lead bỏ qua (ghi log) thay vì báo lỗi.

## Phase 2 — pipeline

`ingest → PrefilterItemJob (queue prefilter) → ClassifyItemJob (queue ai) → LeadClassifier → ListingMatcher → LeadScorer → NotifyLeadJob (queue notify)`

```bash
docker compose exec app php artisan leadradar:eval          # gọi AI thật, cần ANTHROPIC_API_KEY
docker compose exec app php artisan leadradar:eval --limit=10
```

## Phase 3 — Dashboard (Filament 5)

Đăng nhập tại `http://localhost:8080/admin`.

| Vai trò | Thấy gì |
|---|---|
| `sales` | Leads (lọc, nhận, đổi trạng thái, ghi chú, bản nháp), kho căn hộ (chỉ xem), dashboard |
| `admin` | Toàn bộ, thêm Nguồn, Keyword, Khu vực, Dữ liệu thô, Test prefilter, Cài đặt, Người dùng, `/horizon` |

- Bản nháp trả lời: bấm vào nội dung để copy, dán và trả lời **thủ công** tại bài gốc, rồi bấm "Đã trả lời" để ghi vào lịch sử. Hệ thống không bao giờ tự gửi.
- Chuyển sang "Mất khách" hoặc "Không hợp lệ" bắt buộc nhập ghi chú.
- Lưu ý khi viết code Filament: tham số closure phải đặt đúng tên (`$query`, `$data`, `$record`, `$state`, `$get`), vì Filament inject theo tên.

## Phase 4–6

- Collectors (Telegram, Facebook qua Apify hoặc phiên trình duyệt, Expat.com): xem [collectors/README.md](collectors/README.md).
- Triển khai VPS và vận hành: xem [docs/DEPLOY.md](docs/DEPLOY.md).
- Scheduler: `leadradar:retention` (03:00 hằng ngày), `leadradar:health` và `leadradar:quiet-digest` (mỗi 5 phút).
- Nguồn "Quan trọng" (bật trong form Nguồn): cảnh báo nếu 24 giờ không có item mới.
- Xóa theo yêu cầu: **Dữ liệu thô → Xóa dữ liệu theo người đăng** (chỉ admin).
