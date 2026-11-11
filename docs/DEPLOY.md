# Triển khai lên server (Docker + nginx + certbot)

Tài liệu cho server **đã có sẵn nginx và certbot**, đang chạy các dịch vụ khác. App chạy trong Docker, chỉ nghe ở `127.0.0.1:<cổng>`; nginx của server nhận HTTPS rồi chuyển vào cổng đó. Cổng chọn tùy ý trong `.env` để không đụng các dịch vụ đang có.

```
Internet ──HTTPS──> nginx (server, certbot) ──> 127.0.0.1:APP_PORT ──> container app
                                                                       ├─ queue (Horizon)
                                                                       ├─ scheduler
                                                                       ├─ mysql, redis (không mở cổng ra ngoài)
                                                                       └─ collectors (tùy chọn)
```

Tóm tắt toàn bộ:

```bash
git clone https://github.com/daominhtu245/craw-bds.git /opt/leadradar && cd /opt/leadradar
./deploy.sh                 # lần 1: tạo .env rồi dừng
nano .env                   # APP_URL, APP_PORT, ADMIN_EMAIL, ADMIN_PASSWORD
./deploy.sh                 # build, chạy, seed
# thêm site nginx + certbot (mục 4)
```

---

## 1. Chuẩn bị

| Cần | Ghi chú |
|---|---|
| Docker Engine + plugin Compose | Kiểm tra: `docker --version` và `docker compose version`. Chưa có thì cài theo docs.docker.com/engine/install |
| Tên miền | Bản ghi A trỏ về IP server, ví dụ `radar.example.com` |
| Một cổng trống | Kiểm tra: `sudo ss -ltnp \| grep :8080`. Không có dòng nào là cổng còn trống |
| RAM | Khuyến nghị ≥ 2 GB (MySQL + Redis + PHP + Horizon) |

Không cần cài PHP, Node hay MySQL trên server: mọi thứ nằm trong container. CSS của trang công khai cũng được build trong image.

---

## 2. Lấy code

```bash
sudo mkdir -p /opt/leadradar && sudo chown $USER:$USER /opt/leadradar
git clone https://github.com/daominhtu245/craw-bds.git /opt/leadradar
cd /opt/leadradar
```

Repo private thì dùng deploy key (GitHub → Settings → Deploy keys) hoặc personal access token khi clone.

Người dùng chạy lệnh phải dùng được Docker: `sudo usermod -aG docker $USER`, rồi đăng xuất và đăng nhập lại.

---

## 3. Cấu hình và chạy

### 3.1 Tạo `.env`

```bash
./deploy.sh
```

Lần đầu script chỉ copy `.env.example` thành `.env` rồi dừng. Mở `.env` và sửa:

| Biến | Giá trị |
|---|---|
| `APP_URL` | `https://radar.example.com` (đúng tên miền, có `https://`) |
| `APP_PORT` | `127.0.0.1:<cổng trống>`, ví dụ `127.0.0.1:18080`. **Luôn giữ `127.0.0.1:`** để chỉ nginx truy cập được |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Tài khoản admin đầu tiên. Để trống mật khẩu thì script in ra một mật khẩu ngẫu nhiên |
| `CRAWLER_USER_AGENT` | Thay email liên hệ thật (dùng khi đọc forum Expat.com) |
| `COMPOSE_PROFILES` | Collector muốn chạy, xem mục 6. Để trống lúc đầu |

Không cần điền: `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `INGEST_TOKEN` được script tự tạo ngẫu nhiên.

Bot Telegram, API key AI, ngưỡng lọc, nút chat website… **nhập trên dashboard** sau khi chạy (mục 5), không cần sửa `.env`.

### 3.2 Chạy

```bash
./deploy.sh
```

Script làm lần lượt:

1. Tạo mật khẩu DB, `INGEST_TOKEN`, `APP_KEY` nếu còn trống.
2. Build image (lần đầu mất vài phút).
3. `docker compose up -d`. Container `app` tự chạy migration và `storage:link` khi khởi động.
4. Chờ `http://127.0.0.1:<cổng>/up` trả lời.
5. Lần đầu (DB chưa có user): seed tài khoản admin, khu vực, từ khóa, 5 căn demo.

Kết thúc sẽ in trạng thái các container. `app`, `queue`, `scheduler`, `mysql`, `redis` phải ở trạng thái `running` hoặc `healthy`.

> **Sao lưu `.env` ngay** (ra ngoài server). Mất `APP_KEY` thì không giải mã được bot token, API key và liên hệ khách đã lưu.

