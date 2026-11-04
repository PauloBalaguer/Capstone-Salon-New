# Leo Mejillano Salon Group — Salon Management System

Capstone project: a web system for booking salon and home services, taking
payments, and running the day-to-day work of the Daraga, Yashano and Cabangan
branches across five panels (guest, customer, admin, cashier, staff).

## Tech stack

- **PHP 8** (PDO, cURL): backend endpoints and business rules
- **MySQL / MariaDB** (XAMPP): database
- **HTML + Tailwind CSS v4**: pages and design (`npm run build`)
- **JavaScript** (vanilla): page behaviour, `fetch()` calls to the backend
- **Brevo API** for email, **PayMongo** for online payments

## How the project is organized

The project is split into three layers, and each layer has one folder per panel:

| Folder      | Holds                  | Meaning                  |
|-------------|------------------------|--------------------------|
| `frontend/` | HTML pages + CSS       | what the user sees       |
| `function/` | JavaScript             | what the page does       |
| `backend/`  | PHP endpoints          | what the server does     |

Panel order everywhere: **Guest → Login → Customer → Admin → Cashier → Staff → Shared**
(`public/` = Guest, `auth/` = Login).

```
index.html                Landing page
frontend/                 🎨 what the user sees
  public/  auth/  customer/  admin/  cashier/  staff/
  shared/                   Tailwind (input.css → output.css), palettes, image/, icon/, video/
function/                 ⚡ what the page does
  public/  auth/  customer/  admin/  cashier/  staff/
  shared/                   shared JS (toast, address form, booking validation, ...)
backend/                  🖥️ what the server does
  public/  auth/  customer/  admin/  cashier/  staff/
  shared/
    config/
      setup/                env (private keys), database, session, mail, sms, cors, url
      booking/              scheduling, validation, reschedule, cancellation, waitlist, home service
      payments/             reservation & home-service payments, PayMongo, payment log, revenue report
      notifications/        customer/staff notifiers, email outbox
      security/             audit log, end-of-day lock, rate limiter, account setup
      helpers/              loyalty tier, address, admin booking list
    cron/                   scheduled workers (email queue, reminders)
    data/                   static data (Albay locations)
    storage/sessions/       PHP session files (git-ignored)
database/                 🗄️ database
  schema/                 capstone.sql (full schema)
  migrations/             002_… → 051_… numbered changes
  seeders/                demo / landing data
  scripts/                CLI helpers (create_admin, create_cashier, sync_*, refresh_*)
  data/                   import reference data
  docs/                   database implementation notes
```

## The panels

Each panel lives in `frontend/<panel>/`, `function/<panel>/` and `backend/<panel>/`.

- **Guest** (`public`): browse services, book an appointment or home service, pay online, track a booking, leave feedback.
- **Login** (`auth`): register with email OTP, log in, set up an account and reset a forgotten password.
- **Customer** (`customer`): dashboard, booking, appointments, home service, reviews, profile and notifications.
- **Admin** (`admin`): bookings, customers, employees, promotions, inventory, feedback, reports and settings for all branches.
- **Cashier** (`cashier`): one branch's front desk: walk-ins, appointment status, staff assignment, payment, receipts, stock and end-of-day close.
- **Staff** (`staff`): assigned appointments, attendance, inventory use, ranking and profile.

## Local setup (XAMPP)

1. Clone into `C:\xampp\htdocs\Capstone-Salon`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel. PHP 8+ with
   cURL, OpenSSL and mbstring enabled.
3. In phpMyAdmin create a database named `new_capstone_salon` and import
   `database/schema/capstone.sql`. The app connects to `127.0.0.1:3306` as `root`
   with no password; to use anything else, set `DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USERNAME` and `DB_PASSWORD` in the private `.env` from the next step.
4. Install the PHP packages and create the private settings file **outside
   htdocs**, then fill in your PayMongo, Brevo and textbee keys in your editor:
   ```powershell
   composer install
   mkdir C:\xampp\salon-private
   copy .env.example C:\xampp\salon-private\.env
   C:\xampp\php\php.exe backend\shared\config\setup\env.php   # shows set / MISSING, never values
   ```
   To keep the file elsewhere, set `SALON_SECRETS_DIR` to its folder. On a
   hosting server, set the same names as environment variables instead.
5. Create staff-side accounts from the command line:
   ```powershell
   C:\xampp\php\php.exe database\scripts\create_admin.php admin@example.com "StrongPass1!" "Admin Name"
   C:\xampp\php\php.exe database\scripts\create_cashier.php cashier@example.com "StrongPass1!" daraga "Daraga Front Desk"
   ```
6. (Only when you change Tailwind classes) `npm install` then `npm run build`.
7. Open `http://localhost/Capstone-Salon/`.

### Scheduled tasks (Windows Task Scheduler)

