#!/bin/bash
# Bump BMN Schools Plugin Version
# Updates all 3 required locations atomically

set -e

PLUGIN_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/bmn-schools"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from version.json
    CURRENT=$(grep -o '"version": "[^"]*"' "$PLUGIN_DIR/version.json" | head -1 | cut -d'"' -f4)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 0.6.36"
    exit 1
fi

NEW_VERSION="$1"
TODAY=$(date +%Y-%m-%d)

echo "Updating BMN Schools to version $NEW_VERSION..."

# 1. Update version.json
echo "Updating version.json..."
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PLUGIN_DIR/version.json"
sed -i '' "s/\"last_updated\": \"[^\"]*\"/\"last_updated\": \"$TODAY\"/" "$PLUGIN_DIR/version.json"

# 2. Update plugin header in bmn-schools.php
echo "Updating plugin header..."
sed -i '' "s/\* Version: .*/\* Version: $NEW_VERSION/" "$PLUGIN_DIR/bmn-schools.php"

# 3. Update BMN_SCHOOLS_VERSION constant
echo "Updating BMN_SCHOOLS_VERSION constant..."
sed -i '' "s/define('BMN_SCHOOLS_VERSION', '[^']*');/define('BMN_SCHOOLS_VERSION', '$NEW_VERSION');/" "$PLUGIN_DIR/bmn-schools.php"

echo ""
echo "Version updated to $NEW_VERSION in all 3 locations."
echo ""
echo "Verification:"
grep '"version"' "$PLUGIN_DIR/version.json" | head -1
grep 'Version:' "$PLUGIN_DIR/bmn-schools.php" | head -1
grep "BMN_SCHOOLS_VERSION" "$PLUGIN_DIR/bmn-schools.php" | head -1

echo ""
echo "Don't forget to update CLAUDE.md changelog!"
