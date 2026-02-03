#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - Database Reset Script
# ==============================================================================
# Usage: ./reset-db.sh [options]
# Options:
#   --import FILE    Import SQL file after reset
#   --keep-users     Keep WordPress users table
# ==============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
DOCKER_DIR="$PROJECT_ROOT/wordpress/docker"
DB_DIR="$PROJECT_ROOT/wordpress/database"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Database Reset                       ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Parse arguments
IMPORT_FILE=""
KEEP_USERS=false
for arg in "$@"; do
    case $arg in
        --import)
            shift
            IMPORT_FILE="$1"
            ;;
        --keep-users)
            KEEP_USERS=true
            ;;
    esac
done

# Navigate to docker directory
cd "$DOCKER_DIR"

# Check if containers are running
if ! docker-compose ps | grep -q "bmnboston_mysql.*Up"; then
    echo -e "${RED}Error: Database container is not running!${NC}"
    echo -e "${YELLOW}Run ./start-dev.sh first.${NC}"
    exit 1
fi

echo -e "${RED}WARNING: This will reset the WordPress database!${NC}"
read -p "Are you sure? (y/N): " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo -e "${YELLOW}Cancelled.${NC}"
    exit 0
fi

echo -e "${YELLOW}Resetting database...${NC}"

# Drop and recreate database
docker-compose exec -T db mysql -u root -proot_dev_password -e "
    DROP DATABASE IF EXISTS wordpress;
    CREATE DATABASE wordpress;
    GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'%';
    FLUSH PRIVILEGES;
"

echo -e "${GREEN}Database reset complete.${NC}"

# Import SQL file if specified
if [ -n "$IMPORT_FILE" ] && [ -f "$IMPORT_FILE" ]; then
    echo -e "${YELLOW}Importing $IMPORT_FILE...${NC}"
    docker-compose exec -T db mysql -u wordpress -pwordpress_dev_password wordpress < "$IMPORT_FILE"
    echo -e "${GREEN}Import complete.${NC}"
fi

echo ""
echo -e "${YELLOW}Note: You may need to run the WordPress installer again.${NC}"
echo -e "Visit ${BLUE}http://localhost:8080${NC} to set up WordPress."
