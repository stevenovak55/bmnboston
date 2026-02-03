# WordPress Production Deployment

## Server Details (Kinsta)

| Setting | Value |
|---------|-------|
| Host | `35.236.219.140` |
| Port | `57105` |
| Username | `stevenovakcom` |
| Password | See password manager (Kinsta SFTP) |

### Server Paths

| Purpose | Path |
|---------|------|
| Home directory | `~/` or `/www/stevenovakcom_662/` |
| WordPress root | `~/public/` |
| Plugins | `~/public/wp-content/plugins/` |
| Themes | `~/public/wp-content/themes/` |

## Deployment Commands

### SSH Access

```bash
ssh stevenovakcom@35.236.219.140 -p 57105
```

### Upload Single File

```bash
scp -P 57105 file.php \
    stevenovakcom@35.236.219.140:~/public/wp-content/plugins/plugin-name/includes/
```

### Upload Folder

```bash
scp -P 57105 -r plugin-name \
    stevenovakcom@35.236.219.140:~/public/wp-content/plugins/
```

### With sshpass

```bash
sshpass -p '$PASSWORD' scp -P 57105 file.php \
    stevenovakcom@35.236.219.140:~/public/wp-content/plugins/...
```

## Post-Deployment Steps

### Invalidate OPcache

After uploading PHP files, touch them to invalidate opcache:

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "touch ~/public/wp-content/plugins/PLUGIN/includes/*.php"
```

### Clear Sitemap Cache

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "rm -f ~/public/wp-content/cache/mld-sitemaps/*.xml"
```

### Flush Rewrite Rules

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "cd ~/public && php -r \"require_once 'wp-load.php'; flush_rewrite_rules(); echo 'Done';\""
```

## Creating Plugin Zip

```bash
cd ~/Development/BMNBoston/wordpress/wp-content/plugins

zip -r plugin-name-X.Y.Z.zip plugin-name \
    -x "*.git*" -x "*node_modules*" -x "*.DS_Store"
```

Then upload via WordPress Admin > Plugins > Add New > Upload Plugin.

## Deployment Checklist

### Before Deploying

- [ ] Update version in all 3 locations
- [ ] Test locally
- [ ] Commit changes to git

### Deploying

- [ ] Upload files via SCP
- [ ] Touch PHP files for opcache
- [ ] Clear relevant caches

### After Deploying

- [ ] Test on production website
- [ ] Test on iOS app (if API changes)
- [ ] Monitor error logs

## Debugging on Production

### Check WordPress Debug Log

Enable in wp-config.php:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

View log:
```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -100 ~/public/wp-content/debug.log"
```

### Check PHP Errors

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -100 /tmp/php_errors.log"
```

## CDN Considerations

Kinsta CDN caches CSS/JS with 1-year max-age.

**When changing CSS/JS:**
1. Bump version constant (`MLD_VERSION`, `BMN_SCHOOLS_VERSION`)
2. Verify asset enqueued with version parameter
3. Test with browser DevTools "Disable cache"

## Rollback

If deployment breaks something:

1. Access server via SSH
2. Navigate to plugin folder
3. Replace with previous version from git or backup

```bash
cd ~/public/wp-content/plugins/plugin-name
git checkout HEAD~1 -- file-that-broke.php
```
