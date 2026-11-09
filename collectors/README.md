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

### 4. Chạy

```bash
docker compose up -d collector-telegram
docker compose logs -f collector-telegram
```

Gửi thử câu "Looking for 1BR in An Thuong from Dec, 6 months, budget 450$" vào group test. Team sẽ nhận thông báo Telegram có link mở đúng tin gốc.
