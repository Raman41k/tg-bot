#!/bin/bash

PORT=8888
ngrok http $PORT > /dev/null 2>&1 &

sleep 5

NGROK_URL=$(curl -s http://127.0.0.1:4040/api/tunnels | sed -n 's/.*"public_url":"\([^"]*\)".*/\1/p' | head -n 1)

if [ -z "$NGROK_URL" ]; then
  echo "Failed to get ngrok URL"
  exit 1
fi

echo "Ngrok URL: $NGROK_URL"

if grep -q '^APP_URL=' .env; then
  sed -i.bak "s|^APP_URL=.*|APP_URL=${NGROK_URL}|" .env
else
  echo "APP_URL=${NGROK_URL}" >> .env
fi

echo ".env updated with APP_URL=${NGROK_URL}"
