# Git & Deployment Guide

## Initialize Git

```bash
cd /home/japhet/Desktop/greenaiac/website

# Initialize repo
git init

# Add all files
git add .

# First commit
git commit -m "Initial commit: Green AVIC website"
```

## Connect to GitHub (Optional)

```bash
# Create repo on GitHub first, then:
git remote add origin https://github.com/yourusername/greenavic-website.git
git branch -M main
git push -u origin main
```

## Deploy to Hostinger

### Method 1: File Manager (Easiest)
1. Login: https://mail.hostinger.com
2. hPanel → Files → File Manager
3. Navigate to `public_html/`
4. Delete existing files
5. Upload ALL files from website folder
6. Enable SSL in Security settings

### Method 2: FTP
1. Get FTP credentials from hPanel
2. Use FileZilla or similar
3. Upload to `public_html/`

### Method 3: Git Deploy (Advanced)
```bash
# SSH into Hostinger
ssh username@yourdomain.com

# Clone repo
cd public_html
git clone https://github.com/yourusername/greenavic-website.git .

# Update later with:
git pull origin main
```

## Post-Deployment Checklist

- [ ] Website loads correctly
- [ ] All images display
- [ ] Navigation links work
- [ ] Mobile menu functions
- [ ] SSL certificate active (HTTPS)
- [ ] Test on mobile devices

## Update Website

```bash
# Make changes locally
# Test with: python3 -m http.server 8000

# Commit changes
git add .
git commit -m "Update: description of changes"
git push

# Upload to Hostinger via File Manager or pull via SSH
```

---

**Ready for Production!** 🚀
