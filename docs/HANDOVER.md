# Bàn giao: trạng thái dự án (cập nhật 2026-11-10)

Tài liệu này dành cho người (hoặc phiên Claude Code mới) tiếp tục dự án trên máy khác. Đọc theo thứ tự: file này → [SPEC.md](../SPEC.md) → [CLAUDE.md](../CLAUDE.md) → [README.md](../README.md) → [collectors/README.md](../collectors/README.md) → [docs/DEPLOY.md](DEPLOY.md).

- Repo: https://github.com/daominhtu245/craw-bds (nhánh `main`).
- Commit cuối khi viết tài liệu: `414ca74`, cộng thêm commit chứa chính file này.

---

## 1. Tóm tắt

- Code của cả 6 phase trong SPEC mục 19 đã viết xong, mỗi phase một commit.
- Ngoài SPEC, đã thêm theo yêu cầu của chủ dự án:
  - Phân loại bằng keyword, không cần API key.
  - Bản nháp trả lời bằng template.
  - Seeder dữ liệu demo.
  - Script chạy collector trên Windows không cần Docker.
- Test: **239 test Laravel** (PHPUnit) và **58 test collector** (pytest), tất cả xanh tại commit cuối.
- 2026-11-10: dựng lại trên máy mới (Cách B, toolchain ở `C:	ools`), thêm collector Facebook đọc bằng phiên đăng nhập của chủ dự án (mục 4).
- **Chưa phase nào được nghiệm thu đầy đủ ngoài thực tế.** Lý do: máy cũ chưa chạy được Docker (cần reboot sau khi cài WSL2), và chưa có API key, bot Telegram, tài khoản listener hay token Apify. Chi tiết ở mục 3.

## 2. Đã làm gì (theo commit)

| Commit | Nội dung |
|---|---|
| `47428e8` | SPEC, CLAUDE.md, cấu hình git |
| `72347d2` | Khung Laravel 13 + Horizon |
| `c9d4ce2` | Schema DB, enum, model, seeder |
| `f864756` | Ingest API, lệnh `leadradar:ingest-file`, che SĐT/email, test |
| `d6815a2` | docker-compose, `.env.example`, README |
| `07eee92` | Phase 2: prefilter, AI phân loại, chấm điểm, khớp căn, báo Telegram, eval 60 mẫu |
| `6fa63ce` | Phase 3: dashboard Filament, bản nháp AI, workflow lead |
| `2d7b5b0` | Phase 4: collector Telegram chỉ đọc, ingest client, spool |
| `48f4d60` | Phase 5: collector Facebook (Apify) và Expat.com |
| `165e364` | Phase 6: dọn dữ liệu, ngân sách AI, cảnh báo heartbeat, gom tin giờ yên lặng, hướng dẫn VPS |
| `d2d9253` | `DemoLeadSeeder`, hướng dẫn chạy local không cần Docker |
| `de69a77` | Chế độ phân loại `auto` / `ai` / `keyword` |
| `c4c194e` | Bản nháp trả lời bằng template khi không dùng AI |
| `191572c` | Sửa crawler Expat.com (tách phần trích dẫn), sửa khớp keyword ngắn |
| `d67517e`, `414ca74` | `collectors/run-local.ps1`, `.env.local.example` |
| (2026-11-10) | `package-lock.json`; collector Facebook bằng phiên trình duyệt (`facebook.session`, `facebook.browser`) |
| (2026-11-11) | Chuyển các giá trị `.env` nghiệp vụ vào trang Cài đặt (`ConfigOverrides`: AI key mã hóa, model, ngân sách, ngưỡng tiền lọc, hạn lead, tham số website); ô trống = `.env` |
| (2026-11-11) | Docker cho App #2: build CSS Vite trong `Dockerfile` (bước Node), volume `app-storage` cho ảnh, `storage:link` tự chạy (**chưa chạy thử Docker**, chỉ mô phỏng bước build Node); bản nháp AI có `{site_link}` (prompt `reply.v2`, link luôn ở DM); trang Job bị lỗi; DEPLOY.md mục 4b |
| (2026-11-11) | Admin: trang Môi giới đã biết (xem, gỡ, xử lý lại bài bị lọc), Yêu cầu website, Tình trạng collector, Hồ sơ cá nhân (tự đổi mật khẩu); `AdminSmokeTest` mở thử mọi trang theo vai trò |
| (2026-11-11) | App #2: dự án, ảnh và nội dung VI/EN/RU cho căn hộ; trang công khai `/vi|en|ru`; form yêu cầu (liên hệ mã hóa, chống spam, `?ref=` gắn lead cũ, báo Telegram ngay); Telegram Mini App (kiểm tra initData); `{site_link}` trong bản nháp. Xem `docs/APP2.md` |
| (2026-11-11) | Nhận khách Việt (prefilter, keyword VI có/không dấu, prompt `classify.v2`, template VI, eval `samples_vi.jsonl`); cấu hình Telegram trên trang Cài đặt |
| (2026-11-11) | Negative keyword tin chủ nhà tiếng Anh; alias "хай чау"; nền móng Reddit/Threads bằng phiên trình duyệt: `common/browser_session.py`, `reddit.session`, `threads.session` (đăng nhập tay hoặc `--import` cookie), source type `threads`, ingest nhận `reddit`/`threads` |

