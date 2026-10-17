#!/usr/bin/env bash
# Build the distributable zip: forgemesh-pay-per-crawl-<version>.zip (plugin files only).
set -euo pipefail
cd "$(dirname "$0")"
V=$(grep -m1 "Version:" forgemesh-pay-per-crawl.php | awk '{print $3}')
OUT="$PWD/forgemesh-pay-per-crawl-$V.zip"
rm -rf /tmp/fmppc-build && mkdir -p /tmp/fmppc-build/forgemesh-pay-per-crawl
cp -r forgemesh-pay-per-crawl.php uninstall.php readme.txt LICENSE includes admin assets /tmp/fmppc-build/forgemesh-pay-per-crawl/
rm -f "$OUT"
(cd /tmp/fmppc-build && zip -qr "$OUT" forgemesh-pay-per-crawl)
echo "forgemesh-pay-per-crawl-$V.zip"; unzip -l "$OUT" | tail -1
