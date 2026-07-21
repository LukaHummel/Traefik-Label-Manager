#!/bin/bash
set -u

config_dir="/boot/config/plugins/docker.dns"
rm -f "$config_dir/docker.dns.cron" \
  "$config_dir/config.json" \
  "$config_dir/secrets.json" \
  "$config_dir/overrides.json" \
  "$config_dir/state.json"
/usr/local/sbin/update_cron >/dev/null 2>&1 || true
