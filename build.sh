#!/bin/sh
# Package the plugin for installation. Mirrors eplatforms-consent's build.
set -e
NAME=eplatforms-sar
VER=$(grep -m1 "Version:" ${NAME}.php | sed 's/.*Version:[[:space:]]*//')
OUT="dist/${NAME}-${VER}.zip"
rm -rf build dist
mkdir -p build/${NAME} dist
cp -R ${NAME}.php uninstall.php includes README.md build/${NAME}/
( cd build && zip -qr "../${OUT}" ${NAME} )
rm -rf build
echo "${OUT}"
