#!/bin/bash
# 日本旅遊景點導覽 — Deploy Script
# Deploys to XServer via rsync over SSH（ak-office.org と同じ鍵認証／パスワード不要）

SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)/"
DEST_USER="arty4u"
DEST_HOST="ak-office.org"
DEST_DIR="/home/arty4u/arty4u.com/public_html/app/spot/"
SSH_PORT="10022"

echo "🚀 Deploying spot guide to $DEST_HOST..."

rsync -avz \
  --delete \
  --exclude='.DS_Store' \
  --exclude='.claude' \
  --exclude='deploy.sh' \
  --exclude='README.md' \
  --exclude='DESIGN.md' \
  --exclude='_data' \
  -e "ssh -p $SSH_PORT -o StrictHostKeyChecking=accept-new" \
  "$SOURCE_DIR" "$DEST_USER@$DEST_HOST:$DEST_DIR"

if [ $? -eq 0 ]; then
  echo "✅ Deploy completed successfully!"
  echo "📍 Live at: https://arty4u.com/app/spot/"
else
  echo "❌ Deploy failed!"
  exit 1
fi
