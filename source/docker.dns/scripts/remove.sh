#!/bin/bash
set -u

rm -f /boot/config/plugins/docker.dns/docker.dns.cron
/usr/local/sbin/update_cron >/dev/null 2>&1 || true
