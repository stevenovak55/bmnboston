#!/bin/bash
#
# check-versions.sh
# Checks version consistency between code and documentation
#
# Usage: ./check-versions.sh
#
# Returns 0 if all versions match, 1 if mismatches found

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Paths
PROJECT_ROOT="$HOME/Development/BMNBoston"
IOS_PROJECT="$PROJECT_ROOT/ios/BMNBoston.xcodeproj/project.pbxproj"
MLD_VERSION_JSON="$PROJECT_ROOT/wordpress/wp-content/plugins/mls-listings-display/version.json"
MLD_MAIN_PHP="$PROJECT_ROOT/wordpress/wp-content/plugins/mls-listings-display/mls-listings-display.php"
SCHOOLS_VERSION_JSON="$PROJECT_ROOT/wordpress/wp-content/plugins/bmn-schools/version.json"
SCHOOLS_MAIN_PHP="$PROJECT_ROOT/wordpress/wp-content/plugins/bmn-schools/bmn-schools.php"
SNAB_VERSION_JSON="$PROJECT_ROOT/wordpress/wp-content/plugins/sn-appointment-booking/version.json"
SNAB_MAIN_PHP="$PROJECT_ROOT/wordpress/wp-content/plugins/sn-appointment-booking/sn-appointment-booking.php"
ROOT_CLAUDE="$HOME/CLAUDE.md"
CONTEXT_README="$PROJECT_ROOT/.context/README.md"

ERRORS=0

echo "=================================="
echo "  Version Consistency Checker"
echo "=================================="
echo ""

# Function to extract version from version.json
get_json_version() {
    grep '"version"' "$1" | head -1 | sed 's/.*"version": *"\([^"]*\)".*/\1/' | tr -d '\r'
}

# Function to extract version from PHP header
get_php_header_version() {
    grep "Version:" "$1" | head -1 | sed 's/.*Version: *\([^ ]*\).*/\1/' | tr -d '\r'
}

# Function to extract version from PHP constant
get_php_constant_version() {
    grep "define.*VERSION" "$1" | head -1 | sed "s/.*'\([^']*\)'.*/\1/" | tr -d '\r'
}

# Function to extract iOS version
get_ios_version() {
    grep "CURRENT_PROJECT_VERSION" "$1" | head -1 | sed 's/.*= *\([0-9]*\).*/\1/'
}

# Function to count iOS version occurrences
count_ios_versions() {
    grep -c "CURRENT_PROJECT_VERSION = $1;" "$2" 2>/dev/null || echo 0
}

# Function to extract version from CLAUDE.md
get_claude_version() {
    # Parse: | iOS App | v132 |
    # or:    | MLS Listings Display | v6.31.11 |
    grep "| $1 |" "$2" | awk -F'|' '{print $3}' | tr -d ' v'
}

echo "📱 iOS App"
echo "----------"
IOS_VERSION=$(get_ios_version "$IOS_PROJECT")
IOS_COUNT=$(count_ios_versions "$IOS_VERSION" "$IOS_PROJECT")
CLAUDE_IOS=$(get_claude_version "iOS App" "$ROOT_CLAUDE")

echo "  Code version: v$IOS_VERSION (found in $IOS_COUNT/6 occurrences)"
echo "  CLAUDE.md:    v$CLAUDE_IOS"

if [ "$IOS_VERSION" != "$CLAUDE_IOS" ]; then
    echo -e "  ${RED}✗ MISMATCH${NC}"
    ((ERRORS++))
else
    echo -e "  ${GREEN}✓ Match${NC}"
fi

if [ "$IOS_COUNT" != "6" ]; then
    echo -e "  ${YELLOW}⚠ Warning: Expected 6 occurrences in project.pbxproj, found $IOS_COUNT${NC}"
fi
echo ""