---

## 4. nginx + HTTPS (certbot)

```bash
sudo cp deploy/nginx-site.conf.example /etc/nginx/sites-available/leadradar
sudo nano /etc/nginx/sites-available/leadradar
#   - server_name radar.example.com;
#   - proxy_pass http://127.0.0.1:18080;   <- đúng cổng trong APP_PORT
sudo ln -s /etc/nginx/sites-available/leadradar /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d radar.example.com
```

Certbot tự thêm phần HTTPS và chuyển hướng HTTP sang HTTPS, và tự gia hạn như các site khác trên server.

Mẫu cấu hình đã có sẵn những thứ app cần:

- `X-Forwarded-Proto`: app tạo link `https://` và cookie an toàn dựa vào header này (app đã bật tin cậy proxy).
- `client_max_body_size 25m`: upload ảnh căn hộ (mỗi ảnh tối đa 5 MB).

Server dùng thư mục `conf.d/` thay cho `sites-available/` thì copy file vào `/etc/nginx/conf.d/leadradar.conf`.

Firewall: **không** mở `APP_PORT` ra ngoài. Chỉ cần 80/443 cho nginx (thường đã mở sẵn).

Kiểm tra: mở `https://radar.example.com` (trang căn hộ) và `https://radar.example.com/admin` (đăng nhập).

---

## 5. Sau khi đăng nhập dashboard

