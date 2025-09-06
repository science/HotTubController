#!/bin/bash

# Hot Tub Controller PHP Proxy - Development Server
# Usage: ./start-dev-server.sh [port]

PORT=${1:-8080}
HOST="localhost"

echo "🚀 Starting Hot Tub Controller PHP Proxy Development Server"
echo "📍 URL: http://${HOST}:${PORT}"
echo "📁 Document Root: $(pwd)/public"
echo "🔧 Environment: $(grep APP_ENV .env | cut -d'=' -f2)"
echo ""
echo "💡 Press Ctrl+C to stop the server"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Start PHP built-in server
php -S "${HOST}:${PORT}" -t public