#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - Database Export Script
# ==============================================================================
# Usage: ./export-db.sh [filename]
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

# Default filename with timestamp
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FILENAME="${1:-backup_$TIMESTAMP.sql}"
OUTPUT_PATH="$DB_DIR/seeds/$FILENAME"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Database Export                      ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Navigate to docker directory
cd "$DOCKER_DIR"

# Check if containers are running
if ! docker-compose ps | grep -q "bmnboston_mysql.*Up"; then
    echo -e "${RED}Error: Database container is not running!${NC}"
    echo -e "${YELLOW}Run ./start-dev.sh first.${NC}"
    exit 1
fi

# Create output directory if it doesn't exist
mkdir -p "$DB_DIR/seeds"

echo -e "${YELLOW}Exporting database to $OUTPUT_PATH...${NC}"

# Export database
docker-compose exec -T db mysqldump \
    -u wordpress \
    -pwordpress_dev_password \
    --single-transaction \
    --routines \
    --triggers \
    wordpress > "$OUTPUT_PATH"

# Get file size
FILE_SIZE=$(ls -lh "$OUTPUT_PATH" | awk '{print $5}')

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Export Complete                      ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "  ${BLUE}File:${NC} $OUTPUT_PATH"
echo -e "  ${BLUE}Size:${NC} $FILE_SIZE"
echo ""
