#!/bin/bash
#
# Regenerate the shib.php front controller from the LimeSurvey index.php.
#
# The Shibboleth SP protects a specific URL path, so the front controller has
# to be reachable under that path. The standard trick is to keep a copy of
# index.php named shib.php next to it.
#
# The copy goes stale every time LimeSurvey updates index.php, which breaks
# AAI login in a way that looks like a server problem. Run this script after
# every LimeSurvey upgrade.
#
# Usage:
#   ./sync-shib.sh /var/www/html/6          # LS6 installed in /6
#   ./sync-shib.sh /var/www/html            # LS at the document root
#
set -euo pipefail

LS_DIR="${1:-}"
if [ -z "$LS_DIR" ]; then
    echo "usage: $0 <limesurvey-directory>" >&2
    exit 1
fi

if [ ! -f "$LS_DIR/index.php" ]; then
    echo "error: $LS_DIR/index.php not found" >&2
    exit 1
fi

SRC="$LS_DIR/index.php"
DST="$LS_DIR/shib.php"

# Compare the relevant constants rather than the whole file: setdebug.php and
# the vendor path can legitimately differ between the two copies.
extract_const() {
    sed -n "s/^define('$2'.*=> *'\([^']*\)'.*/\1/p" "$1" | head -1
}

SRC_SYSTEM=$(extract_const "$SRC" 'BASEPATH')
DST_SYSTEM=$(extract_const "$DST" 'BASEPATH' 2>/dev/null || true)

if [ -f "$DST" ]; then
    if cmp -s "$SRC" "$DST"; then
        echo "shib.php is already up to date with index.php."
        exit 0
    fi
    echo "shib.php differs from index.php - regenerating."
    # Keep a backup of the previous copy for inspection.
    cp -p "$DST" "$DST.bak"
    echo "  previous copy saved to $DST.bak"
else
    echo "shib.php not found - creating it from index.php."
fi

cp -p "$SRC" "$DST"
chmod --reference="$SRC" "$DST" 2>/dev/null || chmod 644 "$DST"

echo
echo "Created $DST"
echo "  source index.php : $SRC"
echo "  size             : $(wc -c < "$DST") bytes"
echo
echo "Reminder: the Apache location protected by the Shibboleth SP must point"
echo "at this file, and the AAI attributes must be exported for that path."
echo "Deploy tests/probe-aai.php to the web root and request it to confirm the"
echo "attributes reach PHP."
