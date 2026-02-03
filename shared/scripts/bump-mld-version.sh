#!/bin/bash
# Bump MLS Listings Display Plugin Version
# Updates all 3 required locations atomically

set -e

PLUGIN_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/mls-listings-display"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from version.json
    CURRENT=$(grep -o '"version": "[^"]*"' "$PLUGIN_DIR/version.json" | head -1 | cut -d'"' -f4)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 6.30.19"
    exit 1
fi

NEW_VERSION="$1"
TODAY=$(date +%Y-%m-%d)

echo "Updating MLS Listings Display to version $NEW_VERSION..."

# 1. Update version.json
echo "Updating version.json..."
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PLUGIN_DIR/version.json"
sed -i '' "s/\"db_version\": \"[^\"]*\"/\"db_version\": \"$NEW_VERSION\"/" "$PLUGIN_DIR/version.json"
sed -i '' "s/\"last_updated\": \"[^\"]*\"/\"last_updated\": \"$TODAY\"/" "$PLUGIN_DIR/version.json"

# 2. Update plugin header in mls-listings-display.php
echo "Updating plugin header..."
sed -i '' "s/\* Version: .*/\* Version: $NEW_VERSION/" "$PLUGIN_DIR/mls-listings-display.php"

# 3. Update MLD_VERSION constant
echo "Updating MLD_VERSION constant..."
sed -i '' "s/define('MLD_VERSION', '[^']*');/define('MLD_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/mls-listings-display.php"

echo ""
echo "Version updated to $NEW_VERSION in all 3 locations."
echo ""
echo "Verification:"
grep '"version"' "$PLUGIN_DIR/version.json" | head -1
grep 'Version:' "$PLUGIN_DIR/mls-listings-display.php" | head -1
grep "MLD_VERSION" "$PLUGIN_DIR/mls-listings-display.php" | head -1

echo ""
echo "Don't forget to update CLAUDE.md changelog and create a deployment zip!"
