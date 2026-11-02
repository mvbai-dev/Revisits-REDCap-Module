#!/usr/bin/env bash
# Sicherheitstests für Revisits (siehe README "Sicherheit" und docs/Aenderungen.md, Abschnitt 11).
# Läuft gegen einen REDCap-TESTSERVER, nie gegen Produktion. Konfiguration per Umgebung
# (z. B. aus ~/.config/redcap-sectests/env), siehe tests/security/README.md.
# Exit-Code 0 = alle Fälle ok, 1 = mindestens ein Fehlschlag, 2 = Konfiguration fehlt.
set -u
: "${RC_BASE:?}" "${RC_VERSION:?}" "${RC_DB:?}" "${RT_PW_FILE:?}" "${RV_PID:?}"
PW=$(cat "$RT_PW_FILE")
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
EMB="$RC_BASE/$RC_VERSION/ExternalModules/"
pass=0; fail=0
chk() { if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1: erwartet $3, war $2"; fi; }

login() { # $1 Benutzer -> Cookie-Datei
  local j="$TMP/cj_$1"; rm -f "$j"
  curl -s -c "$j" -b "$j" "$RC_BASE/" -o "$TMP/l.html"
  local f fn fv; f=$(grep -o "name='redcap_login_[^']*' value='[^']*'" "$TMP/l.html" | head -1)
  fn=$(echo "$f" | sed "s/name='\([^']*\)'.*/\1/"); fv=$(echo "$f" | sed "s/.*value='\([^']*\)'/\1/")
  curl -s -c "$j" -b "$j" "$RC_BASE/" --data-urlencode "username=$1" --data-urlencode "password=$PW" -d submitted=1 --data-urlencode "$fn=$fv" -o /dev/null
  echo "$j"
}
classify() {
  python3 -c "
import sys;h=sys.stdin.read()
if \"id='username'\" in h: print('login')
elif 'Re-order all visits' in h: print('zugriff')
elif 'Numbers of reordered' in h or 'Data table used' in h: print('lauf')
elif 'permission' in h: print('verweigert')
else: print('?')"
}
page() { curl -s -b "$1" -c "$1" --get --data-urlencode "page=$2" "$EMB?prefix=revisits&pid=$RV_PID"; }

echo "== Rechte-Matrix (5 Schreibweisen von page, Menülink, POST)"
for spec in revtest_nodesign:verweigert revtest_dag:verweigert revtest_design:zugriff RevTest_Upper:zugriff; do
  u=${spec%%:*}; want=${spec#*:}; j=$(login "$u")
  for p in reorder reorder.php ./reorder .//reorder ../revisits_v1.0.0/reorder; do
    chk "$u page=$p" "$(page "$j" "$p" | classify)" "$want"
  done
  link=$(curl -s -b "$j" -c "$j" "$RC_BASE/$RC_VERSION/index.php?pid=$RV_PID" | grep -c 'prefix=revisits&amp;page=reorder\|prefix=revisits&page=reorder')
  [ "$want" = zugriff ] && chk "$u Menülink sichtbar" "$link" 1 || chk "$u Menülink versteckt" "$link" 0
  tok=$(page "$j" reorder | grep -o 'name="redcap_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
  [ -z "$tok" ] && tok=$(curl -s -b "$j" -c "$j" "$RC_BASE/$RC_VERSION/index.php?pid=$RV_PID" | grep -o "redcap_csrf_token = '[^']*'" | sed "s/.*'\(.*\)'/\1/")
  res=$(curl -s -b "$j" -c "$j" -X POST --data-urlencode "redcap_csrf_token=$tok" "$EMB?prefix=revisits&pid=$RV_PID&page=.%2Freorder" | classify)
  [ "$want" = zugriff ] && chk "$u POST ./reorder" "$res" lauf || chk "$u POST ./reorder" "$res" verweigert
done

echo "== XSS über die Einstellung (Wert wird danach wiederhergestellt)"
EM="(select external_module_id from redcap_external_modules where directory_prefix='revisits')"
Q="from redcap_external_module_settings where project_id=$RV_PID and \`key\`='visitdate-field' and external_module_id=$EM"
OLD=$($RC_DB -N -r -e "select value $Q")
$RC_DB -e "update redcap_external_module_settings set value='[\"visit_date\",\"<img src=x onerror=alert(1)>\"]' where project_id=$RV_PID and \`key\`='visitdate-field' and external_module_id=$EM"
j=$(login revtest_design); g=$(page "$j" reorder)
tok=$(echo "$g" | grep -o 'name="redcap_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
p=$(curl -s -b "$j" -c "$j" -X POST --data-urlencode "redcap_csrf_token=$tok" "$EMB?prefix=revisits&pid=$RV_PID&page=reorder")
chk "GET: Payload nicht roh"  "$(echo "$g" | grep -c '<img src=x onerror')" 0
chk "GET: Payload maskiert"   "$(echo "$g" | grep -c '&lt;img src=x onerror')" 1
chk "POST: Payload nicht roh" "$(echo "$p" | grep -c '<img src=x onerror')" 0
OLD_SQL=$(printf '%s' "$OLD" | sed "s/'/''/g")
$RC_DB -e "update redcap_external_module_settings set value='$OLD_SQL' where project_id=$RV_PID and \`key\`='visitdate-field' and external_module_id=$EM"
chk "Einstellung wiederhergestellt" "$($RC_DB -N -r -e "select value $Q")" "$OLD"

echo "== Ergebnis Revisits: $pass ok, $fail fehlgeschlagen"
[ "$fail" -eq 0 ]
