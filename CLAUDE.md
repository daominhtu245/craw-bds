# CLAUDE.md — Expat Lead Radar

Nguồn sự thật duy nhất là `SPEC.md`. Đọc lại phần liên quan trước mỗi task.

**Trạng thái hiện tại, việc còn lại và cách dựng lại trên máy mới: đọc `docs/HANDOVER.md` trước tiên.** Cập nhật file đó sau mỗi thay đổi đáng kể. Các điểm lệch so với SPEC đã chốt được ghi trong `README.md` → "Khác biệt so với SPEC".

## Cách làm việc

- Làm **đúng một phase** trong SPEC mục 19 cho mỗi yêu cầu. Không làm trước phase sau.
- Trước khi code: liệt kê ngắn các file sẽ tạo hoặc sửa. Sau khi code: chạy test và đối chiếu từng tiêu chí nghiệm thu của phase.
- Chỗ nào SPEC ghi **[VERIFY]** thì phải kiểm tra thực tế (đọc tài liệu, README actor, HTML thật, robots.txt). Không đoán selector, input schema hay định dạng API.
- Gặp mâu thuẫn hoặc thiếu thông tin quan trọng: dừng lại và hỏi, không tự bịa yêu cầu.
- Commit nhỏ, message tiếng Anh theo Conventional Commits (`feat:`, `fix:`, `test:`, `chore:`).

## Stack và quy ước

- Laravel (bản ổn định mới nhất, PHP ≥ 8.3), Filament, MySQL 8.4 (`utf8mb4_unicode_ci`), Redis 7, Docker Compose.
- Collectors: Python 3.12, `pydantic`, `httpx`, `telethon`, `apify-client`, `selectolax`, `pytest`. Quản lý bằng `pyproject.toml`.
- Code, tên biến, bảng, cột, log: tiếng Anh. Giao diện dashboard và `summary_vi`: tiếng Việt.
- Business logic đặt trong `app/Services/*`, viết dạng class nhỏ, test được. Controller và Job phải mỏng.
- Mọi ngưỡng, trọng số, tên model AI đặt trong `config/leadradar.php` và đọc từ `.env`. Không hard-code.
- Prompt AI đặt trong `resources/prompts/*.vN.md`. Đổi prompt thì tạo version mới, không sửa đè.
- Thời gian lưu UTC, hiển thị `Asia/Ho_Chi_Minh`.

## Ranh giới bắt buộc (không được vi phạm)

- KHÔNG viết code tự động gửi tin nhắn, comment, DM, join group hay tạo tài khoản trên bất kỳ mạng xã hội nào. Telegram listener chỉ đọc.
- KHÔNG lưu SĐT, email, ảnh, ID người dùng dạng thô. Che SĐT và email trước khi lưu (SPEC mục 15).
- KHÔNG tạo chức năng export hàng loạt dữ liệu lead.
- KHÔNG vượt CAPTCHA, không bỏ qua robots.txt, không bỏ qua rate limit.
  - Ngoại lệ do chủ dự án chốt: đọc bằng phiên đăng nhập của chủ dự án (dùng cá nhân) cho đúng 3 nguồn: Facebook (`collectors/facebook/browser.py`, 2026-11-10), Reddit (`collectors/reddit/browser.py`) và Threads (`collectors/threads/browser.py`) (2026-11-11; Reddit API cần duyệt theo Responsible Builder Policy). Vẫn phải chỉ đọc, chậm, và dừng khi gặp login/checkpoint/CAPTCHA (`common/browser_session.py`). Không mở rộng ngoại lệ này sang nguồn khác nếu chủ dự án chưa chốt.
- Test không gọi API thật (Anthropic, Telegram, Apify). Dùng mock và fixture. Chỉ lệnh `leadradar:eval` được gọi AI thật.
- KHÔNG commit `.env`, file session Telegram hay bất kỳ secret nào. Thêm chúng vào `.gitignore`.
- Fixture dùng dữ liệu **tự viết**, không copy bài thật của người khác.

## Lệnh thường dùng (cập nhật khi tạo xong)

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
docker compose exec app php artisan leadradar:ingest-file storage/samples.jsonl
docker compose exec app php artisan leadradar:eval
docker compose run --rm collectors pytest
docker compose run --rm -it collector-telegram python -m telegram.login
```

Không Docker (SQLite + queue database, chi tiết trong `docs/HANDOVER.md` mục 6):

```bash
cd app && php artisan test                      # 239 test
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --queue=notify,prefilter,default,ai
php artisan leadradar:eval --engine=keyword     # không gọi AI, miễn phí
cd collectors && .venv/Scripts/python -m pytest -q   # 58 test
.\run-local.ps1 forum.expat_com --once          # Windows, đọc collectors/.env.local
```
