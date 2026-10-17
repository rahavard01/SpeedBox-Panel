<div align="center" dir="ltr">

<img src="./docs/images/speedbox-panel-banner.svg" alt="SPEEDBOX PANEL" width="1060">

<p dir="rtl"><strong>مدیریت کاربران، فروش اشتراک، نمایندگان و سرورها در یک پنل</strong></p>

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square)
![Vue](https://img.shields.io/badge/Vue-3-42B883?style=flat-square)
![Kernels](https://img.shields.io/badge/Kernels-Xray%20%2B%20Sing--box-22D3EE?style=flat-square)

</div>

---

<div dir="rtl">

## 🚀 معرفی

**<span dir="ltr">SPEEDBOX PANEL</span>** یک پنل فارسی برای راه‌اندازی و مدیریت سرویس‌های اشتراک است. از ساخت کاربر و تعیین تعرفه تا دریافت پرداخت، مدیریت نمایندگان، ساخت نود و نصب سرویس روی سرورها، همه از داخل همین پنل انجام می‌شود.

پنل اصلی و پنل مدیریت سرورها، ورود و دیتابیس مشترک دارند. از منوی انتخاب پنل می‌توانید بین این دو بخش جابه‌جا شوید. نصب پروژه با یک اسکریپت انجام می‌شود و تنظیمات، کاربران و زیرساخت در همان نصب مدیریت می‌شوند.

### ✨ امکانات

| بخش | امکانات |
| :---: | :---: |
| 👥 کاربران و اشتراک‌ها | ساخت اکانت، تمدید و افزایش ترافیک، تعرفه‌های زمان‌دار و حجمی، سقف سرعت و اتصال، نمایش دستگاه‌های آنلاین و آخرین آنلاین |
| 🤝 نمایندگان و فروش | پنل نمایندگان، کیف پول، دسترسی به تعرفه‌ها، فاکتورها و سوابق تراکنش‌ها |
| 💳 پرداخت‌ها | کارت‌به‌کارت، بررسی رسید، تأیید با پیامک بانکی و پرداخت ارز دیجیتال؛ قابل تنظیم از پنل |
| 🤖 ربات‌ها و پشتیبانی | ربات نمایندگان و کاربران، خرید و تمدید، اعلان‌ها، هشدار پایان اشتراک و ترافیک، سوالات متداول و تیکت‌ها |
| 🖥️ سرورها و نودها | افزودن سرور با <span dir="ltr">SSH</span>، نصب و نصب مجدد سرویس، انتخاب هسته، ساخت و مرتب‌سازی نودها، گروه‌های دسترسی و مسیریابی |
| 📊 پایش و عملیات | وضعیت اتصال سرورها و نودها، مصرف ترافیک، منابع سرور، گزارش عملیات و وضعیت صف‌ها |
| 🔗 صفحه اشتراک | لینک اشتراک اختصاصی، نمایش وضعیت و مصرف، دستگاه‌های آنلاین، <span dir="ltr">QR Code</span> و راهنمای اتصال در مرورگر |
| 🎨 رابط کاربری | چیدمان فارسی، اعداد و تاریخ‌های فارسی، نمایش واکنش‌گرا برای موبایل و دسکتاپ |

### 🌐 پروتکل‌ها و هسته‌ها

هر نود، پروتکل و هستهٔ اجرایی خودش را دارد. هر دو هسته در بستهٔ سرویس سرور قرار دارند و از داخل پنل انتخاب می‌شوند.

<div align="center">
<table align="center" dir="rtl">
<thead>
<tr><th align="center">پروتکل</th><th align="center" dir="ltr">Xray</th><th align="center" dir="ltr">Sing-box</th></tr>
</thead>
<tbody>
<tr><td align="center" dir="ltr">VLESS, VMess, Trojan, Shadowsocks</td><td align="center">✅</td><td align="center">✅</td></tr>
<tr><td align="center" dir="ltr">Hysteria 2</td><td align="center">✅</td><td align="center">✅</td></tr>
<tr><td align="center" dir="ltr">Hysteria 1</td><td align="center">—</td><td align="center">✅</td></tr>
<tr><td align="center" dir="ltr">TUIC, AnyTLS, Naive, Mieru</td><td align="center">—</td><td align="center">✅</td></tr>
<tr><td align="center" dir="ltr">SOCKS, HTTP</td><td align="center">✅</td><td align="center">✅</td></tr>
</tbody>
</table>
</div>

امکان تنظیم **<span dir="ltr">TLS</span>، <span dir="ltr">Reality</span>، گواهی، مسیریابی و خروجی‌های سفارشی** وجود دارد. انتقال‌هایی مانند **<span dir="ltr">TCP</span>، <span dir="ltr">WebSocket</span>، <span dir="ltr">gRPC</span>، <span dir="ltr">HTTPUpgrade</span> و <span dir="ltr">XHTTP</span>** بر اساس سازگاری پروتکل و هسته انتخاب می‌شوند. **<span dir="ltr">XHTTP</span>، <span dir="ltr">KCP</span>، قالب <span dir="ltr">TCP</span> + <span dir="ltr">HTTP</span> و رمزنگاری <span dir="ltr">VLESS</span> به هستهٔ <span dir="ltr">Xray</span> نیاز دارند.**

لینک اشتراک برای برنامهٔ <span dir="ltr">SpeedBox</span> و کلاینت‌های سازگار مانند <span dir="ltr">Shadowrocket</span>، <span dir="ltr">Streisand</span>، <span dir="ltr">Sing-box</span>، <span dir="ltr">Clash</span>، <span dir="ltr">Clash Meta</span>، <span dir="ltr">Stash</span>، <span dir="ltr">Surge</span> و <span dir="ltr">Quantumult X</span> قابل استفاده است. فرمت دریافتی و پروتکل‌های قابل استفاده به کلاینت و نسخهٔ آن بستگی دارد.

پروفایل‌های هوشمند **<span dir="ltr">SMART</span> برای هر کشور و <span dir="ltr">SMART ALL</span>** در خروجی سازگار با <span dir="ltr">Streisand</span> در دسترس‌اند. تنظیمات **<span dir="ltr">ECH</span>** نیز در فرمت‌های پشتیبانی‌شده به کلاینت منتقل می‌شوند.

---

</div>

<div dir="rtl" align="right">

## 📖 نصب و راه‌اندازی <span dir="ltr">SpeedBox Panel</span>


<h3 dir="ltr" align="left">1) Install aaPanel</h3>

از طریق دستور زیر، <span dir="ltr">aaPanel</span> را روی سرور خود نصب کنید:

<div dir="ltr" align="left">

```bash
URL=https://www.aapanel.com/script/install_6.0_en.sh && \
if [ -f /usr/bin/curl ];then curl -ksSO "$URL" ;else wget --no-check-certificate -O install_6.0_en.sh "$URL";fi && \
bash install_6.0_en.sh aapanel
```

</div>

---

<h3 dir="ltr" align="left">2) Basic Environment Setup</h3>