## 3. Trạng thái nghiệm thu theo SPEC mục 19

| Phase | Đã kiểm chứng | Chưa kiểm chứng |
|---|---|---|
| 1. Nền móng | Test xanh; `ingest-file` nạp dữ liệu, gửi trùng không tạo bản ghi mới (đã chạy thật, bản local SQLite) | **`docker compose up` chưa từng chạy** |
| 2. Bộ não | Pipeline chạy thật ở bản local với chế độ keyword; Telegram chỉ test bằng mock | `leadradar:eval` với AI thật (cần `ANTHROPIC_API_KEY`, ngưỡng precision ≥ 0.85, recall ≥ 0.80); tin báo lead tới group team trong 60 giây |
| 3. Dashboard | Test Livewire/Filament cho mọi trang; dashboard đã chạy ở `http://127.0.0.1:8000/admin` | Chưa có người bấm thử toàn bộ luồng trên trình duyệt |
| 4. Telegram | Test với mock Telethon | Đăng nhập thật, nhận tin thật từ group test |
| 5. Facebook + Forum | **Expat.com đã chạy thật**: 28 bài, chưa ra lead nào (forum rất vắng). Facebook bằng phiên trình duyệt đã chạy thật 2026-11-11: 1 group, 10/10 bài tách và nạp được, 0 lead (group toàn tin cho thuê) | Chạy Apify thật (cần `APIFY_TOKEN`) |
| 6. Vận hành | Test retention xanh; có test "collector Telegram im lặng 31 phút thì báo đúng 1 lần" | Chưa triển khai VPS |

## 4. Những quyết định đã chốt (lệch hoặc bổ sung so với SPEC)

Danh sách đầy đủ ở README → "Khác biệt so với SPEC". Các điểm quan trọng:

- **Khớp căn chạy trước chấm điểm**, vì điểm lead dùng kết quả khớp căn. SPEC mục 3 ghi ngược thứ tự này.
- **Gọi Claude API bằng HTTP thô** (Laravel HTTP client), API version `2026-10-21`:
  - Dùng `tool_choice: auto` + `strict: true`, vì ép `tool_choice` trả lỗi 400 trên Sonnet 5.5. Model không gọi tool thì hỏi lại 1 lần.
  - Không gửi `temperature`.
  - Bật server-side refusal fallback cho các model trong `AI_FALLBACK_MODELS`.
  - Model: `claude-haiku-5-5` phân loại, `claude-sonnet-5-5` cho escalate và soạn nháp.
- **Chế độ phân loại** (Cài đặt → Phân loại tin, hoặc biến `CLASSIFIER_MODE`):
  - `auto` (mặc định): AI nếu có key và còn ngân sách. Không có key, hết ngân sách hoặc AI lỗi thì dùng keyword.
  - `ai`: như SPEC, hết ngân sách thì tạm dừng.
  - `keyword`: không bao giờ gọi AI.
- **Keyword classifier** (`app/Services/Classify/`):
  - Keyword nhóm `intent_*` và `stage_*` sửa được trên trang Từ khóa. Cột `keywords.kind` đã đổi sang kiểu string.
  - Độ chính xác: 0.95 / 1.00 trên bộ 60 mẫu, nhưng keyword được chọn dựa trên chính bộ này.
  - Trên 30 mẫu mới (`samples_holdout.jsonl`), **lần chạy đầu chỉ đạt 0.44 / 0.36**. Sau khi bổ sung từ đồng nghĩa đạt 0.92 / 1.00, nhưng bộ này không còn "mù".
- **Bản nháp trả lời**: AI hoặc template (Cài đặt → Template bản nháp, EN/RU, có biến điền tự động và khối điều kiện `[[...]]`).
- **Chưa có `TG_BOT_TOKEN`**: job báo lead bỏ qua và ghi log, không retry.
- **Expat.com**:
  - robots.txt cho phép `/en/forum/...`; crawler gửi 1 request mỗi 5 giây.
  - Bài trả lời trích dẫn bài trước bằng `<blockquote>` trong HTML. Parser bỏ phần trích dẫn và đưa nó vào `context_text`.
