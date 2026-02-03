#!/bin/bash
# Bump iOS App Version
# Updates all 6 occurrences of CURRENT_PROJECT_VERSION in project.pbxproj

set -e

PROJECT_FILE="/Users/bmnboston/Development/BMNBoston/ios/BMNBoston.xcodeproj/project.pbxproj"

# Check if version argument provided
if [ -z "$1" ]; then
    # Get current version
    CURRENT=$(grep "CURRENT_PROJECT_VERSION = " "$PROJECT_FILE" | head -1 | grep -o '[0-9]*' | head -1)
    echo "Current version: $CURRENT"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 97"
    exit 1
fi

NEW_VERSION="$1"

# Count occurrences before
COUNT_BEFORE=$(grep -c "CURRENT_PROJECT_VERSION = " "$PROJECT_FILE" || echo "0")
echo "Found $COUNT_BEFORE occurrences of CURRENT_PROJECT_VERSION"

if [ "$COUNT_BEFORE" -ne 6 ]; then
    echo "WARNING: Expected 6 occurrences but found $COUNT_BEFORE"
fi

echo "Updating iOS app to version $NEW_VERSION..."

# Update all occurrences
sed -i '' "s/CURRENT_PROJECT_VERSION = [0-9]*;/CURRENT_PROJECT_VERSION = $NEW_VERSION;/g" "$PROJECT_FILE"

# Verify
COUNT_AFTER=$(grep -c "CURRENT_PROJECT_VERSION = $NEW_VERSION" "$PROJECT_FILE" || echo "0")
echo ""
echo "Updated $COUNT_AFTER occurrences to version $NEW_VERSION"

if [ "$COUNT_AFTER" -ne 6 ]; then
    echo "WARNING: Expected 6 updates but got $COUNT_AFTER"
    exit 1
fi

echo ""
echo "Don't forget to update ios/CLAUDE.md with recent changes!"
