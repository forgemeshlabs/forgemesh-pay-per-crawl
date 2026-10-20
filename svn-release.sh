#!/usr/bin/env bash
# Push the current plugin version to wordpress.org SVN (trunk + tag + assets).
# Usage: ./svn-release.sh            (reads version from the plugin header, prompts for the SVN password)
# SVN password: https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password
set -euo pipefail
cd "$(dirname "$0")"
VER=$(grep -m1 -E '^\s*\*\s*Version:' forgemesh-pay-per-crawl.php | awk '{print $NF}')
WC=${WC:-$HOME/dev/wp-svn-work/wp-svn}
[ -d "$WC/.svn" ] || svn co https://plugins.svn.wordpress.org/forgemesh-pay-per-crawl "$WC"
svn up -q "$WC"
rsync -a --delete --exclude-from=.distignore ./ "$WC/trunk/"
[ -d docs/wporg-assets ] && rsync -a docs/wporg-assets/ "$WC/assets/"
cd "$WC"
svn add --force . -q
svn status | awk '/^!/{print $2}' | xargs -r svn rm -q
[ -d "tags/$VER" ] || svn cp trunk "tags/$VER" -q
svn status
echo "Committing $VER as forgemeshlabs ..."
svn ci -m "$VER" --username forgemeshlabs "${@}"
