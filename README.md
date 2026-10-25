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
3. In phpMyAdmin create a database named `new capstone_salon` (with the space)
   and import `database/schema/capstone.sql`. Connection settings (host,
   port `3307`, user `root`, empty password) are in
   `backend/shared/config/setup/database.php`; change the port if your MySQL uses 3306.
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