<h4 dir="ltr" align="left">Install Applications</h4>

1. وارد مسیر زیر شوید:

<p dir="ltr" align="left"><strong>aaPanel → App Store</strong></p>

2. برنامه‌های زیر را نصب کنید:

<div dir="ltr" align="left">

- **Nginx**
- **MySQL 8**
- **PHP 8.2**

</div>

<h4 dir="ltr" align="left">Install PHP Extensions</h4>

1. وارد مسیر زیر شوید:

<p dir="ltr" align="left"><strong>App Store → PHP 8.2 → Install extensions</strong></p>

2. افزونه‌های زیر را نصب یا فعال کنید:

<div dir="ltr" align="left">

- **redis**
- **fileinfo**
- **swoole**
- **readline**
- **event**
- **mbstring**
- **bcmath**

</div>

افزونه‌ای که از قبل فعال است، نیاز به نصب مجدد ندارد. اگر گزینه‌ای در فهرست نبود، وضعیت آن را از <span dir="ltr">PHP 8.2 → phpinfo → View phpinfo()</span> بررسی کنید. اسکریپت نصب نیز افزونه‌های ضروری را کنترل می‌کند. **افزونهٔ <span dir="ltr">Swoole</span> برای اجرای <span dir="ltr">Octane</span> لازم است.**

<h4 dir="ltr" align="left">Enable Required PHP Functions</h4>

