#!/bin/bash
set -e

WALLET_DIR="/tmp/wallet"
mkdir -p "$WALLET_DIR"

echo "=== INICIANDO DECODIFICACIÓN DE WALLET ==="

if [ -n "$WALLET_CWALLET_B64" ]; then
  echo "$WALLET_CWALLET_B64" | base64 -d > "$WALLET_DIR/cwallet.sso"
  echo "✅ cwallet.sso: $(wc -c < $WALLET_DIR/cwallet.sso) bytes"
else
  echo "❌ WALLET_CWALLET_B64 no definido"
fi

if [ -n "$WALLET_EWALLET_B64" ]; then
  echo "$WALLET_EWALLET_B64" | base64 -d > "$WALLET_DIR/ewallet.p12"
  echo "✅ ewallet.p12: $(wc -c < $WALLET_DIR/ewallet.p12) bytes"
else
  echo "❌ WALLET_EWALLET_B64 no definido"
fi

if [ -n "$WALLET_SQLNET_B64" ]; then
  echo "$WALLET_SQLNET_B64" | base64 -d > "$WALLET_DIR/sqlnet.ora"
  echo "✅ sqlnet.ora: $(wc -c < $WALLET_DIR/sqlnet.ora) bytes"
  cat "$WALLET_DIR/sqlnet.ora"
else
  echo "❌ WALLET_SQLNET_B64 no definido"
fi

if [ -n "$WALLET_TNSNAMES_B64" ]; then
  echo "$WALLET_TNSNAMES_B64" | base64 -d > "$WALLET_DIR/tnsnames.ora"
  echo "✅ tnsnames.ora: $(wc -c < $WALLET_DIR/tnsnames.ora) bytes"
else
  echo "❌ WALLET_TNSNAMES_B64 no definido"
fi

chmod 600 "$WALLET_DIR"/* 2>/dev/null || true

echo "=== WALLET LISTO ==="

PORT=${PORT:-8080}
exec php -S "0.0.0.0:$PORT" -t /app /app/router.php
