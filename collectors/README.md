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

## Facebook bằng phiên đăng nhập của bạn (không cần Apify)

> **Chỉ dùng cá nhân.** Chủ dự án chốt ngày 2026-11-10. Cách này trái điều khoản và robots.txt của Facebook, tài khoản có thể bị checkpoint hoặc khóa. Nên dùng **tài khoản phụ**.

- Bạn tự đăng nhập trong cửa sổ trình duyệt. Code không gõ mật khẩu, không giải CAPTCHA, không vượt checkpoint.
- Cookie lưu trong profile trình duyệt ở `..\data\facebook-profile` (`FB_PROFILE_DIR`, đã nằm trong `.gitignore`, **không commit**). Ai có thư mục này là có quyền vào tài khoản.
- Chỉ đọc: mỗi group mở 1 trang (sắp xếp bài mới nhất), cuộn `FB_SCROLLS` lần (mặc định 3), nghỉ `FB_SCROLL_DELAY` giây (5–10) giữa các lần cuộn và `FB_GROUP_DELAY` giây (30–60) giữa các group. Không click, gõ, like, comment hay join.
- Bài được lấy từ JSON mà trang tải về (HTML ban đầu + phản hồi `/api/graphql/`), xem `facebook/extract.py`. Chỉ lấy bài sau `max(last_item_at, now − 48h)`.
- Gặp trang login, checkpoint hoặc CAPTCHA: dừng ngay (exit code 2), gửi heartbeat lỗi. Đăng nhập lại bằng tay.
- **[VERIFY]** Đường dẫn JSON mới được kiểm tra bằng fixture tự viết. Lần chạy thật đầu tiên, xem dòng log `facebook group read`: `documents`/`stories` > 0 mà `count` = 0 nghĩa là parser cần sửa.

```powershell
cd collectors
.venv\Scripts\python -m pip install -e ".[dev,browser]"
.venv\Scripts\python -m playwright install chromium   # hoặc đặt FB_BROWSER_CHANNEL=msedge
.\run-local.ps1 facebook.session            # cửa sổ trình duyệt mở: tự đăng nhập, thấy feed thì đóng cửa sổ
.\run-local.ps1 facebook.browser --once     # 1 vòng; bỏ --once để chạy mỗi FB_POLL_MINUTES phút
```

Trên dashboard, vào **Nguồn**, thêm source loại `facebook` với `identifier` là URL group mà tài khoản đó xem được (ví dụ `https://www.facebook.com/groups/ten-group`). `meta` có thể đặt `{"scrolls": 5}`. Muốn xem trình duyệt làm gì thì đặt `FB_HEADLESS=0`.

Chế độ này chỉ chạy local trên Windows (cần cửa sổ để đăng nhập); chưa đưa vào Docker.

## Reddit và Threads bằng phiên đăng nhập (đang làm)

> **Chỉ dùng cá nhân.** Chủ dự án chốt ngày 2026-11-11 (Reddit Data API cần được duyệt theo Responsible Builder Policy). Trái điều khoản và robots.txt của hai trang, tài khoản có thể bị khóa. Nên dùng **tài khoản phụ**. Quy tắc giống Facebook, code dùng chung ở `common/browser_session.py`.

Có 2 cách đưa phiên đăng nhập vào profile:

```powershell
.\run-local.ps1 reddit.session                          # cửa sổ mở: tự đăng nhập, thấy feed thì đóng
.\run-local.ps1 reddit.session --import cookies.json    # hoặc nạp cookie xuất từ Chrome của bạn
.\run-local.ps1 threads.session                         # tương tự, đăng nhập bằng tài khoản Instagram
.\run-local.ps1 threads.session --import cookies.json
```

Hướng dẫn từng bước: [docs/COOKIES.md](../docs/COOKIES.md). Tóm tắt: đăng nhập trên Chrome thường, dùng extension (ví dụ Cookie-Editor → Export → JSON) hoặc file `cookies.txt` dạng Netscape. Chỉ cookie của `reddit.com` / `threads.com` được nạp, cookie trang khác bị bỏ. Lệnh in tên cookie (không in giá trị) và báo đã đăng nhập hay chưa. **Nạp xong thì xóa file export**: ai có file đó là vào được tài khoản.

Profile lưu ở `..\data\reddit-profile` và `..\data\threads-profile` (`REDDIT_PROFILE_DIR`, `THREADS_PROFILE_DIR`, đã gitignore).

Nguồn trên dashboard:
- `reddit`: `identifier` là tên subreddit, không có `r/` (ví dụ `danang`). Subreddit rộng như `VietNam` thì đặt thêm "Chỉ lấy bài khớp từ khóa" (`meta.query`).
- `threads`: `identifier` là từ khóa tìm kiếm (ví dụ `da nang apartment`, `Дананг квартира`).

**[VERIFY]** `reddit.browser` và `threads.browser` chưa viết: cần một phiên đăng nhập thật để xem định dạng dữ liệu trước.

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
