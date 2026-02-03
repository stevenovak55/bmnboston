#!/bin/bash
#
# BMNBoston Deployment Script
#
# Automates deployment of WordPress plugins/themes to both production servers,
# including OPcache invalidation and file permission fixes.
#
# Usage:
#   ./deploy.sh <plugin-name>           Deploy a plugin
#   ./deploy.sh <plugin-name> --force   Skip uncommitted changes warning
#   ./deploy.sh theme                   Deploy the theme
#
# Examples:
#   ./deploy.sh mls-listings-display
#   ./deploy.sh bmn-schools
#   ./deploy.sh sn-appointment-booking
#   ./deploy.sh theme
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
FORCE_FLAG=$2

if [ -z "$COMPONENT" ]; then
    echo -e "${RED}Error: No component specified${NC}"
    echo ""
    echo "Usage: deploy.sh <plugin-name> [--force]"
    echo "       deploy.sh theme [--force]"
    echo ""
    echo "Available plugins:"
    ls -1 "$PLUGINS_LOCAL" 2>/dev/null | grep -v "^index.php$" | sed 's/^/  - /'
    echo ""
    echo "Theme: $THEME_NAME"
    exit 1
fi

# Determine if deploying theme or plugin
if [ "$COMPONENT" = "theme" ]; then
    LOCAL_PATH="$THEMES_LOCAL/$THEME_NAME"
    REMOTE_PATH="wp-content/themes/$THEME_NAME"
    COMPONENT_NAME="$THEME_NAME (theme)"
else
    LOCAL_PATH="$PLUGINS_LOCAL/$COMPONENT"
    REMOTE_PATH="wp-content/plugins/$COMPONENT"
    COMPONENT_NAME="$COMPONENT"
fi

# Verify component exists
if [ ! -d "$LOCAL_PATH" ]; then
    echo -e "${RED}Error: Component not found at $LOCAL_PATH${NC}"
    exit 1
fi

cd "$REPO_ROOT"

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  BMNBoston Deployment Script${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Component: ${GREEN}$COMPONENT_NAME${NC}"
echo -e "Source: $LOCAL_PATH"
echo ""

# Pre-deployment checks
echo -e "${YELLOW}=== Pre-deployment Checks ===${NC}"

# Check for uncommitted changes
UNCOMMITTED=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
if [ "$UNCOMMITTED" -gt 0 ]; then
    echo -e "${YELLOW}WARNING: You have $UNCOMMITTED uncommitted file(s)!${NC}"
    echo ""
    git status --short
    echo ""

    if [ "$FORCE_FLAG" != "--force" ]; then
        echo -e "${RED}Deployment blocked.${NC} Commit your changes first, or use --force to override."
        echo ""
        echo "  git add -A && git commit -m 'Your message' && git push"
        echo ""
        echo "Or run: ./deploy.sh $COMPONENT --force"
        exit 1
    else
        echo -e "${YELLOW}Proceeding anyway (--force flag used)${NC}"
    fi
else
    echo -e "${GREEN}No uncommitted changes. Good!${NC}"
fi

# Check version numbers match
if [ "$COMPONENT" != "theme" ]; then
    VERSION_JSON="$LOCAL_PATH/version.json"
    if [ -f "$VERSION_JSON" ]; then
        VERSION=$(python3 -c "import json; print(json.load(open('$VERSION_JSON'))['version'])" 2>/dev/null || echo "unknown")
        echo -e "Version from version.json: ${GREEN}$VERSION${NC}"
    fi
fi

echo ""

# Deploy to each server
for SERVER_NAME in "${!SERVERS[@]}"; do
    IFS='|' read -r USER_HOST PORT PASS <<< "${SERVERS[$SERVER_NAME]}"

    echo -e "${BLUE}=== Deploying to $SERVER_NAME (port $PORT) ===${NC}"

    # Step 1: Upload files
    echo -n "  Uploading files... "
    sshpass -p "$PASS" scp -P "$PORT" -r -q \
        "$LOCAL_PATH/" \
        "$USER_HOST:~/public/$REMOTE_PATH/" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 2: Clear OPcache (touch PHP files)
    echo -n "  Invalidating OPcache... "
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "find ~/public/$REMOTE_PATH -name '*.php' -exec touch {} \;" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 3: Fix file permissions for CSS/JS
    echo -n "  Fixing file permissions... "
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "find ~/public/$REMOTE_PATH -name '*.css' -exec chmod 644 {} \; 2>/dev/null; \
         find ~/public/$REMOTE_PATH -name '*.js' -exec chmod 644 {} \; 2>/dev/null; \
         find ~/public/$REMOTE_PATH -name '*.php' -exec chmod 644 {} \; 2>/dev/null" 2>/dev/null
    echo -e "${GREEN}done${NC}"

    # Step 4: Log deployment
    DEPLOY_LOG="Deploy: $COMPONENT v$VERSION at $(date '+%Y-%m-%d %H:%M:%S') by $(whoami)"
    sshpass -p "$PASS" ssh -p "$PORT" "$USER_HOST" \
        "echo '$DEPLOY_LOG' >> ~/public/deployment.log" 2>/dev/null || true

    echo ""
done

# Post-deployment verification
echo -e "${BLUE}=== Post-deployment Verification ===${NC}"

# Test API endpoint
echo -n "  Testing API (bmnboston.com)... "
RESPONSE=$(curl -s --max-time 10 "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" 2>/dev/null)
TOTAL=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total', 'error'))" 2>/dev/null || echo "error")

if [ "$TOTAL" != "error" ] && [ "$TOTAL" -gt 0 ]; then
    echo -e "${GREEN}OK ($TOTAL properties)${NC}"
else
    echo -e "${RED}FAILED${NC}"
    echo "  Response: $RESPONSE"
fi

# Test school filter (critical check)
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
echo -e "${GREEN}  Deployment Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Deployed $COMPONENT_NAME to:"
echo "  - bmnboston.com"
echo "  - steve-novak.com"
echo ""
echo -e "${YELLOW}Reminder:${NC} If you changed CSS/JS, make sure you bumped the version constant!"
echo "  - MLD_VERSION in mls-listings-display.php"
echo "  - SNAB_VERSION in sn-appointment-booking.php"
echo "  - BMN_SCHOOLS_VERSION in bmn-schools.php"
echo ""
