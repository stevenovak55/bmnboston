#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - Database Import Script
# ==============================================================================
# Usage: ./import-db.sh <filename>
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
echo -e "${BLUE}  Database Import                      ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Check for filename argument
if [ -z "$1" ]; then
    echo -e "${RED}Error: No filename specified!${NC}"
    echo -e "Usage: $0 <filename>"
    echo ""
    echo -e "Available files in $DB_DIR/seeds/:"
    ls -la "$DB_DIR/seeds/" 2>/dev/null || echo "  (no files found)"
    exit 1
fi

IMPORT_FILE="$1"

# Check if file exists (try as-is first, then in seeds directory)
if [ -f "$IMPORT_FILE" ]; then
    FILE_PATH="$IMPORT_FILE"
elif [ -f "$DB_DIR/seeds/$IMPORT_FILE" ]; then
    FILE_PATH="$DB_DIR/seeds/$IMPORT_FILE"
else
    echo -e "${RED}Error: File not found: $IMPORT_FILE${NC}"
    exit 1
fi

# Navigate to docker directory
cd "$DOCKER_DIR"

# Check if containers are running
if ! docker-compose ps | grep -q "bmnboston_mysql.*Up"; then
    echo -e "${RED}Error: Database container is not running!${NC}"
    echo -e "${YELLOW}Run ./start-dev.sh first.${NC}"
    exit 1
fi

echo -e "${YELLOW}Importing $FILE_PATH...${NC}"
echo -e "${RED}WARNING: This will overwrite existing data!${NC}"
read -p "Continue? (y/N): " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo -e "${YELLOW}Cancelled.${NC}"
    exit 0
fi

# Import database
docker-compose exec -T db mysql \
    -u wordpress \
    -pwordpress_dev_password \
    wordpress < "$FILE_PATH"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Import Complete                      ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Note: You may need to update site URLs if importing from production.${NC}"
