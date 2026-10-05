#!/bin/sh
# Source template only. Do not install automatically. Run on interactive logins.
case "$-" in *i*) ;; *) return 0 2>/dev/null || exit 0 ;; esac
if command -v timeout >/dev/null 2>&1 && command -v badpool-round-status >/dev/null 2>&1; then
    timeout 3 badpool-round-status --login 2>/dev/null || :
fi
