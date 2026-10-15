# Green AVIC News & Insights CMS — Hostinger Deployment & Email Newsletter Guide

This guide details the complete process for deploying the Green AVIC CMS to **Hostinger Single Web Hosting** with **MariaDB**, **PHP 8.3**, and **Hostinger SMTP Email Newsletter broadcasts**.

---

## Architecture Overview

```
public_html/
├── (Existing website: greenavic.org preserved)
├── newsletter/                 <-- Document Root for https://newsletter.greenavic.org
│   ├── index.html              <-- Public News & Insights Reader + Email Subscription Box
│   ├── unsubscribe.php         <-- 1-Click Secure Unsubscribe Page (RFC 8058 compliant)
│   ├── api/
│   │   ├── article.php         <-- Public Article API
│   │   └── subscribe.php       <-- Public Email Subscription API (Honeypot + Anti-Spam)
│   ├── assets/                 <-- Styles, scripts, brand images
│   └── uploads/                <-- Writable Cover Image Directory (Script execution disabled)
├── admin/                      <-- Document Root for https://admin.greenavic.org
│   ├── index.html              <-- Content Studio, Publication Editor & Subscriber Management
│   ├── login.html              <-- Admin Login
│   ├── setup.php               <-- Secure One-Time Admin Setup (Self-locking)
│   ├── invite.php              <-- Co-Admin / Client Onboarding Link
│   ├── api/                    <-- Admin Auth, Article, Uploads, and Subscribers APIs
│   └── admin.css / admin.js
└── cms-core/                   <-- Shared Core Backend (Direct web access strictly blocked)
    ├── .env                    <-- MariaDB & Hostinger SMTP Credentials
    ├── config.php              <-- System Configuration & Fallbacks
    ├── db.php                  <-- PDO Database Connection Handler & Auto-Migration
    ├── common.php              <-- CSRF, Rate Limiting & Session Security
    ├── mailer.php              <-- Zero-Dependency Native PHP 8.3 SSL/TLS Socket SMTP Client
    └── newsletter_service.php  <-- Responsive HTML Email Renderer & Campaign Dispatcher
```

---

## Step 1: Create Hostinger Email Account & Obtain SMTP Credentials

To allow Green AVIC to automatically email published newsletters to your subscribers:

