#!/usr/bin/env bash
# Findet echte Netzdetails (IPv4-Adressen, Tailscale-MagicDNS) in Text von stdin.
# Erlaubt: 127.0.0.1, 0.0.0.0 und die Dokumentations-Netze 192.0.2.x, 198.51.100.x, 203.0.113.x.
# Ausgabe: gefundene Zeilen; Exit 1 bei Treffern.
hits=$(grep -nE '(^|[^0-9.])([0-9]{1,3}\.){3}[0-9]{1,3}([^0-9.]|$)|\.ts\.net\b' \
  | grep -vE '(^|[^0-9.])(127\.0\.0\.1|0\.0\.0\.0|192\.0\.2\.[0-9]{1,3}|198\.51\.100\.[0-9]{1,3}|203\.0\.113\.[0-9]{1,3})([^0-9.]|$)' )
[ -z "$hits" ] && exit 0
printf '%s\n' "$hits"
exit 1
