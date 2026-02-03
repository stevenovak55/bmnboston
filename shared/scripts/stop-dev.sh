#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - Stop Script
# ==============================================================================
# Usage: ./stop-dev.sh [options]
# Options:
#   --clean    Remove volumes (WARNING: deletes all data)
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

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Stopping Development Environment     ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Parse arguments
CLEAN=false
for arg in "$@"; do
    case $arg in
        --clean)
            CLEAN=true
            ;;
    esac
done

# Navigate to docker directory
cd "$DOCKER_DIR"

if [ "$CLEAN" = true ]; then
    echo -e "${RED}WARNING: This will delete all WordPress data and database!${NC}"
    read -p "Are you sure? (y/N): " confirm
    if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
        echo -e "${YELLOW}Stopping and removing containers, networks, and volumes...${NC}"
        docker-compose down -v --remove-orphans
        echo -e "${GREEN}All containers and data removed.${NC}"
    else
        echo -e "${YELLOW}Cancelled.${NC}"
        exit 0
    fi
else
    echo -e "${YELLOW}Stopping containers...${NC}"
    docker-compose down
    echo -e "${GREEN}Containers stopped. Data preserved in volumes.${NC}"
fi

echo ""
echo -e "${GREEN}Development environment stopped.${NC}"
echo -e "Run ${BLUE}./start-dev.sh${NC} to start again."
