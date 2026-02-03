#!/bin/bash
# Bump Bridge MLS Extractor Pro Plugin Version
# Updates all 3 required locations atomically

set -e

PLUGIN_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/bridge-mls-extractor-pro-review"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from plugin header
    CURRENT=$(grep "BME_VERSION" "$PLUGIN_DIR/bridge-mls-extractor-pro.php" | head -1 | grep -o "'[0-9.]*'" | tr -d "'")
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 4.0.33"
    exit 1
fi

NEW_VERSION="$1"

echo "Updating Bridge MLS Extractor Pro to version $NEW_VERSION..."

# 1. Update plugin header
echo "Updating plugin header..."
sed -i '' "s/\* Version: .*/\* Version: $NEW_VERSION/" "$PLUGIN_DIR/bridge-mls-extractor-pro.php"

# 2. Update BME_PRO_VERSION constant (preserve comment)
echo "Updating BME_PRO_VERSION constant..."
sed -i '' "s/define('BME_PRO_VERSION', '[^']*');/define('BME_PRO_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/bridge-mls-extractor-pro.php"

# 3. Update BME_VERSION constant (preserve comment)
echo "Updating BME_VERSION constant..."
sed -i '' "s/define('BME_VERSION', '[^']*');/define('BME_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/bridge-mls-extractor-pro.php"

echo ""
echo "Version updated to $NEW_VERSION in all 3 locations."
echo ""
echo "Verification:"
grep 'Version:' "$PLUGIN_DIR/bridge-mls-extractor-pro.php" | head -1
grep "BME_PRO_VERSION" "$PLUGIN_DIR/bridge-mls-extractor-pro.php" | head -1
grep "BME_VERSION" "$PLUGIN_DIR/bridge-mls-extractor-pro.php" | head -1

echo ""
echo "Don't forget to update VERSIONS.md!"
