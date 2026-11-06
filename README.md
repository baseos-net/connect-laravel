# BaseOS Connect for Laravel

Publish articles from BaseOS onto your Laravel website.
Đăng bài từ BaseOS lên website Laravel của bạn.

You write where a post is saved. Connecting, checking requests, and skipping duplicates are already in the package.
Bạn chỉ viết chỗ lưu bài. Ghép nối, kiểm tra yêu cầu và bỏ bài trùng đã có sẵn trong package.

Laravel 10–13 · PHP 8.1 or newer.

## Install · Cài đặt

```bash
composer require baseos/connect-laravel
php artisan vendor:publish --tag=baseos-connect-config
php artisan migrate
```

In `.env`, set the public site address and the class that stores posts.
Trong `.env`, đặt địa chỉ website và class lưu bài.

```dotenv
APP_URL=https://example.com
BASEOS_REPOSITORY=App\BaseOS\PostRepository
```

`APP_URL` must be HTTPS. BaseOS does not call a plain HTTP site.
`APP_URL` phải là HTTPS. BaseOS không gọi website HTTP thường.

## Save posts · Lưu bài

Implement four methods. A full example is in [`examples/SingleSiteRepository.php`](./examples/SingleSiteRepository.php).
Viết bốn hàm. Ví dụ đầy đủ nằm ở [`examples/SingleSiteRepository.php`](./examples/SingleSiteRepository.php).

| Method | You do |
|---|---|
| `upsert` | Create or update the post. The same BaseOS article must not create two rows. |
| `setState` | Publish, unpublish, or trash the post you already saved. |
| `find` | Look up your row from the BaseOS article id. |
| `fetch` | Look up your row from your own id. Return null when it is gone. |

| Hàm | Bạn làm |
|---|---|
| `upsert` | Tạo hoặc cập nhật bài. Cùng một bài BaseOS không được tạo hai dòng. |
| `setState` | Xuất bản, gỡ, hoặc bỏ vào thùng bài đã lưu. |
| `find` | Tìm bài của bạn theo mã bài BaseOS. |
| `fetch` | Tìm bài theo mã của bạn. Trả null khi bài không còn. |

Read the article with `title()`, `slug()`, `bodyHtml()`, `isPublished()`, and `contentId()`.
Đọc bài bằng `title()`, `slug()`, `bodyHtml()`, `isPublished()` và `contentId()`.

Categories, custom fields, and “check with the website” are in the same example file.
Chuyên mục, trường riêng và «Kiểm tra với website» nằm trong cùng file ví dụ.

## Pair · Ghép với BaseOS

1. In BaseOS, open the project, then **Website connection**, and create a pairing code. It lasts 15 minutes.
   Trong BaseOS, mở dự án → **Kết nối website** → tạo mã ghép. Mã hết hạn sau 15 phút.
2. On the website server, run:
   Trên máy chủ website, chạy:

```bash
php artisan baseos:pair CODE
```

The site then shows as connected in BaseOS. Open **Publishing setup** there to map categories.
Website hiện là đã kết nối trong BaseOS. Mở **Cấu hình đăng bài** ở đó để gắn chuyên mục.

One codebase, several websites: add `--tenant=your-site-id` for each site.
Một mã nguồn, nhiều website: thêm `--tenant=mã-website` cho từng website.

## Everyday commands · Lệnh hay dùng

| | |
|---|---|
| Connection health · Tình trạng kết nối | `php artisan baseos:status` |
| Send categories and fields again · Gửi lại chuyên mục và trường | `php artisan baseos:sync` |
| Explain a setup problem · Chỉ lỗi cài đặt | `php artisan baseos:doctor` |
| Remove the connection on this website · Gỡ kết nối trên website | `php artisan baseos:disconnect CONNECTION_ID` |

After disconnect, revoke that connection in BaseOS too.
Sau khi gỡ trên website, thu hồi kết nối đó trong BaseOS.
