#!/usr/bin/env bash
# Rebuild the committed PNG from its editable SVG source (ImageMagick required).
set -euo pipefail
ROOT="$(dirname "$(dirname "$(realpath "${BASH_SOURCE[0]}")")")"
convert -background none -density 384 "${ROOT}/assets/branding/skeleton-logo.svg" \
    -resize 512x512 -strip "PNG32:${ROOT}/public/skeleton-logo.png"
