# Green AVIC News & Insights CMS — Hostinger Manual Deployment Guide

This guide details the complete, step-by-step process for deploying the Green AVIC CMS to **Hostinger Single Web Hosting** using **hPanel File Manager** and **phpMyAdmin** (No SSH, Node.js, or command-line required).

---

## Architecture Overview

```
public_html/
├── (Existing website: greenavic.org preserved)
├── newsletter/                 <-- Document Root for https://newsletter.greenavic.org
│   ├── index.html              <-- Public News & Insights Reader
│   ├── api/article.php         <-- Public Article API (MariaDB)
│   ├── assets/                 <-- Styles, scripts, brand images
│   └── uploads/                <-- Writable Cover Image Directory (Script execution disabled)
├── admin/                      <-- Document Root for https://admin.greenavic.org
│   ├── index.html              <-- Content Studio Dashboard & Single-Article Editor
│   ├── login.html              <-- Admin Login
│   ├── setup.php               <-- Secure One-Time Admin Installer (Self-locking)
│   ├── api/                    <-- Admin Auth, Article, and Upload Endpoints
│   └── admin.css / admin.js
└── cms-core/                   <-- Shared Core Backend (Direct web access strictly blocked)
    ├── config.php              <-- Your Hostinger MariaDB Credentials
    ├── db.php                  <-- PDO Database Connection Handler
    └── common.php              <-- CSRF, Rate Limiting & Session Security
```

---

## Step 1: Create Your MariaDB Database in Hostinger