وارد مسیر زیر شوید:

<p dir="ltr" align="left"><strong>App Store → PHP 8.2 → Disabled functions</strong></p>

گزینه‌های زیر را **در صورت وجود** حذف کنید و پس از آن <span dir="ltr">PHP</span> را ری‌استارت کنید:

<div dir="ltr" align="left">

- **putenv**
- **proc_open**
- **proc_close**
- **proc_get_status**
- **proc_terminate**
- **pcntl_alarm**
- **pcntl_signal**
- **pcntl_async_signals**
- **symlink**

</div>

<h4 dir="ltr" align="left">Install Server Tools</h4>

دستورهای زیر را در ترمینال سرور <span dir="ltr">Ubuntu / Debian</span> اجرا کنید:

<div dir="ltr" align="left">

```bash
apt-get update
apt-get install -y git curl rsync ca-certificates openssh-client sshpass tar unzip openssl coreutils
export PATH="/www/server/php/82/bin:$PATH"
```

</div>

---

<h3 dir="ltr" align="left">3) Create Website</h3>

1. وارد مسیر زیر شوید:

<p dir="ltr" align="left"><strong>aaPanel → Website → Add site</strong></p>

2. موارد زیر را تنظیم کنید:

<ul dir="ltr" align="left">
<li><strong>Domain:</strong> <span dir="rtl">دامنهٔ پنل</span></li>
<li><strong>Database:</strong> <span dir="rtl">گزینهٔ <span dir="ltr">MySQL</span>؛ یک دیتابیس ایجاد کنید و اطلاعات آن را نگه دارید.</span></li>
<li><strong>PHP Version:</strong> <span dir="rtl">نسخهٔ <span dir="ltr">8.2</span></span></li>
</ul>

3. سایت را ایجاد کنید.

رکورد دامنه را روی آی‌پی سرور قرار دهید.

---

<h3 dir="ltr" align="left">4) Deploy SpeedBox Panel</h3>

در دستورها، <code dir="ltr">your-domain</code> را با دامنهٔ سایت خود جایگزین کنید.

**ورود به دایرکتوری سایت در ترمینال**

<div dir="ltr" align="left">

```bash
cd /www/wwwroot/your-domain
```

</div>

**پاک‌سازی فایل‌های پیش‌فرض در صورت وجود**

<div dir="ltr" align="left">

```bash
chattr -i .user.ini || true
rm -f .htaccess 404.html 502.html index.html .user.ini
```

</div>

**دریافت پروژه در دایرکتوری سایت**

<div dir="ltr" align="left">

```bash
git clone https://github.com/rahavard01/SpeedBox-Panel.git .
```

</div>

**اجرای اسکریپت نصب**

<div dir="ltr" align="left">

```bash
export PATH="/www/server/php/82/bin:$PATH"
bash install.sh
```

</div>

اسکریپت، پیش‌نیازها را بررسی می‌کند، وابستگی‌ها را نصب می‌کند و سرویس‌های صف و <span dir="ltr">Octane</span> را راه‌اندازی می‌کند.

</div>

<p dir="rtl" align="right">اطلاعات زیر در زمان نصب پرسیده می‌شود:</p>

<div dir="ltr" align="center">

<table align="center">
<thead>
<tr><th align="center">Prompt</th><th align="center" dir="rtl">مقدار</th></tr>
</thead>
<tbody>
<tr><td align="left">APP URL</td><td align="right" dir="rtl">آدرس کامل سایت؛ مانند <code dir="ltr">https://panel.example.com</code></td></tr>
<tr><td align="left">DB HOST</td><td align="right" dir="rtl">آدرس دیتابیس؛ معمولاً <code dir="ltr">127.0.0.1</code></td></tr>
<tr><td align="left">DB PORT</td><td align="right" dir="rtl">پورت دیتابیس؛ معمولاً <code dir="ltr">3306</code></td></tr>
<tr><td align="left">DB DATABASE</td><td align="right" dir="rtl">نام دیتابیسی که ساختید</td></tr>
<tr><td align="left">DB USERNAME</td><td align="right" dir="rtl">نام کاربری دیتابیس</td></tr>
<tr><td align="left">DB PASSWORD</td><td align="right" dir="rtl">رمز دیتابیس</td></tr>
<tr><td align="left">ADMIN EMAIL</td><td align="right" dir="rtl">ایمیل ورود مدیر</td></tr>
<tr><td align="left">ADMIN PASSWORD</td><td align="right" dir="rtl">رمز ورود مدیر</td></tr>
<tr><td align="left">ADMIN PANEL CODE</td><td align="right" dir="rtl">کد ساخت اکانت‌های مدیر؛ مانند <code dir="ltr">SBADMIN</code></td></tr>
</tbody>
</table>