- **Facebook bằng phiên đăng nhập** (chủ dự án chốt 2026-11-10, dùng cá nhân): Playwright + profile trình duyệt trong `data/facebook-profile`. Trái điều khoản/robots.txt của Facebook, đã ghi ngoại lệ trong CLAUDE.md. Chỉ đọc, chậm, dừng khi gặp login/checkpoint/CAPTCHA. Đường dẫn JSON đã kiểm chứng trên trang thật 2026-11-11 (`stories` = `count` = 10). Apify vẫn giữ (`facebook.run`).
- **Bảo mật và riêng tư**: không lưu ID người dùng thô (chỉ `author_key` = sha256), che SĐT/email trước khi lưu, không có chức năng tự gửi tin hay export hàng loạt.

## 5. Không có trong git (phải tạo lại trên máy mới)

| Thứ | Ở đâu (máy cũ) | Ghi chú |
|---|---|---|
| `app/.env` | Bản local SQLite | Tạo lại theo mục 6. Máy cũ có `PREFILTER_MAX_AGE_DAYS=150` **chỉ để thử forum**; không đặt giá trị này trên production (SPEC là 14) |
| `.env` ở thư mục gốc | Chưa tạo | Dùng cho Docker: `cp .env.example .env` |
| `app/database/database.sqlite` | Có 7 lead demo, 12 bài mẫu, 28 bài Expat.com | Không cần mang theo; tạo lại bằng seed |
| `collectors/.env.local` | Chỉ có token local, chưa điền `TG_API_ID`/`TG_API_HASH` | `copy .env.local.example .env.local` |
| `data/` (session Telegram, spool) | Chưa có session | Đăng nhập lại bằng `telegram.login` |
| Secrets | **Chưa có cái nào**: `ANTHROPIC_API_KEY`, `TG_BOT_TOKEN`, `TG_TEAM_CHAT_ID`, `TG_API_ID`, `TG_API_HASH`, `APIFY_TOKEN` | Không bao giờ commit |

## 6. Dựng lại trên máy mới

### Cách A: Docker (hướng chính theo SPEC, **chưa từng chạy thử**)

Làm theo README → "Chạy bằng Docker". Nếu lỗi, sửa và ghi lại vào README. Đây là việc đầu tiên nên làm trên máy mới.

### Cách B: Không Docker (đã chạy thật trên Windows)

Cần PHP ≥ 8.3 (máy cũ dùng 8.4) có các extension `pdo_sqlite`, `intl`, `mbstring`, `openssl`, `zip`, `fileinfo`, `curl`; Composer; Python ≥ 3.12.

```bash
git clone https://github.com/daominhtu245/craw-bds.git expat-lead-radar
cd expat-lead-radar/app
composer install                      # composer.json khai báo sẵn ext-pcntl/posix nên cài được trên Windows
cp .env.example .env                  # mặc định đã là sqlite + queue/cache database
# Sửa .env: APP_URL=http://127.0.0.1:8000, ADMIN_EMAIL, ADMIN_PASSWORD, INGEST_TOKEN=<chuỗi ngẫu nhiên>
php artisan key:generate
touch database/database.sqlite        # PowerShell: New-Item database\database.sqlite
php artisan migrate --seed
php artisan db:seed --class=DemoLeadSeeder           # tùy chọn: 7 lead mẫu
php artisan leadradar:ingest-file storage/samples.jsonl
php artisan test                                     # mong đợi: 239 passed
php artisan serve --host=127.0.0.1 --port=8000       # cửa sổ 1
php artisan queue:work --queue=notify,prefilter,default,ai   # cửa sổ 2
```

Sửa `.env` thì phải **khởi động lại `queue:work`**, vì worker chỉ đọc cấu hình lúc khởi động.

Collectors:

```bash
cd ../collectors
python -m venv .venv
.venv/Scripts/python -m pip install -e ".[dev]"     # Linux/macOS: .venv/bin/python
.venv/Scripts/python -m pytest -q                    # mong đợi: 58 passed
.venv/Scripts/python -m pip install -e ".[browser]"   # tùy chọn: Facebook bằng phiên trình duyệt
.venv/Scripts/python -m playwright install chromium
cp .env.local.example .env.local                     # INGEST_TOKEN phải trùng với app/.env
```

Chạy (Windows): `.\run-local.ps1 forum.expat_com --once`, `.\run-local.ps1 telegram.login`, `.\run-local.ps1 telegram.listener`. Trên Linux/macOS: export các biến trong `.env.local`, rồi chạy `python -m <module>`.

## 7. Việc còn lại (theo thứ tự đề xuất)

