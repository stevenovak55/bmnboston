#!/bin/bash
# Bump Exclusive Listings Plugin Version
# Updates all 3 required locations atomically

set -e

PLUGIN_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/exclusive-listings"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from version.json
    CURRENT=$(grep -o '"version": "[^"]*"' "$PLUGIN_DIR/version.json" | head -1 | cut -d'"' -f4)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 1.5.4"
    exit 1
fi

NEW_VERSION="$1"
TODAY=$(date +%Y-%m-%d)

echo "Updating Exclusive Listings to version $NEW_VERSION..."

# 1. Update version.json
echo "Updating version.json..."
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PLUGIN_DIR/version.json"
sed -i '' "s/\"last_updated\": \"[^\"]*\"/\"last_updated\": \"$TODAY\"/" "$PLUGIN_DIR/version.json"

# 2. Update plugin header (note: extra spaces in original format)
echo "Updating plugin header..."
sed -i '' "s/\* Version:           [0-9.]*/\* Version:           $NEW_VERSION/" "$PLUGIN_DIR/exclusive-listings.php"

# 3. Update EL_VERSION constant
echo "Updating EL_VERSION constant..."
sed -i '' "s/define('EL_VERSION', '[^']*');/define('EL_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/exclusive-listings.php"

echo ""
echo "Version updated to $NEW_VERSION in all 3 locations."
echo ""
echo "Verification:"
grep '"version"' "$PLUGIN_DIR/version.json" | head -1
grep 'Version:' "$PLUGIN_DIR/exclusive-listings.php" | head -1
grep "EL_VERSION" "$PLUGIN_DIR/exclusive-listings.php" | head -1

echo ""
echo "Don't forget to update VERSIONS.md!"
