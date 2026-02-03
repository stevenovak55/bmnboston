#!/bin/bash
#
# BMNBoston Rollback Script
#
# Quickly rollback a plugin/theme to a previous git version.
# Stores deployed versions on server for fast recovery.
#
# Usage:
#   ./rollback.sh <plugin-name>                 Show last 10 deployed versions
#   ./rollback.sh <plugin-name> <git-commit>    Rollback to specific commit
#   ./rollback.sh <plugin-name> --last          Rollback to previous deployment
#   ./rollback.sh theme                         Same for theme
#
# Examples:
#   ./rollback.sh mls-listings-display
#   ./rollback.sh mls-listings-display abc1234
#   ./rollback.sh mls-listings-display --last
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
REPO_ROOT="$HOME/Development/BMNBoston"
PLUGINS_LOCAL="$REPO_ROOT/wordpress/wp-content/plugins"
THEMES_LOCAL="$REPO_ROOT/wordpress/wp-content/themes"
THEME_NAME="flavor-flavor-flavor"

# Server credentials
declare -A SERVERS
SERVERS["bmnboston.com"]="stevenovakcom@35.236.219.140|57105|cFDIB2uPBj5LydX"
SERVERS["steve-novak.com"]="stevenovakrealestate@35.236.219.140|50594|nxGDPBDdpeuh2Io"

# Parse arguments
COMPONENT=$1
TARGET=$2

if [ -z "$COMPONENT" ]; then
    echo -e "${RED}Error: No component specified${NC}"
    echo ""
    echo "Usage: rollback.sh <plugin-name> [<git-commit>|--last]"
    echo "       rollback.sh theme [<git-commit>|--last]"
    echo ""
    echo "Available plugins:"
    ls -1 "$PLUGINS_LOCAL" 2>/dev/null | grep -v "^index.php$" | sed 's/^/  - /'
    echo ""
    echo "Theme: $THEME_NAME"
    exit 1
fi

# Determine if rolling back theme or plugin
if [ "$COMPONENT" = "theme" ]; then
    LOCAL_PATH="$THEMES_LOCAL/$THEME_NAME"
    REMOTE_PATH="wp-content/themes/$THEME_NAME"
    COMPONENT_NAME="$THEME_NAME (theme)"
    GIT_PATH="wordpress/wp-content/themes/$THEME_NAME"
else
    LOCAL_PATH="$PLUGINS_LOCAL/$COMPONENT"
    REMOTE_PATH="wp-content/plugins/$COMPONENT"
    COMPONENT_NAME="$COMPONENT"
    GIT_PATH="wordpress/wp-content/plugins/$COMPONENT"
fi

# Verify component exists
if [ ! -d "$LOCAL_PATH" ]; then
    echo -e "${RED}Error: Component not found at $LOCAL_PATH${NC}"
    exit 1
fi