Run these every few minutes with `C:\xampp\php\php.exe` as the program:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Capstone-Salon\backend\shared\cron\sendQueuedEmails.php
C:\xampp\php\php.exe C:\xampp\htdocs\Capstone-Salon\backend\shared\cron\sendAppointmentReminders.php
```

`sendAppointmentReminders.php` also queues PayMongo payment reminders; see
`backend/shared/cron/PAYMENT_REMINDERS.md`.

## Deploying to Vercel (Container runtime, beta)

`Dockerfile.vercel` builds an Apache + PHP image. Same URLs as XAMPP, but only
`index.html`, `frontend/`, `function/` and the panel folders of `backend/` are reachable.

1. **Database:** Vercel doesn't host MariaDB, so use an external MariaDB 10.3+
   server (not MySQL) that accepts TLS connections from any IP, because Vercel
   containers have no fixed address. Import the file from
   `database/scripts/export_for_hosting.php`, then run
   `database/migrations/057_php_sessions.sql` on it.
2. **Environment variables** (Project Settings, Production): `DB_HOST`, `DB_PORT`,
   `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`,
   `DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt`, `APP_URL`, `CRON_SECRET`, plus
   the PayMongo, Brevo and textbee keys from `.env.example`.
3. **PayMongo:** register `https://your-domain.com/backend/public/paymongoWebhook.php`
   and put the returned secret in `PAYMONGO_WEBHOOK_SECRET`.
4. **Cron:** `vercel.json` runs each job once a day, the most the Hobby plan
   allows. On Pro, change them to `*/5 * * * *`: payment reminders have a short
   window and daily runs will miss most of them.
5. **Check after deploying:** log in on two tabs and refresh repeatedly, try a
   test payment, and open `frontend/shared/image/Daraga.png` and
   `frontend/shared/video/gallery-07.mp4`. Both are over Vercel's documented
   4.5 MB limit, so compress them or move them to Vercel Blob if they fail.

## Deploying to a live server

To use traditional hosting instead of Vercel, pick a host that has all of these:

- **Apache or LiteSpeed**, because the `.htaccess` file blocks the private
  folders. Nginx ignores it, which would expose them.
- **PHP 8.1+** with PDO MySQL, cURL, OpenSSL and mbstring.
- **MariaDB 10.3+, not MySQL.** Booking references use a MariaDB sequence.
- **Cron jobs and HTTPS.**

### 1. Prepare on your computer

Change the passwords of the admin and cashier accounts you'll use live, then
remove any test bookings you don't want to keep: they are copied too. Then run:

```powershell
C:\xampp\php\php.exe database\scripts\export_for_hosting.php
```

This writes `C:\xampp\salon-private\salon_live_import.sql`. Keep it out of Git
and out of the web folder, since it holds account data.

### 2. Upload the code

Put the project **in the web root** (usually `public_html`), including the
`vendor/` folder from `composer install`. Leave out `node_modules/`, `tests/`
and `.git/`. If the host has no Composer, upload your local `vendor/` folder.
Make sure `backend/shared/storage/sessions/` is writable.

### 3. Create the database

On the host's database page, create a database and user, then import
`salon_live_import.sql` through phpMyAdmin.

### 4. Add the settings

Create `salon-private/.env` **next to** `public_html`, not inside it, from
`.env.example`. If the host has an environment variables page, you can enter
the same names there instead. Set `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USERNAME`, `DB_PASSWORD`, `APP_URL`, `APP_DEBUG=false` and your PayMongo,
Brevo and textbee keys.

### 5. Turn on HTTPS

Enable the host's SSL certificate and its force-HTTPS option **before logging
in**. When `APP_URL` starts with `https://`, login only works over HTTPS.

### 6. Connect the outside services

- **PayMongo:** register the webhook
  `https://your-domain.com/backend/public/paymongoWebhook.php`, then put the
  returned `whsk_...` secret in `PAYMONGO_WEBHOOK_SECRET`. Keep test keys until
  you've tried a full payment.
- **Cron:** run each of these every few minutes, using the PHP path the host shows:
  ```
  php /path/to/public_html/backend/shared/cron/sendQueuedEmails.php
  php /path/to/public_html/backend/shared/cron/sendQueuedSms.php
  php /path/to/public_html/backend/shared/cron/sendAppointmentReminders.php
  ```

### 7. Check it

- These must return **404 or 403**: `/database/schema/capstone.sql`,
  `/tests/env_test.php`, `/backend/shared/config/setup/env.php`, `/vendor/autoload.php`, `/.env.example`.
- Log in to each panel, book an appointment, and pay with test keys.
- A **500 error on every page** usually means the host doesn't allow
  `Options -Indexes` in `.htaccess`. Remove that line and turn off directory
  listing in the host's panel instead.
- **"Database connection failed"** means a `DB_*` value is wrong.
