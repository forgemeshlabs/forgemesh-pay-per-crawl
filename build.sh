#!/usr/bin/env bash
# Build the distributable zip: forgemesh-pay-per-crawl-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"
V=$(grep -m1 "Version:" forgemesh-pay-per-crawl.php | awk '{print $3}')
rm -rf /tmp/fmppc-build && mkdir -p /tmp/fmppc-build/forgemesh-pay-per-crawl
rsync -a --exclude-from=.distignore ./ /tmp/fmppc-build/forgemesh-pay-per-crawl/
(cd /tmp/fmppc-build && zip -qr "$OLDPWD/forgemesh-pay-per-crawl-$V.zip" forgemesh-pay-per-crawl)
echo "forgemesh-pay-per-crawl-$V.zip"; unzip -l "forgemesh-pay-per-crawl-$V.zip" | tail -1
