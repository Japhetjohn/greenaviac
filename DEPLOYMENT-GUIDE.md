# Deploy Green AVIC Website to Hostinger

## Step 1: Access Hostinger hPanel

1. Go to https://hpanel.hostinger.com
2. Login with your credentials
3. Select your domain/hosting account

## Step 2: Backup & Delete WordPress (IMPORTANT!)

### Option A: Delete via File Manager (Recommended)
1. In hPanel, go to **Files** → **File Manager**
2. Navigate to `public_html` folder
3. **Select ALL files and folders** (wp-admin, wp-content, wp-includes, etc.)
4. Click **Delete** button at the top
5. Confirm deletion

### Option B: Delete via MySQL Database
1. Go to **Databases** → **MySQL Databases**
2. Find the WordPress database
3. Delete the database (optional, but recommended for clean slate)

## Step 3: Upload Green AVIC Website

### Method 1: File Manager Upload (Easiest)

1. In **File Manager**, make sure you're in `public_html` folder
2. Click **Upload** button at top
3. **Upload ALL files from:** `/home/japhet/Desktop/greenaiac/website/`

   Files to upload:
   - index.html
   - assets/ (entire folder)
   - robots.txt
   - sitemap.xml
   - .htaccess

4. Wait for upload to complete (may take 2-5 minutes)

### Method 2: FTP Upload (Alternative)

1. Get FTP credentials from hPanel → Files → FTP Accounts
2. Use FileZilla or similar FTP client
3. Connect to your server
4. Upload all files from `website/` folder to `public_html/`

## Step 4: Verify Website Structure

Your `public_html` folder should look like this:
```
public_html/
├── index.html
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   └── animations.css
│   ├── js/
│   │   └── main.js
│   └── images/
│       ├── brand/
│       ├── gallery/
│       └── products/
├── robots.txt
├── sitemap.xml
└── .htaccess
```

## Step 5: Test Your Website

1. Visit your domain: **https://yourdomain.com**
2. Test all sections:
   - ✓ Homepage loads
   - ✓ Navigation works
   - ✓ All images display
   - ✓ Counter animation works
   - ✓ Mobile menu functions
   - ✓ All links work

## Step 6: Enable SSL/HTTPS (If not already enabled)

1. In hPanel, go to **Security** → **SSL**
2. Enable SSL certificate (Free Let's Encrypt)
3. Force HTTPS redirect

## Troubleshooting

### Images not loading?
- Check that folder names are exactly: `assets/images/brand/`, `assets/images/gallery/`, etc.
- Make sure all files uploaded correctly

### 404 Error?
- Ensure `index.html` is in the root of `public_html`
- Check that file name is exactly `index.html` (lowercase)

### Styling broken?
- Verify `assets/css/style.css` and `assets/css/animations.css` exist
- Check browser console for errors (F12)

## Quick Commands (if using SSH)

```bash
# Delete WordPress files
cd public_html
rm -rf *

# Upload via command line (if you have access)
# Use FTP or File Manager instead for easier upload
```

---

**Need Help?** Contact Hostinger support via live chat in hPanel.

**Your website is ready to go live! 🚀**
