#!/bin/bash

set -e

cd /var/www/family-education

docker run --rm \
  -v "$(pwd)/certbot/www:/var/www/certbot" \
  -v "$(pwd)/letsencrypt:/etc/letsencrypt" \
  certbot/certbot renew \
  --webroot \
  -w /var/www/certbot \
  --no-random-sleep-on-renew

docker compose -f docker-compose.stage.yml exec -T nginx nginx -s reload
