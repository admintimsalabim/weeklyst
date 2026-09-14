<?php
/**
 * Weeklyst — config.php
 * Fill in all values below for your installation.
 * NEVER store this file in a public Git repository.
 */

// ── Database ──────────────────────────────────────────────
define('DB_HOST', 'localhost');             // Almost always 'localhost'
define('DB_NAME', 'YOUR_DATABASE_NAME');   // ← change this
define('DB_USER', 'YOUR_DATABASE_USER');   // ← change this
define('DB_PASS', 'YOUR_DATABASE_PASS');   // ← change this

// ── Email (SMTP) ──────────────────────────────────────────
define('SMTP_HOST',      'mail.yourprovider.com');  // ← SMTP server of your hosting
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@yourdomain.com'); // ← sender email address
define('SMTP_PASS',      'YOUR_EMAIL_PASS');         // ← email password
define('SMTP_FROM',      'noreply@yourdomain.com'); // ← same as SMTP_USER
define('SMTP_FROM_NAME', 'Weeklyst');

// ── App URL ───────────────────────────────────────────────
define('APP_URL',        'https://yourdomain.com');  // ← your domain (no trailing slash)
define('APP_NAME',       'Weeklyst');
define('ALLOWED_ORIGIN', 'https://yourdomain.com');  // ← same as APP_URL

// ── Session security ──────────────────────────────────────
// Generate a random secret using: openssl rand -hex 32
define('COOKIE_SECRET', 'CHANGE_THIS_TO_A_RANDOM_STRING_OF_AT_LEAST_32_CHARACTERS');
define('COOKIE_NAME',   'wl_sess');
define('COOKIE_DAYS',   30);
define('SESSION_DIR',   __DIR__ . '/.sessions');

// ── Admin password ────────────────────────────────────────
// This password is used to log in to admin.html and invite_admin.html
define('INVITE_PASSWORD', 'CHOOSE_A_STRONG_ADMIN_PASSWORD');