1. **Hồ sơ cá nhân** (bấm avatar): đổi mật khẩu admin.
2. **Cài đặt → Thông báo Telegram**: bot token, chat ID của group sale, bấm **Gửi tin thử**.
3. **Cài đặt → AI (Claude)**: API key Anthropic nếu dùng AI. Không có key thì hệ thống phân loại bằng từ khóa.
4. **Cài đặt → Website (App #2)**: Zalo / Telegram / WhatsApp / SĐT của sale.
5. **Thông tin agency**: tên và website (dùng trong bản nháp trả lời).
6. **Dự án**, **Kho căn hộ**: nhập căn thật, ảnh, nội dung 3 thứ tiếng, bật "Hiển thị trên website". Xóa hoặc ẩn 5 căn demo.
7. **Người dùng**: tạo tài khoản cho sale.
8. **Nguồn**: thêm group Telegram/Facebook, forum.

Telegram Mini App: @BotFather → bot → **Bot Settings → Menu Button** → URL `https://radar.example.com/vi`. Chi tiết: [APP2.md](APP2.md).

---

## 6. Collector (đọc bài từ mạng xã hội)

Bật collector bằng `COMPOSE_PROFILES` trong `.env` (cách nhau bằng dấu phẩy), rồi chạy lại `./deploy.sh`.

| Profile | Cần | Bật |
|---|---|---|
| `forum` | Không cần key (đọc Expat.com) | `COMPOSE_PROFILES=forum` |
| `telegram` | `TG_API_ID`, `TG_API_HASH` (my.telegram.org, tài khoản phụ) và đăng nhập 1 lần | Xem dưới |
| `facebook` | `APIFY_TOKEN` (đọc group công khai qua Apify) | `COMPOSE_PROFILES=forum,facebook` |

Telegram: điền `TG_API_ID`, `TG_API_HASH` vào `.env`, đăng nhập một lần (nhập SĐT và mã OTP):

```bash
docker compose run --rm -it collector-telegram python -m telegram.login
```

rồi đặt `COMPOSE_PROFILES=forum,telegram` và chạy `./deploy.sh`. File phiên nằm ở `data/telegram.session`: cần sao lưu, không commit.

Facebook / Reddit / Threads **bằng phiên đăng nhập trình duyệt** không chạy trên server (cần cửa sổ trình duyệt để tự đăng nhập). Chạy chúng trên máy Windows bằng `collectors\run-local.ps1` và trỏ về server:

```
# collectors/.env.local trên máy Windows
INGEST_URL=https://radar.example.com/api
INGEST_TOKEN=<giá trị INGEST_TOKEN trong .env của server>
```

Trạng thái collector: dashboard → **Tình trạng collector**.

---

## 7. Cập nhật code

```bash
cd /opt/leadradar
git pull
./deploy.sh
```

Script build lại image (kể cả CSS), khởi động lại container. Migration chạy tự động, **không seed lại** nên từ khóa và cài đặt đã sửa trên dashboard được giữ nguyên. Ảnh căn hộ nằm trong volume `app-storage` nên không mất.

---

## 8. Vận hành

| Việc | Lệnh |
|---|---|
| Trạng thái | `docker compose ps` |
| Xem log | `docker compose logs -f --tail=100 app queue` (thêm `collector-forum`, `collector-telegram`…) |
| Khởi động lại | `docker compose restart` |
| Dừng | `docker compose down` (dữ liệu vẫn còn trong volume) |
| Vào shell / artisan | `docker compose exec app php artisan <lệnh>` |
| Job bị lỗi | Dashboard → **Job bị lỗi**, hoặc `https://radar.example.com/horizon` |
| Dọn dữ liệu cũ | Tự chạy 03:00 hằng ngày (scheduler); chạy tay: `docker compose exec app php artisan leadradar:retention` |
| Cảnh báo | Mỗi 5 phút qua Telegram: collector im lặng > 30 phút, nguồn "Quan trọng" > 24 giờ không có bài |
| Kiểm tra AI | `docker compose exec app php artisan leadradar:eval --limit=10` (tốn phí AI) |

### Sao lưu hằng ngày

```bash
mkdir -p ~/backups && crontab -e
```

```cron
# MySQL lúc 02:30, ảnh căn hộ lúc 02:45, giữ 14 ngày
30 2 * * * cd /opt/leadradar && docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" leadradar' | gzip > ~/backups/leadradar-$(date +\%F).sql.gz && find ~/backups -name 'leadradar-*.sql.gz' -mtime +14 -delete
45 2 * * * cd /opt/leadradar && docker compose exec -T app tar -czf - -C /var/www/html/storage/app public > ~/backups/photos-$(date +\%F).tar.gz && find ~/backups -name 'photos-*.tar.gz' -mtime +14 -delete
```

Ngoài ra sao lưu **`.env`** và **`data/telegram.session`** một lần, ra ngoài server.

Khôi phục MySQL:

```bash
gunzip -c ~/backups/leadradar-2026-11-11.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" leadradar'
```

### Build gọn cho production (tùy chọn)

Image mặc định có dev dependencies để chạy được `php artisan test` trong container. Muốn bỏ:

```bash
docker compose build --build-arg COMPOSER_NO_DEV=1 app && docker compose up -d
```

---

## 9. Gỡ lỗi

| Hiện tượng | Nguyên nhân / cách xử lý |
|---|---|
| `deploy.sh` báo `port is already allocated` | Cổng trong `APP_PORT` đã có dịch vụ khác dùng. Chọn cổng khác, sửa cả `proxy_pass` trong nginx |
| `502 Bad Gateway` | Container chưa chạy xong hoặc sai cổng. `docker compose ps`, `docker compose logs app`, so `proxy_pass` với `APP_PORT` |
| Trang mở được nhưng link / ảnh là `http://` hoặc sai tên miền | `APP_URL` sai, hoặc nginx thiếu `X-Forwarded-Proto`. Sửa rồi chạy `./deploy.sh` |
| Upload ảnh báo lỗi `413` | Thiếu `client_max_body_size 25m;` trong site nginx |
| Đăng nhập xong bị đá ra / lỗi 419 | Truy cập bằng địa chỉ khác `APP_URL` (ví dụ IP). Luôn dùng đúng tên miền HTTPS |
| Không nhận tin Telegram | Cài đặt → Thông báo Telegram → **Gửi tin thử** để xem lỗi; xem **Job bị lỗi** |
| Collector khởi động lại liên tục | Thiếu key (`TG_API_ID`, `APIFY_TOKEN`) hoặc chưa đăng nhập Telegram. Bỏ profile đó khỏi `COMPOSE_PROFILES` cho tới khi có |
| Quên mật khẩu admin | `docker compose exec app php artisan tinker --execute="App\Models\User::where('email','admin@...')->first()->update(['password'=>bcrypt('mat-khau-moi')]);"` |

---

## 10. Đã kiểm chứng và chưa

- Bước build CSS trong `Dockerfile` đã mô phỏng thành công trên máy dev (chỉ với các file mà bước đó copy).
- **Chưa chạy `docker compose` thật lần nào** (máy dev không có Docker). Lần deploy đầu nên theo dõi `docker compose logs -f app` và báo lại lỗi nếu có. Hai điểm cần để ý nhất: biến `AUTORUN_LARAVEL_STORAGE_LINK` của image `serversideup/php` (ảnh căn hộ hiện được không) và thời gian MySQL khởi động lần đầu.
