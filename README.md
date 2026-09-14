# weeklyst
A free to use webapp with a shopping list and diner weekplanner. Ist's a The family planner for meals, groceries and also contains an option to add loyalty cards.

# Weeklyst — Installation & User Guide

**Version 1.8** — The family planner for meals, groceries and loyalty cards.

---

## 📋 Table of Contents

1. [Requirements](#requirements)
2. [Installation for administrators](#installation)
3. [Step 1 — Create database](#step-1-database)
4. [Step 2 — Upload files](#step-2-files)
5. [Step 3 — Fill in configuration](#step-3-config)
6. [Step 4 — First launch](#step-4-launch)
7. [Admin dashboard](#admin-dashboard)
8. [User guide](#user-guide)
9. [Admin guide](#admin-guide)
10. [Troubleshooting](#troubleshooting)

---

## Requirements

- PHP 8.0 or higher with extensions: `pdo_mysql`, `openssl`, `curl`
- MySQL 5.7 or higher (or MariaDB 10.4+)
- HTTPS connection (required for PWA and push notifications)
- SMTP access for sending emails
- Web server with `.htaccess` support (Apache)

---

## Installation

### Step 1 — Create database

1. Open **phpMyAdmin** or another MySQL management interface
2. Create a new database, e.g. `weeklyst`
3. Create a database user with full rights on that database
4. Go to **Import** and import the file:
   ```
   database/weeklyst_schema.sql
   ```
5. Note the following details for step 3:
   - Database name
   - Username
   - Password
   - Host (almost always `localhost`)

---

### Step 2 — Upload files

Upload all files from the `app/` folder to the **root of your web hosting** (`public_html` or `www`):

```
app/
├── index.html          ← Landing page + login screens
├── app.html            ← The weekly planner webapp
├── about.html          ← About page
├── privacy.html        ← Privacy policy
├── install.html        ← Installation instructions for users
├── lang.js             ← All translations (NL + EN)
├── sw.js               ← Service worker (push notifications + cache)
├── api.php             ← REST API backend
├── config.php          ← ⚠️ Configuration — see step 3
├── db.php              ← Database connection
├── mailer.php          ← Email functions
├── csrf.php            ← Bot protection
├── push.php            ← Push notifications
├── invite.php          ← Invitation emails backend
├── admin.html          ← Admin dashboard
├── admin_api.php       ← Admin API backend
├── invite_admin.html   ← Send invitations
└── .htaccess           ← Security + HTTPS redirect
```

> ⚠️ **Important:** Never store `config.php` in a public Git repository — this file contains passwords.

---

### Step 3 — Fill in configuration

Open `config.php` and fill in all values:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');    // ← change this
define('DB_USER', 'your_database_user');    // ← change this
define('DB_PASS', 'your_database_password');// ← change this

// Email (SMTP)
define('SMTP_HOST',      'mail.yourprovider.com');  // ← SMTP server
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@yourdomain.com'); // ← sender address
define('SMTP_PASS',      'your_email_password');    // ← email password
define('SMTP_FROM',      'noreply@yourdomain.com'); // ← same as SMTP_USER
define('SMTP_FROM_NAME', 'Weeklyst');

// App URL — no trailing slash!
define('APP_URL',        'https://yourdomain.com'); // ← your domain
define('ALLOWED_ORIGIN', 'https://yourdomain.com'); // ← same as APP_URL

// Session security — choose a random string of at least 32 characters
define('COOKIE_SECRET', 'replace-this-with-a-random-long-string');
define('COOKIE_NAME',   'wl_sess');
define('COOKIE_DAYS',   30);
define('SESSION_DIR',   __DIR__ . '/.sessions');

// Admin password — for admin.html and invite_admin.html
define('INVITE_PASSWORD', 'choose-a-strong-admin-password');
```

**Tips:**
- Generate a random `COOKIE_SECRET` using: `openssl rand -hex 32`
- Use a strong, unique password for `INVITE_PASSWORD`
- Make sure the `.sessions/` folder is writable by the web server, or create it manually

---

### Step 4 — First launch

1. Go to `https://yourdomain.com`
2. Click **Create free account**
3. Enter your name, email address and password
4. Confirm your email address using the code you receive
5. Create a family with a name
6. You are now the admin of the family

---

## Admin dashboard

The admin dashboard is available at `https://yourdomain.com/admin.html`

Log in with the `INVITE_PASSWORD` from `config.php`.

**Features:**
- **Version management** — set the version number; users automatically receive an update notification
- **Invitations** — send invitation emails to new family members or people who want to preview the app
- **Families** — overview of all families with search function and delete option
- **Delete requests** — process cancellation requests from families

---

## User guide

### Installing the app on your phone

**iPhone / iPad (Safari):**
1. Go to `https://yourdomain.com` in Safari
2. Tap the share icon (square with arrow pointing up)
3. Choose **Add to Home Screen**
4. Tap **Add**

**Android (Chrome):**
1. Go to `https://yourdomain.com` in Chrome
2. Tap the three dots in the top right
3. Choose **Add to home screen**
4. Tap **Add**

> Tip: Always open the app via the home screen icon for the best experience and to receive push notifications.

---

### Joining a family

You need an **invitation link** or a **family code** from the admin.

**Via invitation link:**
1. Tap the link you received
2. Enter a name and password
3. You are immediately a member of the family

**Via family code:**
1. Go to `https://yourdomain.com`
2. Tap **🔑 Join with invite code**
3. Enter the family code (6 characters), your name and a password

---

### Logging in

1. Go to `https://yourdomain.com`
2. Tap **Log in**
3. Choose the **👨‍👩‍👧 Family member** tab
4. Enter your family code, name and password

---

### Using the weekly planner

- **Meals** — fill in what you eat each day; today is highlighted in green
- **Groceries** — add items and check them off while shopping
- **Ideas** — save dinner ideas for later
- **Reorder** — hold the ⠇ icon to drag an item to a different position
- **New week** — tap ⚙️ → Start new week to clear the shopping list (meals are kept)

---

### Loyalty cards

1. Open ⚙️ Settings → Loyalty cards → **＋ Add card**
2. Scan the barcode or QR code using the camera, or enter the number manually
3. Choose the type (EAN-13, Code 128 or QR code) and a colour
4. Tap a card to display it full screen at the checkout

---

### Setting up push notifications

1. Open ⚙️ Settings → Notifications
2. Tap **🔔 Enable notifications**
3. Grant permission when your browser/phone asks

You will receive a notification when a family member has updated the list (with a 2-minute delay).

---

### Forgot password

**If you have an email address (admin):**
1. Tap **Forgot password?** on the login screen
2. Choose the **Admin** tab and enter your email address
3. You will receive a recovery code by email

**If you don't have an email address (family member):**
1. Tap **Forgot password?** on the login screen
2. Choose the **Family member** tab and enter your name and family code
3. The admin receives an email with a reset link
4. The admin forwards that link to you
5. Open the link and set a new password

---

## Admin guide

As an admin you have access to additional features in ⚙️ Settings.

### Managing family members

- **Invite** — tap the **Invite** button next to the family code to generate a shareable link
- **👑 Make admin** — tap the gold crown next to a member to make them an admin too
- **✕ Remove** — tap the red ✕ to permanently remove a member

### Setting the week start day

In ⚙️ Settings → Week → **Week starts on** you can choose which day your week starts on. The other days are filled in automatically.

### Cancelling your account

1. Open ⚙️ Settings → Delete account
2. Check the confirmation box
3. Tap **Send request**

The app administrator will receive an email and will delete the family and all data.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Connection error when logging in | Check `DB_*` values in `config.php` |
| Emails not arriving | Check `SMTP_*` values and spam folder |
| App not loading | Check that `.htaccess` has been uploaded correctly |
| Push notifications not working | App must be opened via the home screen icon (not as a browser tab) |
| Barcode not working in store | Delete the card and add it again |
| Cache issues after update | Purge the Cloudflare cache or clear the browser cache |

---

## Security

- All passwords are stored using bcrypt hashing
- Sessions are secured with HMAC-signed cookies
- All forms have bot protection
- HTTPS is required (configured via `.htaccess`)
- Sensitive PHP files are blocked via `.htaccess`

---

## Technical information

| Component | Technology |
|-----------|------------|
| Frontend | HTML, CSS, Vanilla JavaScript |
| Backend | PHP 8.0+ |
| Database | MySQL / MariaDB |
| Email | PHPMailer via SMTP |
| Barcodes | JsBarcode + ZXing + QRCode.js |
| Push | Web Push API + VAPID |
| Hosting | Any Apache web host with PHP and MySQL |

---

*Weeklyst was built in collaboration with Claude.ai — https://claude.ai*