echo "🏠 MLS Listings Display"
echo "-----------------------"
MLD_JSON=$(get_json_version "$MLD_VERSION_JSON")
MLD_PHP=$(get_php_header_version "$MLD_MAIN_PHP")
MLD_CONST=$(get_php_constant_version "$MLD_MAIN_PHP")
CLAUDE_MLD=$(get_claude_version "MLS Listings Display" "$ROOT_CLAUDE")

echo "  version.json:  $MLD_JSON"
echo "  PHP header:    $MLD_PHP"
echo "  PHP constant:  $MLD_CONST"
echo "  CLAUDE.md:     v$CLAUDE_MLD"

if [ "$MLD_JSON" != "$MLD_PHP" ] || [ "$MLD_PHP" != "$MLD_CONST" ]; then
    echo -e "  ${RED}✗ Code versions don't match${NC}"
    ((ERRORS++))
elif [ "$MLD_JSON" != "$CLAUDE_MLD" ]; then
    echo -e "  ${RED}✗ Documentation doesn't match code${NC}"
    ((ERRORS++))
else
    echo -e "  ${GREEN}✓ All match${NC}"
fi
echo ""

echo "🏫 BMN Schools"
echo "--------------"
SCHOOLS_JSON=$(get_json_version "$SCHOOLS_VERSION_JSON")
SCHOOLS_PHP=$(get_php_header_version "$SCHOOLS_MAIN_PHP")
SCHOOLS_CONST=$(get_php_constant_version "$SCHOOLS_MAIN_PHP")
CLAUDE_SCHOOLS=$(get_claude_version "BMN Schools" "$ROOT_CLAUDE")

echo "  version.json:  $SCHOOLS_JSON"
echo "  PHP header:    $SCHOOLS_PHP"
echo "  PHP constant:  $SCHOOLS_CONST"
echo "  CLAUDE.md:     v$CLAUDE_SCHOOLS"

if [ "$SCHOOLS_JSON" != "$SCHOOLS_PHP" ] || [ "$SCHOOLS_PHP" != "$SCHOOLS_CONST" ]; then
    echo -e "  ${RED}✗ Code versions don't match${NC}"
    ((ERRORS++))
elif [ "$SCHOOLS_JSON" != "$CLAUDE_SCHOOLS" ]; then
    echo -e "  ${RED}✗ Documentation doesn't match code${NC}"
    ((ERRORS++))
else
    echo -e "  ${GREEN}✓ All match${NC}"
fi
echo ""

echo "📅 SN Appointments"
echo "------------------"
SNAB_JSON=$(get_json_version "$SNAB_VERSION_JSON")
SNAB_PHP=$(get_php_header_version "$SNAB_MAIN_PHP")
SNAB_CONST=$(get_php_constant_version "$SNAB_MAIN_PHP")
CLAUDE_SNAB=$(get_claude_version "SN Appointments" "$ROOT_CLAUDE")

echo "  version.json:  $SNAB_JSON"
echo "  PHP header:    $SNAB_PHP"
echo "  PHP constant:  $SNAB_CONST"
echo "  CLAUDE.md:     v$CLAUDE_SNAB"

if [ "$SNAB_JSON" != "$SNAB_PHP" ] || [ "$SNAB_PHP" != "$SNAB_CONST" ]; then
    echo -e "  ${RED}✗ Code versions don't match${NC}"
    ((ERRORS++))
elif [ "$SNAB_JSON" != "$CLAUDE_SNAB" ]; then
    echo -e "  ${RED}✗ Documentation doesn't match code${NC}"
    ((ERRORS++))
else
    echo -e "  ${GREEN}✓ All match${NC}"
fi
echo ""

echo "=================================="
if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}All versions consistent!${NC}"
    exit 0
else
    echo -e "${RED}Found $ERRORS version mismatch(es)${NC}"
    echo ""
    echo "To fix:"
    echo "  1. Update code versions if releasing new version"
    echo "  2. Update CLAUDE.md version table to match code"
    echo "  3. Run this script again to verify"
    exit 1
fi
