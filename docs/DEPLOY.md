# Triển khai lên VPS

Cấu hình tối thiểu: 2 vCPU, 4 GB RAM, Ubuntu 24.04, một domain (ví dụ `radar.example.com`).

## 1. Chuẩn bị máy

```bash
# Docker Engine + compose plugin (theo hướng dẫn chính thức của Docker)
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # đăng xuất rồi đăng nhập lại

# Firewall: chỉ mở SSH và HTTPS. MySQL/Redis không publish cổng ra ngoài.
sudo ufw allow OpenSSH && sudo ufw allow 80,443/tcp && sudo ufw enable
```

## 2. Lấy code và cấu hình

```bash
git clone https://github.com/daominhtu245/craw-bds.git expat-lead-radar && cd expat-lead-radar
cp .env.example .env
mkdir -p data && sudo chown -R 1000:1000 data     # session Telegram + spool của collector
```

Sửa `.env`:

| Biến | Giá trị production |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://radar.example.com` |
| `APP_PORT` | `127.0.0.1:8080` (chỉ reverse proxy truy cập được) |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `INGEST_TOKEN` | chuỗi ngẫu nhiên dài: `openssl rand -hex 32` |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | tài khoản admin đầu tiên |
| `ANTHROPIC_API_KEY`, `AI_DAILY_BUDGET_USD` | key thật, ngân sách/ngày |
| `TG_BOT_TOKEN`, `TG_TEAM_CHAT_ID` | bot báo lead (thêm bot vào group team trước) |
| `TG_API_ID`, `TG_API_HASH` | tài khoản listener riêng (xem `collectors/README.md`) |
| `APIFY_TOKEN` | nếu dùng Facebook |
| `CRAWLER_USER_AGENT` | có email liên hệ thật |

Tạo `APP_KEY`:

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show   # dán vào APP_KEY
```

## 3. Chạy

```bash
docker compose up -d app queue scheduler mysql redis
docker compose exec app php artisan migrate --seed --force
docker compose run --rm -it collector-telegram python -m telegram.login   # nhập SĐT + OTP
docker compose up -d collector-telegram collector-forum collector-facebook
```

## 4. HTTPS bằng Caddy (reverse proxy)

```bash
sudo apt install -y caddy
sudo tee /etc/caddy/Caddyfile >/dev/null <<'EOF'
radar.example.com {
    reverse_proxy 127.0.0.1:8080
}
EOF
sudo systemctl reload caddy
```

Dashboard: `https://radar.example.com/admin`. Horizon (chỉ admin): `/horizon`.

## 5. Vận hành

| Việc | Lệnh / ghi chú |
|---|---|
| Xem log | `docker compose logs -f queue collector-telegram` (log dạng JSON) |
| Kiểm tra AI | `docker compose exec app php artisan leadradar:eval --limit=10` (tốn phí AI) |
| Dọn dữ liệu | Tự chạy 03:00 hằng ngày; chạy tay: `php artisan leadradar:retention` |
| Cảnh báo | Mỗi 5 phút: collector im lặng > 30 phút, hoặc nguồn "Quan trọng" > 24 giờ không có item |
| Giờ yên lặng | Đổi trong **Cài đặt**; lead `urgent` vẫn báo ngay, còn lại gom 1 tin sau giờ yên lặng |
| Ngân sách AI | Hết ngân sách thì job AI tạm dừng tới 0h (giờ VN), team nhận 1 cảnh báo |
| Cập nhật code | `git pull && docker compose up -d --build && docker compose exec app php artisan migrate --force` |

### Sao lưu MySQL hằng ngày

```bash
crontab -e
# 02:30 mỗi ngày, giữ 14 bản
30 2 * * * cd ~/expat-lead-radar && docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" leadradar' | gzip > ~/backups/leadradar-$(date +\%F).sql.gz && find ~/backups -name 'leadradar-*.sql.gz' -mtime +14 -delete
```

File `data/telegram.session` cũng cần sao lưu riêng (mất thì phải đăng nhập lại), và **không bao giờ** commit lên git.

### Build gọn cho production (tùy chọn)

Image mặc định có dev dependencies để chạy `php artisan test` trong container. Trên production có thể bỏ:

```bash
docker compose build --build-arg COMPOSER_NO_DEV=1 app
```