</div>

<div dir="rtl" align="right">

---

<h3 dir="ltr" align="left">5) Site Configuration</h3>

از قسمت <span dir="ltr">Website</span>، سایت خود را انتخاب کنید و **<span dir="ltr">Site directory → Running directory</span>** را روی <code dir="ltr">/public</code> قرار دهید.

در بخش **<span dir="ltr">URL rewrite</span>**، متن زیر را ثبت کنید:


<div dir="ltr" align="left">

```nginx
location = /index.php {
    try_files /not_exists @speedbox_octane;
}

location / {
    try_files $uri $uri/ @speedbox_octane;
}

location @speedbox_octane {
    set $suffix "";
    if ($uri = /index.php) {
        set $suffix ?$query_string;
    }
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Forwarded-Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Port $server_port;
    proxy_set_header Connection "";
    proxy_pass http://127.0.0.1:7010$suffix;
    proxy_read_timeout 60s;
}
```

</div>

گواهی معتبر دامنه را از بخش **<span dir="ltr">SSL</span>** فعال کنید. درخواست‌ها از طریق <span dir="ltr">Nginx</span> به سرویس <span dir="ltr">Octane</span> روی پورت داخلی <code dir="ltr">7010</code> می‌رسند؛ این پورت را در فایروال عمومی باز نکنید.

---

<h3 dir="ltr" align="left">6) Background Services</h3>

برای اجرای زمان‌بندی، از قسمت <span dir="ltr">Cron</span> تنظیمات زیر را انجام دهید:

<p dir="ltr" align="left"><strong>aaPanel → Cron → Add task</strong></p>

<div dir="ltr" align="left">

- **Task type:** Shell Script
- **Task Name:** speedbox-schedule
- **Execute cycle:** N Minutes → 1 Minute
- **Execute user:** `www`
- **Script Content:**

</div>

<div dir="ltr" align="left">

```bash
cd /www/wwwroot/your-domain && /www/server/php/82/bin/php artisan schedule:run
```

</div>

**سرویس صف و <span dir="ltr">Octane</span> در زمان نصب، خودکار با <span dir="ltr">systemd</span> فعال می‌شوند؛ نیازی به تعریف دستی آن‌ها در <span dir="ltr">Supervisor</span> نیست.** صف <code dir="ltr">deployments</code> برای عملیات سرورها و صف <code dir="ltr">default</code> برای کارهای عمومی است.

</div>

<div dir="rtl" align="right">

---

<h3 dir="ltr" align="left">7) Initial Setup</h3>

</div>

<div dir="rtl" align="right">

آدرس <code dir="ltr">https://your-domain/login</code> را باز کنید و با اطلاعات مدیر وارد شوید.

1. آدرس و مسیر اشتراک را تنظیم کنید؛ مسیر پیش‌فرض <code dir="ltr">s</code> است.
2. گروه‌های دسترسی و پلن‌های اتصال را در پنل مدیریت سرورها بسازید.
3. تعرفه‌ها و روش‌های پرداخت را در پنل اصلی تنظیم کنید.
4. سرور و نود را اضافه کنید و با یک اکانت تست، اشتراک و اتصال را بررسی کنید.

سرور مقصد باید لینوکسی، دارای <span dir="ltr">systemd</span> و دسترسی <code dir="ltr">root</code> یا <code dir="ltr">sudo</code> بدون رمز باشد. پورت‌های موردنیاز نود را در فایروال باز کنید.

</div>

---

<div dir="rtl" align="right">

<h2 dir="ltr" align="left">🔄 Update</h2>

</div>

