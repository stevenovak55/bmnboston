#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - Start Script
# ==============================================================================
# Usage: ./start-dev.sh [options]
# Options:
#   --rebuild    Rebuild containers before starting
#   --logs       Follow logs after starting
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
echo -e "${BLUE}  BMN Boston Development Environment   ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Parse arguments
REBUILD=false
FOLLOW_LOGS=false
for arg in "$@"; do
    case $arg in
        --rebuild)
            REBUILD=true
            ;;
        --logs)
            FOLLOW_LOGS=true
            ;;
    esac
done

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}Error: Docker is not running!${NC}"
    echo -e "${YELLOW}Please start Docker Desktop and try again.${NC}"
    exit 1
fi

echo -e "${GREEN}Docker is running${NC}"

# Navigate to docker directory
cd "$DOCKER_DIR"

# Rebuild if requested
if [ "$REBUILD" = true ]; then
    echo -e "${YELLOW}Rebuilding containers...${NC}"
    docker-compose build --no-cache
fi

# Start containers
echo -e "${YELLOW}Starting containers...${NC}"
docker-compose up -d

# Wait for services to be ready
echo -e "${YELLOW}Waiting for services to be ready...${NC}"
sleep 5

# Check if WordPress is accessible
echo -e "${YELLOW}Checking WordPress...${NC}"
MAX_ATTEMPTS=30
ATTEMPT=1
while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 | grep -q "200\|302"; then
        echo -e "${GREEN}WordPress is ready!${NC}"
        break
    fi
    echo -e "  Attempt $ATTEMPT/$MAX_ATTEMPTS - Waiting..."
    sleep 2
    ATTEMPT=$((ATTEMPT + 1))
done

if [ $ATTEMPT -gt $MAX_ATTEMPTS ]; then
    echo -e "${YELLOW}WordPress may still be initializing. Check logs with: docker-compose logs wordpress${NC}"
fi

# Print access URLs
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Development Environment Started!     ${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "  ${BLUE}WordPress:${NC}    http://localhost:8080"
echo -e "  ${BLUE}WordPress Admin:${NC} http://localhost:8080/wp-admin"
echo -e "  ${BLUE}phpMyAdmin:${NC}   http://localhost:8081"
echo -e "  ${BLUE}Mailhog:${NC}      http://localhost:8025"
echo ""
echo -e "  ${YELLOW}Database:${NC}"
echo -e "    Host: localhost:3306"
echo -e "    User: wordpress"
echo -e "    Password: wordpress_dev_password"
echo -e "    Database: wordpress"
echo ""

# Follow logs if requested
if [ "$FOLLOW_LOGS" = true ]; then
    echo -e "${YELLOW}Following logs (Ctrl+C to exit)...${NC}"
    docker-compose logs -f
fi
