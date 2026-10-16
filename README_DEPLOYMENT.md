# Green AVIC Newsletter System — Hostinger Deployment Guide

This document provides complete, step-by-step instructions for deploying and testing the **Green AVIC News & Insights CMS and Direct Email Newsletter System** on **Hostinger Single Web Hosting** (`PHP 8.3`, `MariaDB`, manual upload via hPanel File Manager).

---

## 1. Architecture & Subdomain Layout

The deployment package is structured for Hostinger's standard `public_html` directory:

```
public_html/
├── (Main website: https://greenavic.org is preserved untouched)
│
├── newsletter/                 <-- Document Root for https://newsletter.greenavic.org
│   ├── index.html              <-- Public News & Insights Reader + Direct Email Subscription
│   ├── unsubscribe.php         <-- 1-Click Secure Unsubscribe (RFC 8058 compliant)
│   ├── api/
│   │   ├── article.php         <-- Public Published Article API
│   │   └── subscribe.php       <-- Direct Single Opt-In API (Anti-Spam Honeypot + Instant Activation)
│   ├── assets/                 <-- CSS, JS, brand logos, icons
│   └── uploads/                <-- Article Cover Images (.htaccess protected)
│
├── admin/                      <-- Document Root for https://admin.greenavic.org
│   ├── index.html              <-- Content Studio, Publication Editor & Subscriber Management
│   ├── login.html              <-- Secure Admin Login
│   ├── setup.php               <-- Browser-Accessible Database Setup & Migration Assistant
│   ├── invite.php              <-- Client & Co-Admin Onboarding Link Generator
│   ├── admin.css / admin.js    <-- Studio UI Logic & Broadcast Queue Controls
│   └── api/
│       ├── article.php         <-- Publication Engine & Campaign Broadcast Trigger
│       ├── auth.php            <-- Admin Session, Auth & Multi-Admin Roster
│       ├── cron.php            <-- Hostinger Background Delivery Cron Endpoint
│       ├── subscribers.php     <-- Audience Management & Batch Process API
│       └── upload.php          <-- Secure Cover Image Upload Handler
│
└── cms-core/                   <-- Private Backend Core (Direct web access blocked by .htaccess)
    ├── .env                    <-- Active Production Credentials (Pre-configured)
    ├── .env.example            <-- Configuration Template
    ├── config.php              <-- Configuration Loader & Path Resolvers
    ├── db.php                  <-- Database Connection & Auto-Migrator
    ├── common.php              <-- CSRF, Rate Limiting & Session Security
    ├── mailer.php              <-- PHPMailer SMTP Client (Hostinger Port 465 SSL)
    ├── newsletter_service.php  <-- Responsive HTML Email Renderer & 100/24h Quota Queue
    └── PHPMailer/              <-- Bundled PHPMailer v6.9.3 (PHP 8.3 compatible)
```

---

## 2. Fast Deployment Steps (3 to 5 Minutes)

