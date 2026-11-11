# Lấy cookie Reddit và Threads cho collector

Tài liệu này hướng dẫn đưa phiên đăng nhập Reddit và Threads vào collector bằng cookie, không cần đăng nhập qua cửa sổ trình duyệt của collector.

> **Đọc trước:**
> - **Cookie phiên = mật khẩu.** Ai có file cookie là vào được tài khoản, không cần mật khẩu hay mã 2FA. Không gửi file qua chat, email, Zalo; không dán vào Claude hay bất kỳ AI nào; không commit lên git.
> - **Dùng tài khoản phụ.** Đọc bằng phiên đăng nhập trái điều khoản của Reddit và Threads, tài khoản có thể bị khóa (chủ dự án đã chốt ngày 2026-11-11, xem `CLAUDE.md`).
> - Collector chỉ đọc. Nó không đăng bài, comment, like, follow hay nhắn tin.

---

## 1. Chuẩn bị (một lần)

1. **Nên tạo một profile Chrome riêng cho tài khoản phụ**: bấm ảnh đại diện góc trên bên phải Chrome → *Thêm* (Add). Nhờ vậy bạn không lẫn cookie với tài khoản chính, và không vô tình bấm đăng xuất.
2. Trong profile đó, cài extension **Cookie-Editor** từ Chrome Web Store (tác giả: cgagnier). Ghim extension lên thanh công cụ cho dễ bấm.
3. Collector đã cài sẵn (`collectors\.venv`). Các lệnh bên dưới chạy trong PowerShell, tại thư mục `collectors`:
   ```powershell
   cd C:\Users\Administrator\Downloads\craw-bds\collectors
   ```

---

## 2. Reddit

1. Mở https://www.reddit.com và **đăng nhập** bằng tài khoản phụ. Đợi đến khi thấy feed.
2. Vẫn ở tab reddit.com, bấm biểu tượng **Cookie-Editor**.
3. Kiểm tra trong danh sách có cookie tên **`reddit_session`**. Nếu không có nghĩa là chưa đăng nhập xong; tải lại trang rồi thử lại.
4. Bấm **Export** (góc dưới) → chọn **JSON**. Cookie được copy vào clipboard.
5. Mở **Notepad**, dán (Ctrl+V), lưu thành `C:\Users\Administrator\Desktop\reddit-cookies.json`.
   - Ở hộp thoại lưu: *Save as type* = **All files**, *Encoding* = **UTF-8**, để tên file không bị thành `.json.txt`.
6. Nạp vào collector:
   ```powershell
   .\run-local.ps1 reddit.session --import C:\Users\Administrator\Desktop\reddit-cookies.json
   ```
7. Kết quả đúng sẽ có dạng:
   ```
   Imported 12 cookies into ...\data\reddit-profile: csv, edgebucket, loid, reddit_session, token_v2, ...
   Logged in. Delete ...\reddit-cookies.json now: it gives full access to the account.
   ```
8. **Xóa file** `reddit-cookies.json` (và dọn Thùng rác).

---

## 3. Threads

Threads đăng nhập bằng tài khoản Instagram.

1. Mở https://www.threads.com và **đăng nhập** (chọn *Continue with Instagram*). Đợi đến khi thấy feed.
2. Vẫn ở tab threads.com, bấm **Cookie-Editor**.
3. Kiểm tra có cookie tên **`sessionid`**.
4. Bấm **Export** → **JSON**, dán vào Notepad, lưu thành `C:\Users\Administrator\Desktop\threads-cookies.json` (All files, UTF-8).
5. Nạp vào collector:
   ```powershell
   .\run-local.ps1 threads.session --import C:\Users\Administrator\Desktop\threads-cookies.json
   ```
6. Thấy dòng `Logged in.` là xong. **Xóa file** `threads-cookies.json`.

> Phải export khi đang mở tab **threads.com**, không phải instagram.com. Cookie Instagram không dùng được cho Threads, và lệnh import sẽ tự bỏ cookie của trang khác.

---

## 4. Sau khi nạp

- **Không bấm "Đăng xuất"** trên Reddit/Threads ở trình duyệt bạn đã export cookie. Đăng xuất sẽ hủy phiên phía máy chủ, collector cũng mất đăng nhập theo. Muốn thôi dùng thì chỉ cần đóng tab, hoặc xóa luôn profile Chrome phụ.
- Đổi mật khẩu tài khoản phụ cũng làm phiên cũ hết hiệu lực; khi đó làm lại từ bước 2 hoặc 3.
- Cookie được lưu ở `data\reddit-profile` và `data\threads-profile`. Hai thư mục này đã nằm trong `.gitignore`. Muốn thu hồi quyền của collector thì xóa thư mục tương ứng.

---

## 5. Lỗi thường gặp

| Thông báo | Nguyên nhân | Cách xử lý |
|---|---|---|
| `No cookies for reddit.com in ...` (hoặc `threads.com, threads.net`) | Export ở sai tab, hoặc file rỗng | Mở đúng tab reddit.com / threads.com rồi export lại |
| `No login cookie (reddit_session) found` / `(sessionid)` | Export khi chưa đăng nhập xong | Đăng nhập, tải lại trang, kiểm tra có `reddit_session` / `sessionid` trong Cookie-Editor rồi export lại. Nếu đã đăng nhập mà vẫn báo, gửi **danh sách tên cookie** (dòng `Imported ...`, không có giá trị) cho người code để sửa |
| `cookies.txt line does not have 7 tab-separated fields` | Chọn nhầm định dạng **Header String** | Export lại bằng **JSON** (hoặc **Netscape**) |
| `JSONDecodeError` | Dán thiếu, hoặc Notepad lưu sai bảng mã | Export lại, lưu với Encoding UTF-8 |
| Collector báo `not logged in (run reddit.session)` sau vài ngày/tuần | Phiên hết hạn hoặc đã đăng xuất | Làm lại từ bước 2 hoặc 3 |
| Collector báo `redirected to login` / `challenge` / `checkpoint` | Trang yêu cầu xác minh | Mở trang bằng profile Chrome phụ, tự xác minh, rồi export và nạp lại. Collector không bao giờ tự vượt bước này |

---

## 6. Không có Cookie-Editor (cách thủ công)

Chỉ cần đúng một cookie đăng nhập.

1. Ở tab reddit.com (hoặc threads.com), nhấn **F12** → tab **Application** → *Storage* → **Cookies** → chọn `https://www.reddit.com` (hoặc `https://www.threads.com`).
2. Tìm dòng `reddit_session` (hoặc `sessionid`), nhấp đúp vào cột **Value** và copy.
3. Tạo file JSON bằng Notepad, thay `GIÁ_TRỊ` bằng chuỗi vừa copy:

   Reddit:
   ```json
   [{"name": "reddit_session", "value": "GIÁ_TRỊ", "domain": ".reddit.com", "path": "/", "secure": true, "httpOnly": true}]
   ```

   Threads:
   ```json
   [{"name": "sessionid", "value": "GIÁ_TRỊ", "domain": ".threads.com", "path": "/", "secure": true, "httpOnly": true}]
   ```
4. Nạp bằng lệnh `--import` như mục 2 hoặc 3, rồi xóa file.

Cách này đủ để đăng nhập, nhưng thiếu các cookie phụ nên trang có thể hỏi xác minh thường hơn. Ưu tiên dùng Cookie-Editor.
