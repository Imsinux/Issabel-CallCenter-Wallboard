# Issabel/FreePBX Call Center Wallboard
*(Farsi & English Documentation)*

## 🇬🇧 English Description

**Advanced Real-time Wallboard for Issabel & FreePBX**
This is a comprehensive, standalone PHP-based Wallboard designed for call centers using Asterisk-based systems like Issabel or FreePBX. It provides real-time monitoring of queues, agents, and KPIs without needing complex configurations.


https://github.com/user-attachments/assets/b042810d-4ef6-4132-96c2-4b5981c9fffd


### ✨ Features
* **Real-time Monitoring:** Live view of waiting calls, answered calls, and abandoned calls.
* **Agent Status:** See which agents are Available, On Call, or Paused (with pause duration).
* **KPI Dashboard:** Calculates Answer Rate %, SLA %, Average Wait Time, and Average Talk Time.
* **Auto-Refresh:** Data updates automatically every 3 seconds using AJAX.
* **Charts:** Beautiful visualizations for Call Distribution and SLA.
* **Frequent Callers:** Automatically detects and lists phone numbers with 3 or more calls per day.
* **Workforce Management:** Tracks agent pause times per day.
* **Excel Export:** Download full reports including Agent Summary and Call Logs in XLSX format.
* **Dark & Light Mode:** Switch themes instantly.
* **Secure Login:** Simple built-in authentication system.

### 🚀 Installation Guide

1.  **Download Files:**
    Download the repository files (`wallboard.php`, `callcenter.php`, `queue_agent_summary.php`, `logout.php`).

2.  **Copy to Server:**
    Upload these files to your server's web directory. The standard path for Issabel/FreePBX is:
    `/var/www/html/`


### 🖥️ How to Use

1.  Open your web browser.
2.  Navigate to your server's IP address followed by the file name:
    `http://YOUR_SERVER_IP/wallboard.php`
    **💡 Important:** The login page is dark by default. **Click the lamp's yellow button to turn on the light. The login form will appear only after the light is on.
4.  **Default Credentials:**
    * Username: `admin`
    * Password: `WallBoard`
    *(It is highly recommended to change the password in `wallboard.php` file)*.

---

## 🇮🇷 راهنمای فارسی

**والبورد پیشرفته و زنده برای ایزابل و FreePBX**
این پروژه یک داشبورد مدیریتی کامل و مستقل است که با زبان PHP نوشته شده و برای مراکز تماس مبتنی بر استریسک (مانند ایزابل) طراحی شده است. این ابزار به شما اجازه می‌دهد وضعیت صف‌ها و اپراتورها را به صورت لحظه‌ای رصد کنید.

### ✨ قابلیت‌ها و امکانات
* **مانیتورینگ زنده (Live):** نمایش لحظه‌ای تماس‌های در انتظار، پاسخ داده شده و از دست رفته.
* **وضعیت اپراتورها:** نمایش وضعیت دقیق (آزاد، در حال مکالمه، یا در حال استراحت/Pause) به همراه مدت زمان.
* **شاخص‌های کلیدی (KPI):** محاسبه خودکار درصد پاسخگویی، سطح سرویس (SLA)، میانگین زمان انتظار و مکالمه.
* **بروزرسانی خودکار:** اطلاعات صفحه هر ۳ ثانیه بدون نیاز به رفرش کردن بروز می‌شوند.
* **نمودارهای گرافیکی:** نمایش وضعیت تماس‌ها و عملکرد به صورت نمودار.
* **شناسایی تماس‌های پرتکرار:** لیست کردن شماره‌هایی که در روز ۳ بار یا بیشتر تماس گرفته‌اند.
* **مدیریت نیروی کار:** محاسبه دقیق میزان زمان استراحت (Pause) اپراتورها در طول روز.
* **خروجی اکسل:** امکان دانلود گزارش کامل عملکرد و ریز مکالمات با فرمت Excel.
* **تم تاریک و روشن:** دارای قابلیت تغییر تم (Dark/Light Mode).
* **سیستم ورود امن:** دارای صفحه لاگین اختصاصی.

### 🚀 آموزش نصب و راه‌اندازی

۱. **دانلود فایل‌ها:**
تمامی فایل‌های پروژه (`wallboard.php`, `callcenter.php`, `queue_agent_summary.php`, `logout.php`) را دانلود کنید.

۲. **کپی در سرور:**
فایل‌ها را باید در مسیر وب سرور خود کپی کنید. مسیر استاندارد در ایزابل به صورت زیر است:
`/var/www/html/`

۳. دسترسی‌ها (Permissions): مطمئن شوید که وب‌سرور دسترسی خواندن فایل‌های لاگ استریسک را دارد (معمولا به صورت پیش‌فرض دارد، اما اگر ارور داد دستور زیر را بزنید):
chown -R asterisk:asterisk /var/www/html/wallboard
 💡 نکته مهم: صفحه ورود ابتدا کاملاً تاریک است. برای دیدن فرم ورود، روی دکمه زرد چراغ کلیک کنید تا لامپ روشن شود و فیلد یوزرنیم/پسورد نمایش داده شود.

🖥️ نحوه استفاده
۱. مرورگر خود (کروم یا فایرفاکس) را باز کنید. ۲. آدرس آی‌پی سرور خود را به همراه نام فایل وارد کنید: http://YOUR_SERVER_IP/wallboard.php 

. اطلاعات ورود پیش‌فرض:
نام کاربری: admin
رمز عبور: WallBoard

(توصیه می‌شود برای امنیت بیشتر، فایل wallboard.php را باز کنید و رمز عبور را تغییر دهید).
