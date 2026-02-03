#!/bin/bash
# Bump flavor-flavor-flavor Theme Version
# Updates all 2 required locations atomically

set -e

THEME_DIR="/Users/bmnboston/Development/BMNBoston/wordpress/wp-content/themes/flavor-flavor-flavor"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version from version.json
    CURRENT=$(grep -o '"version": "[^"]*"' "$THEME_DIR/version.json" | head -1 | cut -d'"' -f4)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 1.6.0"
    exit 1
fi

NEW_VERSION="$1"
TODAY=$(date +%Y-%m-%d)

echo "Updating flavor-flavor-flavor theme to version $NEW_VERSION..."

# 1. Update version.json
echo "Updating version.json..."
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$THEME_DIR/version.json"
sed -i '' "s/\"updated\": \"[^\"]*\"/\"updated\": \"$TODAY\"/" "$THEME_DIR/version.json"

# 2. Update style.css header
echo "Updating style.css header..."
sed -i '' "s/Version:      [0-9.]*/Version:      $NEW_VERSION/" "$THEME_DIR/style.css"

echo ""
echo "Version updated to $NEW_VERSION in all 2 locations."
echo ""
echo "Verification:"
grep '"version"' "$THEME_DIR/version.json" | head -1
grep 'Version:' "$THEME_DIR/style.css" | head -1

echo ""
echo "Don't forget to update VERSIONS.md!"