cd "$REPO_ROOT"

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  BMNBoston Rollback Script${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Component: ${GREEN}$COMPONENT_NAME${NC}"
echo ""

# If no target specified, show recent commits
if [ -z "$TARGET" ]; then
    echo -e "${YELLOW}Recent commits for $COMPONENT_NAME:${NC}"
    echo ""
    git log --oneline -10 -- "$GIT_PATH" | while read -r line; do
        COMMIT_HASH=$(echo "$line" | cut -d' ' -f1)
        COMMIT_MSG=$(echo "$line" | cut -d' ' -f2-)
        echo -e "  ${GREEN}$COMMIT_HASH${NC}  $COMMIT_MSG"
    done
    echo ""
    echo "Usage: rollback.sh $COMPONENT <commit-hash>"
    echo "       rollback.sh $COMPONENT --last"
    exit 0
fi

# Determine the target commit
if [ "$TARGET" = "--last" ]; then
    # Get the commit before the current HEAD for this component
    TARGET_COMMIT=$(git log --oneline -2 -- "$GIT_PATH" | tail -1 | cut -d' ' -f1)
    if [ -z "$TARGET_COMMIT" ]; then
        echo -e "${RED}Error: Could not find previous commit for $COMPONENT_NAME${NC}"
        exit 1
    fi
    echo -e "Rolling back to previous commit: ${GREEN}$TARGET_COMMIT${NC}"
else
    TARGET_COMMIT=$TARGET
fi

# Verify the commit exists and has changes to this component
if ! git cat-file -e "$TARGET_COMMIT" 2>/dev/null; then
    echo -e "${RED}Error: Commit $TARGET_COMMIT does not exist${NC}"
    exit 1
fi

# Get commit info
COMMIT_MSG=$(git log --format="%s" -1 "$TARGET_COMMIT" 2>/dev/null)
COMMIT_DATE=$(git log --format="%ci" -1 "$TARGET_COMMIT" 2>/dev/null)

echo ""
echo -e "${YELLOW}=== Rollback Target ===${NC}"
echo -e "  Commit: ${GREEN}$TARGET_COMMIT${NC}"
echo -e "  Message: $COMMIT_MSG"
echo -e "  Date: $COMMIT_DATE"
echo ""

# Confirm rollback
echo -e "${YELLOW}WARNING: This will replace production files with version from $TARGET_COMMIT${NC}"
echo -e "Press Enter to continue or Ctrl+C to cancel..."
read -r

# Create a temporary directory for the old version
TEMP_DIR=$(mktemp -d)
echo -e "${BLUE}=== Extracting files from commit $TARGET_COMMIT ===${NC}"

# Extract the component files from the target commit
git archive "$TARGET_COMMIT" -- "$GIT_PATH" | tar -xC "$TEMP_DIR"

if [ ! -d "$TEMP_DIR/$GIT_PATH" ]; then
    echo -e "${RED}Error: Component not found in commit $TARGET_COMMIT${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

EXTRACTED_PATH="$TEMP_DIR/$GIT_PATH"

# Get version info if available
if [ -f "$EXTRACTED_PATH/version.json" ]; then
    OLD_VERSION=$(python3 -c "import json; print(json.load(open('$EXTRACTED_PATH/version.json'))['version'])" 2>/dev/null || echo "unknown")
    echo -e "  Version in rollback: ${GREEN}$OLD_VERSION${NC}"
fi

echo ""

# Deploy to each server
for SERVER_NAME in "${!SERVERS[@]}"; do
    IFS='|' read -r USER_HOST PORT PASS <<< "${SERVERS[$SERVER_NAME]}"

    echo -e "${BLUE}=== Rolling back on $SERVER_NAME (port $PORT) ===${NC}"

    # Step 1: Backup current version (just in case)
    echo -n "  Creating backup... "
    BACKUP_NAME="${COMPONENT}_backup_$(date +%Y%m%d_%H%M%S)"
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "cp -r ~/public/$REMOTE_PATH ~/public/${BACKUP_NAME}" 2>/dev/null || true
    echo -e "${GREEN}done${NC} (saved as $BACKUP_NAME)"

    # Step 2: Upload rollback files
    echo -n "  Uploading rollback files... "
    sshpass -p "$PASS" scp -P "$PORT" -r -q \
        "$EXTRACTED_PATH/" \
        "$USER_HOST:~/public/$REMOTE_PATH/" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 3: Clear OPcache (touch PHP files)
    echo -n "  Invalidating OPcache... "
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "find ~/public/$REMOTE_PATH -name '*.php' -exec touch {} \;" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 4: Fix file permissions
    echo -n "  Fixing file permissions... "
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "find ~/public/$REMOTE_PATH -name '*.css' -exec chmod 644 {} \; 2>/dev/null; \
         find ~/public/$REMOTE_PATH -name '*.js' -exec chmod 644 {} \; 2>/dev/null; \
         find ~/public/$REMOTE_PATH -name '*.php' -exec chmod 644 {} \; 2>/dev/null" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 5: Log the rollback
    ROLLBACK_LOG="Rollback: $COMPONENT to $TARGET_COMMIT ($COMMIT_MSG) at $(date '+%Y-%m-%d %H:%M:%S')"
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "echo '$ROLLBACK_LOG' >> ~/public/deployment.log" 2>/dev/null || true

    echo ""
done

# Cleanup
rm -rf "$TEMP_DIR"

# Post-rollback verification
echo -e "${BLUE}=== Post-Rollback Verification ===${NC}"

# Test API endpoint
echo -n "  Testing API (bmnboston.com)... "
RESPONSE=$(curl -s --max-time 10 "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" 2>/dev/null)
TOTAL=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total', 'error'))" 2>/dev/null || echo "error")

if [ "$TOTAL" != "error" ] && [ "$TOTAL" -gt 0 ]; then
    echo -e "${GREEN}OK ($TOTAL properties)${NC}"
else
    echo -e "${RED}FAILED - Check server logs!${NC}"
fi

# Test school filter
echo -n "  Testing school filter... "
SCHOOL_RESPONSE=$(curl -s --max-time 10 "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" 2>/dev/null)
SCHOOL_TOTAL=$(echo "$SCHOOL_RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total', 'error'))" 2>/dev/null || echo "error")

if [ "$SCHOOL_TOTAL" != "error" ] && [ "$SCHOOL_TOTAL" -gt 1000 ]; then
    echo -e "${GREEN}OK ($SCHOOL_TOTAL properties with A schools)${NC}"
elif [ "$SCHOOL_TOTAL" != "error" ]; then
    echo -e "${YELLOW}WARNING: Only $SCHOOL_TOTAL properties (expected 1000+)${NC}"
else
    echo -e "${RED}FAILED${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Rollback Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Rolled back $COMPONENT_NAME to commit $TARGET_COMMIT"
echo "Backups saved on servers as: ${BACKUP_NAME}"
echo ""
echo -e "${YELLOW}If issues persist, check:${NC}"
echo "  - Server error logs: tail ~/logs/error.log"
echo "  - OPcache may need manual clear in Kinsta dashboard"
echo "  - CDN cache may need purging"
echo ""
