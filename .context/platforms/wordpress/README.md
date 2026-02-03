# WordPress Development

Quick reference for WordPress and plugin development.

## Custom Plugins

| Plugin | Purpose |
|--------|---------|
| MLS Listings Display | Property search, REST API |
| BMN Schools | School data integration |
| SN Appointment Booking | Google Calendar bookings |
| Bridge MLS Extractor | MLS data extraction |
| Exclusive Listings | Exclusive property management |

**Current versions:** See [../../VERSIONS.md](../../VERSIONS.md)

## Documentation

- [Deployment](deployment.md) - Production deployment (Kinsta)
- [Troubleshooting Guide](../../troubleshooting/troubleshooting.md) - Common WordPress issues

## Local Development

### Docker Commands

```bash
cd ~/Development/BMNBoston/shared/scripts
./start-dev.sh          # Start containers
./stop-dev.sh           # Stop containers
./logs.sh               # View logs
```

### Access Points

| Service | URL |
|---------|-----|
| WordPress | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| Mailhog | http://localhost:8025 |

## Version Bumping

All plugins require updating 3 locations:

1. `version.json` - version and last_updated
2. `plugin-name.php` - Header comment
3. `plugin-name.php` - Define constant

## Database Access

```bash
# Docker MySQL CLI
docker compose exec db mysql -u wordpress -pwordpress wordpress

# Check tables
SHOW TABLES LIKE 'wp_bme_%';
DESCRIBE wp_bme_listing_summary;
```

## Key Patterns

### Use Summary Tables

```php
// FAST
$wpdb->get_results("SELECT * FROM bme_listing_summary WHERE ...");

// SLOW - avoid
$wpdb->get_results("SELECT * FROM bme_listings l JOIN bme_details d ON ...");
```

### Use $wpdb->prepare()

```php
$wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bme_listing_summary WHERE city = %s",
    $city
));
```

### Security

- Use nonces for AJAX
- Sanitize inputs: `sanitize_text_field()`, `absint()`
- Escape outputs: `esc_html()`, `wp_kses_post()`
- Check capabilities: `current_user_can('manage_options')`