<p dir="rtl" align="right">از دیتابیس و فایل <code dir="ltr">.env</code> بکاپ بگیرید؛ سپس اجرا کنید:</p>

<div dir="rtl" align="right">

<div dir="ltr" align="left">

```bash
cd /www/wwwroot/your-domain
export PATH="/www/server/php/82/bin:$PATH"
bash update.sh
```

</div>

</div>

<p dir="rtl" align="right">آپدیت امن فقط روی نصب موجود با سرویس‌های <span dir="ltr">Octane/systemd</span> و صف اجرا می‌شود. نام سرویس‌ها از مسیر پروژه شناسایی می‌شود؛ سرویس سایت دیگری بازنویسی نمی‌شود. برای استقرار قدیمی <span dir="ltr">PHP-FPM</span>، ابتدا تبدیل به Octane را جداگانه انجام دهید. اجرای آپدیت با تغییرات محلی یا کار در حال اجرای صف متوقف می‌شود.</p>

<div dir="rtl" align="right">

### رفتار آپدیت امن

- PHP از `PHP_BIN` یا `PATH` انتخاب می‌شود؛ برای نمونه روی سرور PHP 8.3، `export PHP_BIN=/www/server/php/83/bin/php` را پیش از آپدیت اجرا کنید.
- مقصد آپدیت یک SHA ثابت از upstream است؛ تنها fast-forward انجام می‌شود. تغییرات محلی، فایل‌های آپلودی، سورس فرانت محلی و `.env` حذف یا بازنویسی نمی‌شوند؛ `git clean` و `reset --hard` اجرا نمی‌شوند.
- سورس قبلی، `.env` و دیتابیس در پوشهٔ خصوصی بیرون پروژه پشتیبان‌گیری می‌شوند. در صورت تغییر وابستگی‌ها، vendor قبلی نیز پشتیبان دارد. پیش‌نیاز dump در MySQL/MariaDB، `mysqldump` است؛ مسیر دلخواه را با `MYSQLDUMP_BIN` مشخص کنید.
- اگر فایل‌های migration تغییر کرده باشند، آپدیت پیش از تغییر نسخه متوقف می‌شود. پس از بررسی آن‌ها، اجرای دوباره با `SPEEDBOX_ALLOW_MIGRATIONS=1` مجاز است. پشتیبان قبل از اجرای migration گرفته می‌شود.
- اگر خطا پیش از آغاز migration رخ دهد، بازگردانی کد قبلی تلاش می‌شود؛ برنامه همچنان در maintenance می‌ماند تا بازیابی بررسی شود. پس از شروع migration، بازگردانی خودکار SQL یا بالا آوردن بی‌قید برنامه انجام نمی‌شود، چون می‌تواند داده‌های جدیدتر را از بین ببرد.
- صف هنگام بررسی توقف امن موقتاً pause می‌شود؛ اگر job رزروشده/در حال اجرا باشد، آپدیت متوقف و صف resume می‌شود. صف deployment هنگام اعمال تغییرات متوقف است. زمان‌بندی cron مستقل است؛ snapshot دیتابیس جایگزین طراحی migration سازگار با اجرای هم‌زمان نیست.
- نصب موجود، پورت و unitهای خودش را حفظ می‌کند. اگر شناسایی خودکار مبهم باشد، `SPEEDBOX_OCTANE_SERVICE` و `SPEEDBOX_QUEUE_SERVICE` را در محیط shell تعیین کنید؛ WorkingDirectory هر unit باید همین پروژه باشد.
- روی موفقیت پیام `UPDATE FINISHED`، SHA مقصد و مسیر بکاپ چاپ می‌شود. روی شکست، پیام و مسیر بکاپ را بررسی کنید؛ بدون بررسی صرفاً `artisan up` نزنید.

### پراکسی و محدودیت درخواست‌ها

پیش‌فرض `SPEEDBOX_TRUSTED_PROXIES` فقط `127.0.0.1,::1` است. اگر HAProxy یا Cloudflare در زنجیرهٔ پراکسی دارید، فقط IP/CIDRهای معتبر همان زنجیره را اضافه کنید؛ wildcard عمومی استفاده نکنید. تنظیمات پیش‌فرض جدید در config تعریف شده‌اند و نیازی به بازنویسی `.env` نصب موجود نیست. پس از تغییر `.env`، config cache را پاک/بازسازی و Octane را reload کنید.

