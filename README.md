# Expat Lead Radar

Đặc tả đầy đủ: [SPEC.md](SPEC.md). Quy ước làm việc: [CLAUDE.md](CLAUDE.md).

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