1. Log in to [Hostinger hPanel](https://hpanel.hostinger.com/).
2. In the top navigation or sidebar, go to **Emails** ➔ click **Manage** next to `greenavic.org`.
3. Click **Create Email Account**:
   - **Email Address**: `newsletter@greenavic.org` (or your preferred address).
   - **Password**: Create a strong password (save this for `.env`).
4. Click **Create**.
5. Note your Hostinger SMTP details:
   - **SMTP Host**: `smtp.hostinger.com`
   - **SMTP Port**: `465` (SSL) or `587` (TLS)
   - **SMTP Username**: `newsletter@greenavic.org`
   - **SMTP Password**: The password you just created.

---

## Step 2: Configure Hostinger DNS Records (SPF, DKIM, DMARC)

Proper email authentication prevents your emails from landing in spam/junk folders.

1. In hPanel, navigate to **Advanced** ➔ **DNS Zone Editor** (or **Emails** ➔ **Email Settings** ➔ **DNS Records**).
2. Ensure the following DNS records are present:
   - **SPF Record (TXT)**:
     - **Name / Host**: `@` (or leave blank depending on interface)
     - **Type**: `TXT`
     - **Value**: `v=spf1 include:_spf.mail.hostinger.com ~all`
     - **TTL**: `14400`
   - **DKIM Record (TXT / CNAME)**:
     - In Hostinger Email Settings, Hostinger will automatically generate the DKIM record (e.g. `hostingermail._domainkey`). Click **Enable / Verify DKIM** if prompted.
   - **DMARC Record (TXT)**:
     - **Name / Host**: `_dmarc`
     - **Type**: `TXT`
     - **Value**: `v=DMARC1; p=none; sp=none; rua=mailto:newsletter@greenavic.org`
     - **TTL**: `14400`

---

## Step 3: Create Your MariaDB Database in Hostinger

1. In hPanel, navigate to **Databases** ➔ **Management**.
2. Under **Create a New MySQL Database And User**:
   - **Database Name**: Enter a name (e.g., `greenavic_cms` — Hostinger prefixes this, e.g., `u123456789_greenavic_cms`).
   - **Username**: Enter a username (e.g., `admin`).
   - **Password**: Enter a strong password.
3. Click **Create**.
4. Note the full Database Name, Username, and Password.

---

## Step 4: Import `schema.sql` via phpMyAdmin

1. In hPanel Databases, click **Enter phpMyAdmin** next to your database.
2. Click the **Import** tab at the top.
3. Choose `schema.sql` from this package and click **Go / Import**.
4. The following 7 tables will be created:
   - `users`: Admin credentials
   - `articles`: Current live publication
   - `drafts`: Working draft
   - `login_attempts`: Brute-force protection
   - `subscribers`: Single opt-in email audience & unique 1-click unsubscribe tokens
   - `newsletter_campaigns`: Broadcast history and delivery metrics
   - `newsletter_deliveries`: Per-subscriber logs with duplicate prevention

*(Note: If you have already imported the database previously, the CMS auto-migrator in `cms-core/db.php` will automatically create the subscriber tables for you).*

---

## Step 5: Upload Files via Hostinger File Manager

1. In hPanel, go to **Files** ➔ **File Manager**.
2. Open the `public_html` directory.
3. Upload `greenavic-production.zip`.
4. Right-click `greenavic-production.zip` and select **Extract**.
5. Choose `.` (current folder `public_html`) as the destination.
6. This extracts:
   - `newsletter/` ➔ `public_html/newsletter/`
   - `admin/` ➔ `public_html/admin/`
   - `cms-core/` ➔ `public_html/cms-core/`
7. Delete the ZIP file after extraction.

---

## Step 6: Configure `.env` with Database & SMTP Credentials

1. In File Manager, open `public_html/cms-core/`.
2. Locate `.env` (or duplicate `.env.example` to `.env`).
3. Right-click `.env` and click **Edit**:
   ```ini
   # Database Credentials
   DB_HOST=localhost
   DB_NAME=u123456789_greenavic_cms
   DB_USER=u123456789_admin
   DB_PASS=YOUR_DATABASE_PASSWORD_HERE
   DB_PORT=3306

   # Security Token
   SESSION_SECRET=YOUR_64_CHAR_HEX_TOKEN

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
4. Click **Save**.

---

## Step 7: Initialize Administrator Account & Log In

1. Open your browser and go to:
   ```
   https://admin.greenavic.org/setup.php
   ```
2. Enter your email (`japhetjohnk@gmail.com`) and initial password (`GreenAvic!2026`).
3. Click **Complete Setup** (creates admin account and permanently locks `setup.php`).
4. Go to `https://admin.greenavic.org/login.html` and sign in.
5. On first login, change your temporary password to your private permanent password.

---

## Step 8: Verifying the Email Newsletter System

### 1. Test SMTP Connection
1. In the top bar of Content Studio (`https://admin.greenavic.org`), click **Subscribers & Broadcasts**.
2. Under "SMTP Status", verify it displays **Connected**.
3. Click **Test SMTP**, enter your personal email address, and click OK.
4. Check your inbox to confirm you received the test email.

### 2. Test Public Subscription
1. Visit `https://newsletter.greenavic.org`.
2. Scroll down to the **Join the Green AVIC Community** subscription section.
3. Enter an email address and click **Subscribe**.
4. Confirm the success message displays.
5. In Content Studio ➔ **Subscribers & Broadcasts**, verify that the email now appears as an **ACTIVE** subscriber.

### 3. Test Automatic Email Broadcast on Publish
1. In Content Studio, click **Write New Post** (or edit current).
2. Enter a headline, excerpt, and body content (add a cover photo if desired).
3. Click **Publish Article** ➔ confirm **Yes, Publish Now**.
4. The system will atomically publish the article to `newsletter.greenavic.org` and automatically dispatch the full article newsletter to all active subscribers.
5. A confirmation toast will report: `"Broadcast sent: X delivered (0 failed) to X subscribers."`
6. Check your subscriber inbox:
   - Notice the Green AVIC emerald header and logo.
   - Headline, author byline, date, cover photo, and full rich text.
   - "Read Online on Green AVIC" button.
   - 1-click **Unsubscribe** link at the bottom.

### 4. Test 1-Click Unsubscribe
1. Click the **Unsubscribe with one click** link at the bottom of the received newsletter email.
2. Confirm it opens `https://newsletter.greenavic.org/unsubscribe?token=...` and displays the clean confirmation page: *"You have been unsubscribed. [email] will no longer receive newsletter broadcasts from Green AVIC."*
3. In Content Studio ➔ **Subscribers & Broadcasts**, verify that the subscriber status has changed to **UNSUBSCRIBED**. Future publications will not email this address.
4. You can also reactivate or remove any subscriber directly from the Content Studio.

---

## Co-Admin & Client Access

To give your client or team members access without sharing passwords:
1. In Content Studio, click **Team & Admins** in the top navigation.
2. Under **Invite Co-Admin or Client**, click **Generate Link**.
3. Copy the secure invite link and send it to your client.
4. She can open the link in her browser and set her own email address and private password.