سقف‌های پیش‌فرض در دقیقه: API عادی ۶۰ به‌ازای کاربر واردشده یا IP مهمان؛ کنترل نود ۱۲۰۰ به‌ازای IP/شناسهٔ ماشین/نود با سقف مشترک ۱۲۰۰۰ برای هر IP؛ وبهوک‌های تلگرام ۶۰۰ برای هر IP. مقادیر از `SPEEDBOX_API_RATE_LIMIT`، `SPEEDBOX_NODE_RATE_LIMIT`، `SPEEDBOX_NODE_IP_RATE_LIMIT` و `SPEEDBOX_WEBHOOK_RATE_LIMIT` قابل تنظیم‌اند. محدودکنندهٔ صریح هر endpoint همچنان اجرا می‌شود.

### تست و توسعه

این بسته به npm build یا migration جدید نیاز ندارد. برای توسعه/بیلد فرانت با نسخه‌های قفل‌شدهٔ فعلی، Node باید شرط Vite یعنی `^20.19.0 || >=22.12.0` را داشته باشد؛ سرور مصرف‌کنندهٔ build آماده نیازمند نصب Node نیست. سرویس production بدون `--watch` اجرا می‌شود. اگر watch توسعهٔ Octane لازم شد، وابستگی Chokidar را جداگانه و با lock هماهنگ فراهم و تست کنید؛ این بسته آن قابلیت اختیاری را فعال نمی‌کند.

تست‌های ایزوله (pdo_sqlite و توابع ساخت process باید در PHP CLI فعال باشند):

```bash
php scripts/tests/subscription-guards.test.php
php scripts/tests/final-audit.test.php
python3 scripts/tests/safe-update.test.py
```

تست آخر فقط مخزن‌های موقت و سرویس‌های mock را تغییر می‌دهد؛ تست Swoole یا MySQL واقعی نیست. `DatabaseSeeder` پیش‌فرض عمداً خالی است؛ مدیر با installer ساخته می‌شود و هیچ حساب نمونه با رمز عمومی ایجاد نمی‌شود.

درخواست معتبر `POST /api/v2/server/connections/lease` پس از احراز هویت ماشین و نود فعال، از سقف عمومی درخواست‌های نود و IP جداست؛ درخواست نامعتبر همچنان با همان سقف‌ها محدود می‌شود. این استثنا، محدودیت IP هر حساب، مالکیت lease یا بررسی مصرف و انقضا را حذف نمی‌کند. فاصلهٔ تمدید و Agent تغییر نکرده‌اند؛ هزینهٔ پردازش همچنان به تعداد اتصال‌های فعال بستگی دارد.

نمایش روز ریست از همان سیاست شمسی زمان‌بند استفاده می‌کند. اکانت حجمی و سیاست «بدون بازنشانی» تاریخ ریست نشان نمی‌دهند، حتی اگر تاریخ قدیمی ذخیره شده باشد. مقدار `expired_at = 0` برای همهٔ حساب‌ها، مستقل از نقش یا شناسه، بدون انقضاست؛ `NULL` نیز تا تعیین تاریخ طبق قواعد فعلی پلن معتبر است. فقط زمان واقعیِ گذشته منقضی می‌شود. هنگام ویرایش، ورودی صریح صفر حفظ می‌شود. `php scripts/preventive-preflight.php` نوع اتصال و تعداد حساب‌های دارای انقضای صفر را فقط می‌خواند؛ صفر به‌تنهایی مانع نصب نیست و هیچ تبدیل داده یا استثنای مدیر ندارد. سیاست قدیمی شروع اعتبار پلن‌های یک‌ماههٔ مشمول پس از اولین مصرف (فقط برای تاریخ NULL) تغییر نکرده است؛ صفر ذخیره‌شده وارد آن مسیر نمی‌شود.