### Step 1: Upload and Extract ZIP
1. Log in to [Hostinger hPanel](https://hpanel.hostinger.com/).
2. Navigate to **Files** ➔ **File Manager** (open `public_html`).
3. Upload `greenavic-newsletter-deployment.zip` into `public_html`.
4. Right-click the uploaded ZIP file and select **Extract**.
5. Set the extraction path to `.` (`public_html`).
6. Verify that `newsletter/`, `admin/`, and `cms-core/` folders are present in `public_html`.
7. You may delete `greenavic-newsletter-deployment.zip` after extraction.

### Step 2: Run the Browser Database Installer
Instead of manually importing SQL scripts through phpMyAdmin, use the automated setup assistant:

1. In your browser, open:
   ```
   https://admin.greenavic.org/setup
   ```
   *(Or `https://admin.greenavic.org/setup.php`)*
2. When prompted by the **Installer Security Gate**, enter your private Setup Token:
   ```
   greenavic_setup_9a8b7c6d5e4f
   ```
   *(Or log in first if an administrator account already exists).*
3. The installer automatically:
   - Connects to your MariaDB database using `cms-core/.env`.
   - Preserves all existing administrator accounts, passwords, roles (Administrator, Moderator, Editor), and permissions.
   - Preserves all published articles, working drafts, and media.
   - Normalizes all article cover image paths to valid public URLs (`https://newsletter.greenavic.org/uploads/covers/...`).
   - Migrates the subscriber audience to **Direct Single Opt-In** and activates any legacy pending subscribers.
   - Creates `articles`, `drafts`, `subscribers`, `newsletter_campaigns`, `newsletter_deliveries`, `system_logs`, and `system_settings` tables if missing.
   - Idempotently verifies and adds missing columns (`name`, `role`, `status`) to the `users` table without dropping accounts.
   - Provides a direct token unlock form on locked screens so you can safely rerun migrations at any time without SSH.
4. You will see success badges (`[OK]`) for all database components.

---

### Step 3: Verify & Repair via Admin Dashboard (Optional)
If you ever need to inspect health or re-verify database integrity after upload:
1. Log in to `https://admin.greenavic.org/login`.
2. Click **"System Health"** in the top navigation bar.
3. Inspect live MariaDB connection latency, Hostinger SMTP gateway handshake, and recent audit logs.
4. Click **"Verify & Repair Database"** to automatically check table schemas and re-normalize publication images.

---

## 3. Direct Single Opt-In Subscription Flow

The subscription flow is now instantaneous and friction-free:
1. Visitors browse `https://newsletter.greenavic.org`.
2. In the **"Join the Green AVIC Community"** section:
   - Visitor enters their email address.
   - Checks the consent box (*"I agree to receive Green AVIC News & Insights updates by email..."*).
   - Clicks **Subscribe**.
3. The server immediately registers the subscriber with `status = 'active'`.
4. The user sees an instant confirmation message on screen (*"Thank you for subscribing! You are now subscribed to Green AVIC News & Insights."*).
5. **Zero confirmation links or token verification emails are sent.** The subscriber is immediately ready to receive broadcasts.
6. **Duplicate handling:** If an already active subscriber enters their email again, the system politely confirms they are subscribed without duplicate records or errors.
7. **Resubscribe handling:** If a previously unsubscribed user resubscribes, their status is set back to `active`, consent timestamp is refreshed, and a new secure unsubscribe token is generated.

---

## 4. Automatic Newsletter Delivery on Publication

When an administrator clicks **"Publish Article"** in `https://admin.greenavic.org`:
1. The article becomes the active live article on `https://newsletter.greenavic.org`.
2. An automated broadcast campaign is generated.
3. Every active subscriber receives a branded HTML newsletter email containing:
   - Green AVIC logo and branding.
   - Article title, category, reading time, and date.
   - Cover photo.
   - Executive excerpt and introduction.
   - Direct button linking to read the full article on `https://newsletter.greenavic.org`.
   - Single-click unsubscribe link at the bottom.
4. If a publish action is accidentally clicked multiple times, built-in debounce guards prevent duplicate campaigns.

---

## 5. Hostinger 24-Hour SMTP Limit & Background Cron

Hostinger Single Web Hosting enforces a rolling limit of **100 emails per 24 hours**.
The Green AVIC queue handles this automatically:

1. In Hostinger hPanel, go to **Advanced** ➔ **Cron Jobs**.
2. Select **Hourly** (`0 * * * *`).
3. Set the command to:
   ```bash
   wget -q -O - "https://admin.greenavic.org/api/cron.php?secret=greenavic_cron_sec_8f7b2a9e3d4c1" >/dev/null 2>&1
   ```
4. Click **Save**.

If your subscriber count exceeds the remaining 24-hour quota, the remaining emails wait in `newsletter_deliveries` and are automatically dispatched as quota resets. Administrators can also click **"Process Next Batch Now"** inside the dashboard.

---

## 6. Client Onboarding (Two Independent Admins)

To give your client her own administrator account without sharing passwords:
1. Sign in to `https://admin.greenavic.org/login`.
2. Click **"Team & Administrators"** in the top navigation.
3. Under **"Invite Co-Admin or Client"**, click **"Generate Link"**.
4. Copy the link (`https://admin.greenavic.org/invite?token=...`) and send it to your client.
5. She can open the link in her browser, enter her own email, and set her private permanent password.

---

## 7. Verification Checklist

- [ ] **Run Web Installer:** Open `https://admin.greenavic.org/setup`, enter `greenavic_setup_9a8b7c6d5e4f`, and verify green checkmarks.
- [ ] **Admin Login:** Open `https://admin.greenavic.org/login` and log in. Verify the admin count badge is showing correctly.
- [ ] **Test Direct Subscription:**
  1. Open `https://newsletter.greenavic.org` in an incognito window.
  2. Enter a test email and click **Subscribe**.
  3. Verify the instant on-screen success message.
  4. In Admin Dashboard ➔ **Subscribers**, verify the email appears in the list with status **Active**.
- [ ] **Test SMTP:** In Admin Dashboard ➔ **Subscribers**, click **"Send Test Email"** to verify mailbox credentials.
- [ ] **Test Publication:** Publish an article and verify your test email receives the branded newsletter broadcast.
- [ ] **Test Unsubscribe:** Click the unsubscribe link in the received email and confirm your address is marked unsubscribed.
