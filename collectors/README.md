# Collectors

Collectors **chỉ đọc và chuẩn hóa**, rồi gửi lên `POST /api/ingest/items`. Mọi logic nghiệp vụ nằm trong Laravel.

- Không có code nào gửi tin, comment, DM, join group hay tạo tài khoản.
- ID người dùng thô chỉ dùng để tính `author_key = sha256("{source_type}:{id}")` ngay trong collector, không bao giờ được gửi lên server.
- API lỗi thì item được ghi tạm vào `/data/spool/*.jsonl` và gửi lại sau.

```bash
docker compose run --rm collectors pytest          # test, không gọi Telegram/Apify thật
```

## Telegram (Phase 4)

### 1. Lấy API ID / API hash

1. Dùng **một tài khoản Telegram riêng** cho việc lắng nghe, không dùng tài khoản cá nhân.
2. Đăng nhập https://my.telegram.org bằng số điện thoại của tài khoản đó.
3. Vào **API development tools**, tạo app (tên tùy ý, platform: Other).
4. Chép `api_id` và `api_hash` vào `.env` (`TG_API_ID`, `TG_API_HASH`). Không chia sẻ hai giá trị này.

### 2. Đăng nhập lần đầu (bạn tự nhập số điện thoại và OTP)

```bash
docker compose run --rm -it collector-telegram python -m telegram.login
```

Session được lưu vào `./data/telegram.session` (đã nằm trong `.gitignore`, **không commit**).

### 3. Join group và khai báo nguồn

1. Mở app Telegram bằng tài khoản listener và **tự join** các group cần theo dõi.
2. Trên dashboard, vào **Nguồn**, thêm source loại `telegram` với `identifier` là username của group (không có `@`), ví dụ `danang_expats`. Group không có username thì dùng ID dạng `-100…`.
3. Listener tự tải lại danh sách nguồn mỗi 10 phút; source mới được backfill 200 tin trong 48 giờ gần nhất.

> Group thường (basic group, ID không bắt đầu bằng `-100`) không có link tới từng tin nhắn. Hãy nâng cấp group đó lên supergroup, nếu không link "Mở bài gốc" sẽ không mở đúng tin.

### Chạy trên Windows không cần Docker

```powershell
cd collectors
copy .env.local.example .env.local      # điền TG_API_ID, TG_API_HASH (file này không commit)
.\run-local.ps1 telegram.login          # nhập số điện thoại, mã OTP (và mật khẩu 2FA nếu có)
.\run-local.ps1 telegram.listener       # để cửa sổ này mở; Ctrl+C để dừng
```

Laravel phải đang chạy (`php artisan serve` + `queue:work`). Session lưu ở `..\data\telegram.session`.

### 4. Chạy

```bash
docker compose up -d collector-telegram
docker compose logs -f collector-telegram
```

Gửi thử câu "Looking for 1BR in An Thuong from Dec, 6 months, budget 450$" vào group test. Team sẽ nhận thông báo Telegram có link mở đúng tin gốc.

## Facebook qua Apify (Phase 5)

- Actor mặc định: `apify/facebook-groups-scraper` (`APIFY_FB_ACTOR`). Input/output đã đối chiếu với README của actor (2026-11-09).
- Chỉ group **công khai**. Thêm source loại `facebook` với `identifier` là URL group, `meta` có thể đặt `{"maxItems": 50}`.
- Mỗi vòng (mặc định 60 phút) chạy 1 lần actor cho mỗi group, chỉ lấy bài sau `max(last_item_at, now − 48h)`. Chi phí mỗi run (`usage_total_usd`) được ghi vào log.
- Comment Facebook để Phase 7.

```bash
docker compose run --rm collector-facebook python -m facebook.run --once
```

## Expat.com forum (Phase 5)

- robots.txt được đọc lại ở **mỗi vòng**. Nếu mục forum bị chặn, collector dừng (exit code 2) và ghi lỗi. Kiểm tra ngày 2026-11-09: `/en/forum/asia/vietnam/da-nang/` được phép.
- 1 request / 5 giây, User-Agent lấy từ `CRAWLER_USER_AGENT` (hãy điền email liên hệ thật).
- Mỗi vòng đọc 2 trang danh sách, rồi mở các thread cập nhật trong `FORUM_MAX_AGE_DAYS` ngày (mặc định 7). Ngày giờ lấy từ JSON-LD `DiscussionForumPosting`.
- Fixture `tests/fixtures/expat_com_*.html` giữ đúng cấu trúc HTML thật, nhưng nội dung là tự viết.
- Forum Đà Nẵng ít hoạt động. Nếu thread gần nhất đã cũ hơn 7 ngày, một vòng crawl sẽ ra 0 item. Muốn chạy thử thì tăng `FORUM_MAX_AGE_DAYS`.

```bash
docker compose run --rm collector-forum python -m forum.expat_com --once
```

Trên VPS Linux, thư mục `./data` phải ghi được bởi uid 1000: `mkdir -p data && sudo chown -R 1000:1000 data`.