1. Log in to [Hostinger hPanel](https://hpanel.hostinger.com/).
2. Select your hosting plan and click **Manage** next to `greenavic.org`.
3. In the left sidebar, navigate to **Databases** ➔ **Management**.
4. Under **Create a New MySQL Database And User**:
   - **Database Name**: Enter a name (e.g., `greenavic_cms` — Hostinger prefixes this, e.g., `u123456789_greenavic_cms`).
   - **Username**: Enter a username (e.g., `admin`).
   - **Password**: Enter a strong password (or generate one).
5. Click **Create**.
6. **Important**: Note down the full Database Name, Username, and Password.

---

## Step 2: Import `schema.sql` via phpMyAdmin

1. On the same **Databases** page in hPanel, scroll down to **List of Current MySQL Databases And Users**.
2. Click **Enter phpMyAdmin** next to your newly created database.
3. In phpMyAdmin, click the **Import** tab in the top navigation bar.
4. Click **Choose File** (or "Browse") and select `schema.sql` from this package.
5. Scroll to the bottom and click **Import** (or **Go**).
6. Verify that the 4 tables are created:
   - `users`
   - `articles`
   - `drafts`
   - `login_attempts`

---

## Step 3: Upload Files via Hostinger File Manager

You can upload either the **All-In-One ZIP** or the **Individual ZIPs**:

### Option A: All-in-One Upload (Recommended)
1. In hPanel, go to **Files** ➔ **File Manager**.
2. Select **Access files of greenavic.org**.
3. Open the `public_html` directory.
4. Click the **Upload** icon (arrow pointing up) at the top right, and upload:
   - `greenavic-cms-hpanel-all-in-one.zip`
5. Right-click the uploaded ZIP file in File Manager and select **Extract**.
6. Choose `.` (current folder `public_html`) as the extraction destination.
7. This automatically places:
   - `newsletter/` into `public_html/newsletter/` (replacing Hostinger's default page)
   - `admin/` into `public_html/admin/` (replacing Hostinger's default page)
   - `cms-core/` into `public_html/cms-core/`
8. You can delete the ZIP file after extraction.

---

## Step 4: Configure Database Credentials in `cms-core/config.php`

1. In Hostinger File Manager, open the `public_html/cms-core/` folder.
2. Right-click `config.php` and click **Edit**.
3. Update the three database constants with your Hostinger database details:
   ```php
   define('DB_HOST', 'localhost');                  // Keep 'localhost'
   define('DB_NAME', 'u123456789_greenavic_cms');   // Your full Database Name
   define('DB_USER', 'u123456789_admin');           // Your full Database User
   define('DB_PASS', 'YOUR_ACTUAL_PASSWORD_HERE');  // Your Database Password
   ```
4. Click **Save** in the top right of File Manager.

> **Security Note:** `cms-core/.htaccess` has `Require all denied`, which prevents visitors from ever viewing `config.php` in a web browser.

---

## Step 5: Initialize the Administrator Account

1. Open your web browser and navigate to:
   ```
   https://admin.greenavic.org/setup.php
   ```
2. The form will display:
   - **Administrator Email**: Pre-filled with `japhetjohnk@gmail.com`
   - **Initial Password**: Enter the initial password you provided (`GreenAvic!2026`)
   - **Confirm Initial Password**: Re-enter the initial password
3. Click **Complete Setup**.
4. The script will securely hash the password with bcrypt into MariaDB and create `setup.lock`.
5. Once complete, `setup.php` is **permanently disabled and locked** against further execution.

---

## Step 6: First Sign In & Mandatory Password Change

1. Navigate to:
   ```
   https://admin.greenavic.org/login.html
   ```
2. Enter:
   - **Email**: `japhetjohnk@gmail.com`
   - **Password**: Enter your initial password (`GreenAvic!2026`)
3. Click **Sign In**.
4. Because this is the first login, the **Password Change Required** modal will automatically appear:
   - Enter your **Current Initial Password**.
   - Enter your **New Permanent Password** (minimum 8 characters).
   - Confirm your **New Permanent Password**.
5. Click **Set New Password & Enter Studio**.
6. Your permanent password is now hashed into MariaDB, and you are logged into the Content Studio!

---

## Step 7: Verification & Testing Checklist

### 1. Public Newsletter Check (`https://newsletter.greenavic.org`)
- [ ] Visit `https://newsletter.greenavic.org`.
- [ ] Initially displays the clean, authentic Green AVIC empty state.
- [ ] Header, logo, navigation links, and footer render properly.

### 2. Admin Content Studio Check (`https://admin.greenavic.org`)
- [ ] Direct access to `https://admin.greenavic.org` requires authentication (redirects to login).
- [ ] Log in with `japhetjohnk@gmail.com` and your permanent password.
- [ ] Click **Write New Post**.
- [ ] Fill in **Headline**, **Excerpt**, **Category**, and **Body Content**.
- [ ] **Image Upload**: Drag & drop or browse a cover image (JPG, PNG, or WebP). Confirm that the image preview appears with a green success indicator.
- [ ] Enter **Cover Image Alt Text**.

### 3. Draft vs. Live Publishing Test
- [ ] Click **Save Draft**: A success toast appears.
- [ ] Open `https://newsletter.greenavic.org` in an incognito window: Verify that the draft is **NOT** visible publicly.
- [ ] Back in Content Studio, click **Publish Article**.
- [ ] Confirm in the replacement dialog.
- [ ] Refresh `https://newsletter.greenavic.org`: Verify that the published article is now **LIVE**, displaying the headline, cover image, category badge, read time, and full reader view.

### 4. Article Replacement Test
- [ ] In Content Studio, edit or create a replacement article.
- [ ] Click **Publish Article**: The confirmation modal confirms replacement.
- [ ] Verify that `https://newsletter.greenavic.org` immediately displays the replacement without caching or broken layouts.

### 5. Main Site Integration Check (`https://greenavic.org`)
- [ ] Visit `https://greenavic.org`.
- [ ] Scroll to the footer under **Resources** and click **Newsletter**: Confirms that it opens `https://newsletter.greenavic.org`.

---

## Rollback Procedure (If Needed)

If you ever need to restore previous states:
1. **Database Rollback**: In phpMyAdmin, you can re-import `schema.sql` to reset tables to a clean state.
2. **Files Rollback**: The main website in `public_html/` is independent. Deleting `public_html/newsletter` and `public_html/admin` removes the CMS without affecting `greenavic.org`.
