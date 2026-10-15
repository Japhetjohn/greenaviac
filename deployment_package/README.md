# Green AVIC Newsletter System — Hostinger Deployment Guide

This document provides complete, beginner-friendly instructions for deploying the **Green AVIC News & Insights CMS and Email Newsletter System** to **Hostinger Single Web Hosting** (`PHP 8.3`, `MariaDB`, manual upload via hPanel File Manager & phpMyAdmin).

---

## 1. Hosting Environment & Architecture

Hostinger Single Web Hosting does not provide SSH or Node.js. All files are pre-compiled and bundled with official **PHPMailer v6.9.3** (zero Composer needed on the server).

### Subdomain Document Roots in Hostinger `public_html`:

```
public_html/
├── (Existing website: https://greenavic.org is preserved untouched)
│
├── newsletter/                 <-- Document Root for https://newsletter.greenavic.org
│   ├── index.html              <-- Public News & Insights Reader + Email Subscription Box
│   ├── confirm.php             <-- Double Opt-In Verification Page (Activates subscriber)
│   ├── unsubscribe.php         <-- 1-Click Secure Unsubscribe Page (RFC 8058 compliant)
│   ├── api/
│   │   ├── article.php         <-- Public Published Article API
│   │   └── subscribe.php       <-- Public Subscription API (Honeypot + Anti-Spam + Double Opt-In)
│   ├── assets/                 <-- CSS, JS, brand logos, icons
│   └── uploads/                <-- Article Cover Images (Script execution blocked by .htaccess)
│
├── admin/                      <-- Document Root for https://admin.greenavic.org
│   ├── index.html              <-- Content Studio, Publication Editor & Subscriber Management
│   ├── login.html              <-- Admin Login
│   ├── setup.php               <-- Secure One-Time Initial Administrator Setup (Self-locking)
│   ├── invite.php              <-- Co-Admin / Client Onboarding Link
│   ├── admin.css / admin.js    <-- Studio UI Logic & Queue Controls
│   └── api/
│       ├── article.php         <-- Publication Engine & Campaign Dispatcher
│       ├── auth.php            <-- Admin Session & Authentication
│       ├── cron.php            <-- Background Delivery Cron Endpoint
│       ├── subscribers.php     <-- Audience Management & Batch Process API
│       └── upload.php          <-- Secure Image Upload Handler (GD / Imagick)
│
└── cms-core/                   <-- Shared Backend (Direct web access blocked by .htaccess)
    ├── .env.example            <-- Configuration Template (Rename to .env)
    ├── config.php              <-- Configuration Loader & Path Resolvers
    ├── db.php                  <-- Database Connection & Auto-Migrator
    ├── common.php              <-- CSRF, Rate Limiting & Session Security
    ├── mailer.php              <-- PHPMailer SMTP Client (Hostinger SSL 465)
    ├── newsletter_service.php  <-- Responsive Email Renderer, 100/24h Quota & Delivery Queue
    └── PHPMailer/              <-- Bundled PHPMailer v6.9.3 (PHP 8.3 compatible)
```

---

## 2. Pre-Deployment: Mailbox & SMTP Settings

Hostinger Single Web Hosting includes webmail. Create the sender mailbox before sending:

