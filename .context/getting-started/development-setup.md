# Development Setup

## Prerequisites

- macOS with Xcode 15+
- Docker Desktop
- Node.js 18+ (for some tooling)
- SSH access to production server

## Docker Environment

### Start/Stop Commands

```bash
cd ~/Development/BMNBoston/shared/scripts

./start-dev.sh          # Start all containers
./start-dev.sh --logs   # Start and follow logs
./stop-dev.sh           # Stop all containers
./logs.sh               # Follow container logs
```

### Database Commands

```bash
./export-db.sh          # Export database backup
./import-db.sh          # Import database from backup
./reset-db.sh           # Reset database to clean state
```

### Access Points (Development)

| Service | URL | Credentials |
|---------|-----|-------------|
| WordPress | http://localhost:8080 | wp-admin |
| phpMyAdmin | http://localhost:8081 | wordpress / wordpress_dev_password |
| Mailhog | http://localhost:8025 | - |
| MySQL | localhost:3306 | wordpress / wordpress_dev_password |

## iOS Development

### Building

```bash
cd ~/Development/BMNBoston/ios

# Build for Simulator
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15' build

# Build for Device
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS,id=00008140-00161D3A362A801C' \
    -allowProvisioningUpdates build
```

### Installing to Device

```bash
# Install app
xcrun devicectl device install app \
    --device 00008140-00161D3A362A801C \
    /Users/bmnboston/Library/Developer/Xcode/DerivedData/BMNBoston-*/Build/Products/Debug-iphoneos/BMNBoston.app

# Launch app
xcrun devicectl device process launch \
    --device 00008140-00161D3A362A801C \
    com.bmnboston.realestate
```

### Running Tests

```bash
xcodebuild test -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15'
```

## Production Server Access (Kinsta)

| Setting | Value |
|---------|-------|
| Host | `35.236.219.140` |
| Port | `57105` |
| Username | `stevenovakcom` |
| Password | See password manager (Kinsta SFTP) |

### SSH Access

```bash
ssh stevenovakcom@35.236.219.140 -p 57105
```

### Upload Files

```bash
# Upload single file
scp -P 57105 file.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/plugin-name/includes/

# Upload plugin folder
scp -P 57105 -r plugin-name stevenovakcom@35.236.219.140:~/public/wp-content/plugins/

# With sshpass (password from password manager)
sshpass -p '$PASSWORD' scp -P 57105 file.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/...
```

### Server Paths

| Purpose | Path |
|---------|------|
| Home directory | `~/` or `/www/stevenovakcom_662/` |
| WordPress root | `~/public/` |
| Plugins | `~/public/wp-content/plugins/` |
| Themes | `~/public/wp-content/themes/` |

### After Deployment

Always touch PHP files to invalidate opcache:

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "touch ~/public/wp-content/plugins/PLUGIN/includes/*.php"
```

## WordPress Plugin Development

### Testing Plugins

```bash
cd wordpress/wp-content/plugins/mls-listings-display
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
```

### Creating Plugin Zip

```bash
cd ~/Development/BMNBoston/wordpress/wp-content/plugins
zip -r plugin-name-X.Y.Z.zip plugin-name \
    -x "*.git*" -x "*node_modules*" -x "*.DS_Store"
```

## Common Issues

### "Profile has not been explicitly trusted"
Go to iPhone Settings > General > VPN & Device Management > Trust certificate

### Docker containers won't start
```bash
docker compose down -v
docker compose up -d
```

### PHP changes not reflecting
Touch the files to invalidate opcache, or wait a few minutes for automatic refresh.