1. **Chạy Docker trên máy mới**: `docker compose up -d --build`, `migrate --seed`, chạy `php artisan test` trong container. Sửa lỗi nếu có.
2. **Telegram (Phase 4)**: chủ dự án lấy `TG_API_ID`/`TG_API_HASH` ở my.telegram.org (dùng tài khoản phụ), tự đăng nhập (OTP), tự join group, thêm nguồn trên dashboard. Sau đó chạy listener, kiểm tra backfill và tin thời gian thực.
3. **Bot báo lead**: tạo bot qua BotFather, thêm vào group team (hoặc channel, bot làm admin). Điền bot token và chat ID ở **Cài đặt → Thông báo Telegram** (token mã hóa bằng APP_KEY; để trống thì dùng `TG_BOT_TOKEN` / `TG_TEAM_CHAT_ID` trong `.env`), bấm **Gửi tin thử**. Có công tắc bật/tắt; giờ yên lặng để trống thì luôn báo ngay. Kiểm tra tiêu chí "lead hiện trong group team trong vòng 60 giây".
4. **AI**: khi có `ANTHROPIC_API_KEY`, chạy `php artisan leadradar:eval` (tốn phí, ước tính vài cent cho 60 mẫu). So sánh với `--engine=keyword`.
5. **Facebook**: phiên trình duyệt đã chạy được (2026-11-11). Còn lại: thêm group có người thuê thật, theo dõi log `facebook group read` (sửa `facebook/extract.py` nếu `stories` > 0 mà `count` = 0), đã thêm 11 negative keyword tiếng Anh cho tin chủ nhà (`KeywordSeeder`). Tin rao có câu tu từ "Looking for an apartment…?" vẫn lọt prefilter vì positive mạnh luôn thắng negative (SPEC 8 bước 6); classifier xử lý tiếp. (Hoặc Apify: `facebook.run --once` khi có `APIFY_TOKEN`.)
6. **Chất lượng keyword**: theo dõi lead "Không hợp lệ" hoặc bị sót trên dữ liệu thật để bổ sung keyword. Bài mơ hồ (ví dụ "I am thinking about living in Da Nang…") keyword không bắt được; AI làm tốt hơn.
7. **App #2 ngoài thực tế**: nhập dự án/căn thật (ảnh, giá, chính sách từ đối tác), điền nút chat ở Cài đặt; Mini App cần VPS có HTTPS rồi cấu hình Menu Button ở @BotFather (`docs/APP2.md` mục 5). Chưa thử Mini App trong Telegram thật (chỉ test chữ ký initData bằng dữ liệu tự tạo).
8. **Reddit và Threads** (chủ dự án chốt 2026-11-11, xem CLAUDE.md): đã có phần đăng nhập/nạp cookie. Còn lại: chủ dự án đăng nhập (`reddit.session`, `threads.session`), soi dữ liệu trang thật [VERIFY], rồi viết `reddit/browser.py` + `extract.py` (đọc theo subreddit, `meta.query` tùy chọn) và `threads/browser.py` + `extract.py` (đọc kết quả tìm kiếm theo từ khóa), test bằng fixture tự viết.
9. **Triển khai VPS**: theo [docs/DEPLOY.md](DEPLOY.md).

## 8. Lưu ý kỹ thuật cho người code tiếp

- **Filament v5**: tham số closure được inject **theo tên** (`$query`, `$data`, `$record`, `$state`, `$get`). Đặt sai tên thì nhận giá trị rỗng mà không báo lỗi.
- **`phpunit.xml`** ép (`force="true"`) các biến môi trường như `DB_CONNECTION`, `CLASSIFIER_MODE`, `ANTHROPIC_API_KEY`, `PREFILTER_MAX_AGE_DAYS`, để `.env` local không rò vào test. Thêm biến cấu hình mới mà test phụ thuộc vào thì thêm vào đây.
- **Test không gọi API thật.** Dùng `tests/Concerns/FakesClaude.php`; helper này đồng thời đặt API key giả, vì chế độ `auto` chỉ dùng AI khi có key. Collector test dùng `httpx.MockTransport` và mock Telethon.
- **Fixture phải là nội dung tự viết.** Fixture HTML của Expat.com giữ cấu trúc trang thật, nhưng chữ là tự viết.
- **Prompt AI**: `app/resources/prompts/*.vN.md`. Muốn đổi thì tạo version mới, không sửa đè.
- **Cấu hình**: mọi ngưỡng, trọng số và tên model nằm trong `app/config/leadradar.php`, đọc từ `.env`.
- **Windows + Git Bash**: heredoc trong Bash có thể biến `\r`, `\t` thành ký tự điều khiển. Khi cần ghi đường dẫn có dấu `\`, dùng công cụ ghi file thay cho heredoc.
- **Không dùng email cá nhân của chủ dự án trong User-Agent của crawler** nếu chưa được đồng ý. Điền email liên hệ vào `CRAWLER_USER_AGENT`.
