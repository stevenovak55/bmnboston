#!/bin/bash
# ==============================================================================
# BMN Boston Development Environment - View Logs Script
# ==============================================================================
# Usage: ./logs.sh [service]
# Services: wordpress, db, phpmyadmin, mailhog, all (default)
# ==============================================================================

# Colors for output
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
DOCKER_DIR="$PROJECT_ROOT/wordpress/docker"

# Navigate to docker directory
cd "$DOCKER_DIR"

SERVICE="${1:-all}"

echo -e "${BLUE}Showing logs for: $SERVICE${NC}"
echo -e "${BLUE}Press Ctrl+C to exit${NC}"
echo ""

case $SERVICE in
    wordpress)
        docker-compose logs -f wordpress
        ;;
    db|mysql)
        docker-compose logs -f db
        ;;
    phpmyadmin)
        docker-compose logs -f phpmyadmin
        ;;
    mailhog)
        docker-compose logs -f mailhog
        ;;
    all)
        docker-compose logs -f
        ;;
    *)
        echo "Unknown service: $SERVICE"
        echo "Available services: wordpress, db, phpmyadmin, mailhog, all"
        exit 1
        ;;
esac
