# Installation Guide

## File Structure

Create this folder structure in `/wp-content/plugins/`:

```
wc-api-mps-scheduled-sync/
├── wc-api-mps-scheduled-sync.php
├── README.txt
└── includes/
    ├── activation.php
    ├── admin-page.php
    ├── cron-manager.php
    ├── hooks.php
    ├── logger.php
    ├── product-queries.php
    ├── sync-engine.php
    └── time-manager.php
```

## Quick Setup Steps

### 1. Create Plugin Folder

```bash
cd /path/to/wordpress/wp-content/plugins/
mkdir wc-api-mps-scheduled-sync
cd wc-api-mps-scheduled-sync
mkdir includes
```

### 2. Upload Files

- Put `wc-api-mps-scheduled-sync.php` in the main folder
- Put all 8 files from `includes/` into the `includes/` subfolder

### 3. Set Permissions

```bash
chmod 755 wc-api-mps-scheduled-sync.php
chmod 755 includes/*.php
```

### 4. Activate Plugin

Go to WordPress Admin → Plugins → Activate "WooCommerce Product Sync - Scheduled Sync"

### 5. Setup Linux Cron (Important!)

WordPress cron is unreliable. Add a real cron job:

```bash
crontab -e
```

Add this line:

```bash
*/15 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Or if you have WP-CLI:

```bash
*/15 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

## Verify It's Working

1. Go to **Product Sync → Scheduled Sync**
2. Check "Cron Status" shows: ✓ Active
3. Check "Next Run" shows a future timestamp
4. Click "Run Sync Now" to test

## Your Category Exclusions ARE Respected

Don't worry! The plugin uses your existing store settings:

- If you excluded "Electronics" category → It won't sync products from that category
- If you set a store to "Inactive" → It won't sync to that store
- All your price adjustments → Applied automatically
- Your meta data exclusions → Respected

The plugin just adds scheduled execution. All your rules still apply.

## Monitoring

Check logs in the admin page:

- Success: "Successfully synced product ID: X"
- Errors: "Error syncing product ID X: [reason]"

## Sync Schedule

| Time | Sync Type | Products per Run | What Syncs |
|------|-----------|------------------|------------|
| 12:00 AM - 6:30 AM | Full | 20 | Everything |
| 6:30 AM - 12:00 AM | Light | 5 | Price & quantity only |

## Troubleshooting

**Page times out (504 error)**

- The sync still works in background
- Consider increasing PHP timeout

**Sync doesn't run**

- Check Linux cron is set up
- Visit the admin page to see cron status

**Products not syncing**

- Check logs for errors
- Verify original plugin is configured
- Check if store is active

## Need Help?

1. Check logs in admin page
2. Enable WordPress debug: `define('WP_DEBUG', true);` in wp-config.php
3. Check `/wp-content/debug.log`