تست مستقل `php scripts/tests/preventive-runtime.test.php` از SQLite موقت استفاده می‌کند. برای تست MySQL واقعی، روی نمونهٔ تست جداگانه متغیر `SPEEDBOX_TEST_MYSQL_PORT` یا `SPEEDBOX_TEST_MYSQL_SOCKET` و در صورت نیاز `SPEEDBOX_TEST_MYSQL_USER` و `SPEEDBOX_TEST_MYSQL_PASSWORD` را تنظیم کنید و `php scripts/tests/preventive-runtime.test.php --mysql --timed` را اجرا کنید. تست، دیتابیس تصادفی خودش را ایجاد و حذف می‌کند و `.env` پروژه را نمی‌خواند؛ اجرای زمان‌دار شامل ۷۰ ثانیه تمدید واقعی و پذیرش هم‌زمان با دو پردازش است. این تست معیار ظرفیت کل سرور نیست.

</div>

<p dir="rtl" align="right">بکاپ را بیرون از پوشهٔ پروژه نگه دارید و کلید <code dir="ltr">APP_KEY</code> را تغییر ندهید. اگر عامل سرورها تغییر کرده است، بعد از آپدیت از گزینهٔ <strong>نصب مجدد</strong> در مدیریت سرورها استفاده کنید.</p>

---

<div dir="rtl" align="right">

<details>
<summary><strong>🔧 Checks and Logs</strong></summary>

<h3 dir="ltr" align="left">PHP Requirements</h3>

**بررسی پیش‌نیازهای PHP**

<div dir="ltr" align="left">

```bash
cd /www/wwwroot/your-domain
export PATH="/www/server/php/82/bin:$PATH"
bash scripts/check-requirements.sh --octane
```

</div>

این دستور، کمبود افزونه‌های ضروری، توابع غیرفعال و ابزارهای موردنیاز را مشخص می‌کند.

<h3 dir="ltr" align="left">Queue and Scheduler</h3>

**بررسی سرویس‌های صف، Octane و زمان‌بندی**

<div dir="ltr" align="left">

```bash
systemctl status panel-speedbox-queue panel-speedbox-octane --no-pager -l
cd /www/wwwroot/your-domain
/www/server/php/82/bin/php artisan schedule:list
```

</div>

<h3 dir="ltr" align="left">Queue Log</h3>

**گزارش صف**

<div dir="ltr" align="left">

```bash
journalctl -u panel-speedbox-queue -n 100 --no-pager
```

</div>

<h3 dir="ltr" align="left">Octane Log</h3>

**گزارش Octane**

<div dir="ltr" align="left">

```bash
journalctl -u panel-speedbox-octane -n 100 --no-pager
```

</div>

<h3 dir="ltr" align="left">Application Log</h3>

**گزارش برنامه**

<div dir="ltr" align="left">

```bash
cd /www/wwwroot/your-domain
tail -n 100 storage/logs/laravel.log
```

</div>

</details>

</div>

---

<p align="center" dir="ltr">
<img src="./docs/images/speedbox-credit.svg" alt="Powered by SPEEDBOX" width="220" height="32">
</p>



### Bundled node releases

Panel installation and `bash update.sh` use the verified Linux amd64/arm64 binaries
in `agent-dist/`. They do not download Go or compile the node on the server.
The updater checks the target release before pausing workers or entering maintenance.
Missing/corrupted archives or a changed agent source without a matching release stop
before that point. Archives are ordinary Git files (not Git LFS); clone/fetch must
complete before deployment. A failed later deployment retains maintenance for checked
recovery and backs up the previous node artifacts alongside the code and database.

For developers changing `agent/` or the agent build/package scripts, run on a Linux
build machine with a working Go toolchain and Python 3:

```bash
bash scripts/build-agent-from-source.sh
php scripts/agent-artifacts.php verify .
```

This builds both architectures and regenerates the archives and source/checksum manifest.
Commit the changed source, build scripts and `agent-dist/` together. Do not regenerate
the manifest around old binaries after editing Go source. The manifest detects accidental
release mismatches; it is not a cryptographic attestation of how a binary was compiled.
Existing remote nodes receive the new binary through the separate managed-node update
step; updating the panel alone does not restart or replace remote nodes.


### Public frontend distribution

This repository ships the compiled frontend in `public/build/`. Frontend sources in `resources/js/` and `resources/css/` are maintained only in the private repository and are excluded before publication. Server-side templates in `resources/views/` remain available because the application needs them at runtime.
