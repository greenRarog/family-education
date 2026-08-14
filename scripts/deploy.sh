#!/usr/bin/env bash

set -e

PROJECT_DIR="/var/www/family-education"
COMPOSE_FILE="docker-compose.stage.yml"

cd "$PROJECT_DIR"

echo "==> Updating source code"
git pull --ff-only origin main

echo "==> Building application"
docker compose -f "$COMPOSE_FILE" build --pull

echo "==> Starting containers"
docker compose -f "$COMPOSE_FILE" up -d

echo "==> Running migrations"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

echo "==> Optimizing Laravel"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize

echo "==> Deployment finished"
