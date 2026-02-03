#!/bin/bash
# Bump SN Appointment Booking Plugin Version
# Updates all 4 required locations atomically

set -e

PLUGIN_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/sn-appointment-booking"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from version.json
    CURRENT=$(grep -o '"version": "[^"]*"' "$PLUGIN_DIR/version.json" | head -1 | cut -d'"' -f4)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 1.9.6"
    exit 1
fi

NEW_VERSION="$1"
TODAY=$(date +%Y-%m-%d)

echo "Updating SN Appointment Booking to version $NEW_VERSION..."

# 1. Update version.json
echo "Updating version.json..."
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PLUGIN_DIR/version.json"
sed -i '' "s/\"last_updated\": \"[^\"]*\"/\"last_updated\": \"$TODAY\"/" "$PLUGIN_DIR/version.json"

# 2. Update plugin header in sn-appointment-booking.php
echo "Updating plugin header..."
sed -i '' "s/\* Version: .*/\* Version: $NEW_VERSION/" "$PLUGIN_DIR/sn-appointment-booking.php"

# 3. Update SNAB_VERSION constant
echo "Updating SNAB_VERSION constant..."
sed -i '' "s/define('SNAB_VERSION', '[^']*');/define('SNAB_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/sn-appointment-booking.php"

# 4. Update CURRENT_VERSION in class-snab-upgrader.php
echo "Updating CURRENT_VERSION in upgrader..."
sed -i '' "s/const CURRENT_VERSION = '[^']*';/const CURRENT_VERSION = '$NEW_VERSION';/" "$PLUGIN_DIR/includes/class-snab-upgrader.php"

# 5. Update test bootstrap (optional but keeps it in sync)
if [ -f "$PLUGIN_DIR/tests/bootstrap.php" ]; then
    echo "Updating test bootstrap..."
    sed -i '' "s/define('SNAB_VERSION', '[^']*');/define('SNAB_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/tests/bootstrap.php"
fi

echo ""
echo "Version updated to $NEW_VERSION in all 4 locations."
echo ""
echo "Verification:"
grep '"version"' "$PLUGIN_DIR/version.json" | head -1
grep 'Version:' "$PLUGIN_DIR/sn-appointment-booking.php" | head -1
grep "define('SNAB_VERSION'" "$PLUGIN_DIR/sn-appointment-booking.php" | head -1
grep "CURRENT_VERSION = " "$PLUGIN_DIR/includes/class-snab-upgrader.php" | head -1

echo ""
echo "Don't forget to update VERSIONS.md!"
