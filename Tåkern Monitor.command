#!/bin/bash
# Öppnar Tåkern Monitor i Chrome kiosk-läge
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --kiosk \
  --user-data-dir=/tmp/takern-kiosk \
  "https://pierrea.se/takern/monitor.html"
