# App #2: trang căn hộ công khai và Telegram Mini App

Chủ dự án chốt ngày 2026-11-11. Khách xem căn hộ (ảnh, giá, chính sách) bằng tiếng Việt, Anh hoặc Nga, rồi gửi yêu cầu. Yêu cầu thành lead điểm cao và được báo ngay cho sale qua Telegram.

Hệ thống **không tự nhắn tin cho ai**. Sale gửi link trang căn hộ cho khách bằng bản nháp trả lời, khách tự mở và tự gửi form.

---

## 1. Đường dẫn

| Trang | URL |
|---|---|
| Trang chủ (tự chọn ngôn ngữ theo trình duyệt) | `/` |
| Danh sách căn | `/vi`, `/en`, `/ru` (lọc: khu vực, dự án, số phòng ngủ, giá tối đa) |
| Chi tiết căn | `/{vi|en|ru}/apartments/{slug}` |
| Gửi yêu cầu (POST) | `/{vi|en|ru}/inquiry` |

Tắt toàn bộ trang công khai: `SITE_ENABLED=false` trong `app/.env`.

---

## 2. Nhập dữ liệu trên dashboard

1. **Dự án** (menu mới): tên (ví dụ Masteri…), khu vực, mô tả 3 thứ tiếng.
2. **Kho căn hộ** → sửa căn → mục **Website (App #2)**:
   - Bật **Hiển thị trên website**.
   - Chọn dự án, diện tích, upload ảnh (tối đa 20 ảnh, mỗi ảnh ≤ 5 MB, kéo để đổi thứ tự, ảnh đầu là ảnh đại diện).
   - Tiêu đề, mô tả, chính sách theo từng tab Tiếng Việt / English / Русский. Thiếu tiếng nào thì trang dùng tiếng Anh, rồi tiếng Việt.
   - Căn chỉ hiện khi **Đang cho thuê** và **Hiển thị trên website** cùng bật.
3. **Cài đặt** → **Website (App #2)**: Zalo, Telegram, WhatsApp, số điện thoại của sale. Nút nào có giá trị mới hiện ở góc trang.
4. **Cài đặt** → **Thông báo Telegram**: phải có bot token và chat ID thì sale mới nhận được tin báo yêu cầu.

Ảnh lưu ở `app/storage/app/public/listings`. Cần chạy `php artisan storage:link` một lần trên mỗi máy (đã chạy trên máy này).

---

## 3. Khi khách gửi yêu cầu

- Form: họ tên, kênh liên hệ (Điện thoại / Zalo / WhatsApp / Telegram / Email), liên hệ, ngày dọn vào, số tháng, ngân sách (tiếng Việt: triệu VND/tháng; tiếng Anh, Nga: USD/tháng), ghi chú, ô đồng ý lưu thông tin.
- Chống spam: ô ẩn (honeypot), form phải mở ít nhất 3 giây (`INQUIRY_MIN_FILL_SECONDS`), tối đa 5 yêu cầu mỗi giờ mỗi IP (`INQUIRY_RATE_LIMIT`).
- Hệ thống tạo:
  - `inquiries`: họ tên và liên hệ **mã hóa** bằng APP_KEY; ghi chú được che SĐT/email.
  - Lead mới (nguồn **Website** hoặc **Telegram Mini App**), loại "người thuê", điểm `INQUIRY_SCORE` (mặc định 100), khớp căn như lead thường.
  - Nếu khách mở trang từ link có `?ref=` trong bản nháp trả lời, yêu cầu được **gắn vào lead cũ** thay vì tạo lead mới.
- Sale nhận tin Telegram **ngay**, kể cả trong giờ yên lặng, có họ tên, liên hệ, căn quan tâm, ngày đến, ngân sách và ghi chú.
- Trang chi tiết lead có mục **Khách gửi yêu cầu (website / Mini App)** với liên hệ của khách.
- Lệnh `leadradar:retention` (03:00 hằng ngày) xóa họ tên và liên hệ sau `INQUIRY_CONTACT_DAYS` ngày (mặc định 90).

---

## 4. Link trong bản nháp trả lời

Template bản nháp có thêm biến `{site_link}`: link trang căn (căn khớp tốt nhất nếu căn đó đang hiển thị, nếu không thì trang danh sách) kèm mã theo dõi `?ref=`. Mẫu tin nhắn riêng mặc định (VI/EN/RU) đã có dòng này; trả lời công khai không chèn link.

Mã `ref` được ký bằng APP_KEY, đoán mã sẽ không gắn được vào lead.

---

## 5. Telegram Mini App

Mini App chính là trang công khai, mở bên trong Telegram. Khi chạy trong Telegram:

- Giao diện theo màu theme của Telegram, ẩn header/footer.
- Tự điền họ tên từ Telegram và có lựa chọn **"Telegram của tôi"**: lấy username Telegram làm liên hệ, khách không phải gõ.
- Server kiểm tra chữ ký `initData` bằng bot token (`TelegramWebApp`), dữ liệu quá 24 giờ bị từ chối. Chỉ dùng username; **không lưu Telegram user ID**.

### Cài đặt (cần tên miền HTTPS)

Telegram chỉ mở Mini App qua **HTTPS công khai**, không mở được `http://127.0.0.1`. Cần triển khai lên VPS có tên miền (xem [DEPLOY.md](DEPLOY.md)) và đặt `APP_URL=https://ten-mien-cua-ban`.

1. Bot dùng chung với bot báo lead (cùng bot token trong Cài đặt), hoặc bot riêng cho khách. Nếu dùng bot riêng thì token của bot đó phải là token trong Cài đặt, vì server dùng nó để kiểm tra chữ ký.
2. Chat với @BotFather:
   - `/mybots` → chọn bot → **Bot Settings** → **Menu Button** → nhập URL `https://ten-mien-cua-ban/vi` (hoặc `/en`, `/ru`) và tên nút, ví dụ "Xem căn hộ".
   - Hoặc `/newapp` để tạo Mini App có link riêng dạng `t.me/<bot>/<app>`.
3. Mở bot trong Telegram, bấm nút menu: trang căn hộ hiện trong Telegram.

Thử trên máy local trước khi có VPS: có thể dùng một tunnel HTTPS tạm (ví dụ `cloudflared tunnel --url http://127.0.0.1:8000`). Lưu ý tunnel công khai cả dashboard `/admin` ra Internet trong lúc chạy; tắt tunnel khi thử xong.

---

## 6. Cấu hình (`app/.env`)

Các biến dưới đây (trừ `TG_WEBAPP_MAX_AGE`) cũng sửa được ở **Cài đặt → Website (App #2)**; giá trị trên dashboard được ưu tiên, ô để trống thì dùng `.env`.

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `SITE_ENABLED` | `true` | Bật trang công khai |
| `SITE_DEFAULT_LOCALE` | `vi` | Ngôn ngữ khi trình duyệt không khớp vi/en/ru |
| `SITE_PER_PAGE` | `12` | Số căn mỗi trang |
| `INQUIRY_SCORE` | `100` | Điểm lead tạo từ form |
| `INQUIRY_CONTACT_DAYS` | `90` | Số ngày giữ họ tên và liên hệ |
| `INQUIRY_RATE_LIMIT` | `5` | Yêu cầu mỗi giờ mỗi IP |
| `INQUIRY_MIN_FILL_SECONDS` | `3` | Form gửi nhanh hơn bị coi là bot |
| `TG_WEBAPP_MAX_AGE` | `86400` | Tuổi tối đa của initData Mini App (giây) |