1. Log in to [Hostinger hPanel](https://hpanel.hostinger.com/).
2. Navigate to **Emails** ➔ click **Manage** next to `greenavic.org`.
3. Click **Create Email Account**:
   - **Email Address**: `newsletter@greenavic.org`
   - **Password**: Set your mailbox password.
4. Note your Hostinger SMTP credentials:
   - **SMTP Host**: `smtp.hostinger.com`
   - **SMTP Port**: `465`
   - **Encryption**: `SSL`
   - **SMTP Username**: `newsletter@greenavic.org`
   - **SMTP Password**: Your mailbox password.

> **DNS Records (SPF & DKIM):** In hPanel ➔ **Emails** ➔ **Email Settings**, verify that SPF and DKIM are enabled. This ensures your newsletter emails land directly in subscriber inboxes instead of spam.

---

## 3. Database Setup via phpMyAdmin

1. In hPanel, go to **Databases** ➔ **Management** and create a MySQL/MariaDB database (or use your existing one).
2. Click **Enter phpMyAdmin** next to your database.
3. Import the database schema:
   - **If you already have articles and users in your database:** Click the **Import** tab, choose `migration.sql`, and click **Go**. This upgrades the schema with double opt-in subscriber tables, delivery queue, and Hostinger 24h quota tracking without touching existing articles.
   - **If this is a completely fresh installation:** Click the **Import** tab, choose `schema.sql`, and click **Go**.

*(Note: Even if you do not run the SQL script manually, `cms-core/db.php` includes an automatic schema migrator that will create and alter the necessary tables on first request).*

---

## 4. Upload Files via Hostinger File Manager

1. In hPanel, go to **Files** ➔ **File Manager** (access `public_html`).
2. Upload `greenavic-newsletter-deployment.zip` to `public_html`.
3. Right-click `greenavic-newsletter-deployment.zip` and select **Extract**.
4. Set the destination to `.` (`public_html`).
5. Ensure the extracted folders match the structure:
   - `public_html/newsletter/`
   - `public_html/admin/`
   - `public_html/cms-core/`
6. Delete `greenavic-newsletter-deployment.zip` after extraction.

---

## 5. Configure `.env` Credentials

1. In File Manager, navigate to `public_html/cms-core/`.
2. Find `.env.example`.
3. Rename or copy `.env.example` to `.env`.
4. Right-click `.env` and click **Edit**:
   ```ini
   # Database Credentials (from Hostinger hPanel -> Databases)
   DB_HOST=localhost
   DB_NAME=u773570837_greenavic_cms
   DB_USER=u773570837_admin
   DB_PASS=YOUR_DATABASE_PASSWORD
   DB_PORT=3306

   # Security Token (Any random 32-64 character string)
   SESSION_SECRET=d89b37190e2f5b6697843232145eefab7c81a952d7e008fa9131d8bf62ca1234
   SESSION_LIFETIME=86400

   # Subdomain URLs
   NEWSLETTER_URL=https://newsletter.greenavic.org
   ADMIN_URL=https://admin.greenavic.org
   MAIN_SITE_URL=https://greenavic.org

   # Hostinger SMTP Configuration
   SMTP_HOST=smtp.hostinger.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=newsletter@greenavic.org
   SMTP_PASS=YOUR_HOSTINGER_EMAIL_PASSWORD
   SMTP_FROM_EMAIL=newsletter@greenavic.org
   SMTP_FROM_NAME="Green AVIC"
   ```
5. Click **Save & Close**.

> **Security Note:** Direct web requests to `public_html/cms-core/` are blocked by `.htaccess` (`Require all denied`). Your database and SMTP credentials cannot be downloaded or viewed via a browser.

---

## 6. Setup Hostinger Background Cron Job

Hostinger Single Web Hosting limits SMTP sending to **100 emails per rolling 24 hours**. 
When you publish an article, the system immediately sends to available quota and queues the rest in `newsletter_deliveries`. 

To automatically process remaining deliveries across 24-hour cycles:

1. In hPanel, navigate to **Advanced** ➔ **Cron Jobs**.
2. Select **Hourly** (`0 * * * *`).
3. Enter the command:
   ```bash
   wget -q -O - "https://admin.greenavic.org/api/cron.php?secret=greenavic_cron_sec_8f7b2a9e3d4c1" >/dev/null 2>&1
   ```
4. Click **Save**.

*(Note: You can also click **"Process Next Batch"** inside Admin Studio at any time to process pending deliveries manually).*

---

## 7. Administrator Login & Client Onboarding

1. Visit `https://admin.greenavic.org/login`.
2. Log in with your administrator email and initial password.
3. If this is the initial login, the system will prompt you to change your temporary password to a permanent one.
4. **Inviting Your Client or Co-Admin:**
   - In Admin Studio, click **"Team & Administrators"** (or click the team icon in the top header).
   - Under **Invite Co-Admin or Client**, click **"Generate Link"**.
   - Copy the link and send it to your client. She can set her own email and private password to access the studio independently.

---

## 8. End-to-End Verification Checklist

Perform these quick tests to confirm your system is running in production:

- [ ] **Test SMTP:** In Admin Studio, click **"Subscribers"** ➔ click **"Send Test Email"**. Enter your email and verify that you receive the test message.
- [ ] **Test Subscription & Double Opt-In:**
  1. Open `https://newsletter.greenavic.org` in an incognito window.
  2. Enter an email in the "Subscribe to Green AVIC Updates" section and submit.
  3. Check your inbox for the **"Confirm your Green AVIC subscription"** email.
  4. Click the confirmation link. You should see the Green AVIC **"Subscription Confirmed"** page.
- [ ] **Verify in Admin Studio:**
  - In Admin Studio ➔ **Subscribers**, verify that the subscriber moves from "Awaiting Confirmation" to "Confirmed Active".
- [ ] **Test Publishing & Broadcast:**
  1. In Admin Studio, create or edit an article and click **"Publish Article"**.
  2. Confirm publication.
  3. The public website at `https://newsletter.greenavic.org` will update immediately.
  4. All confirmed subscribers will automatically receive the formatted newsletter email with article title, excerpt, and link to the full article.
- [ ] **Test 1-Click Unsubscribe:**
  - Open the received newsletter email, scroll to the bottom, and click **"Unsubscribe"**.
  - You will see the confirmation page confirming immediate suppression.
  - In Admin Studio ➔ **Subscribers**, the status updates to "Unsubscribed" and no further broadcasts will be sent to that address.
