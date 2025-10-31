#!/bin/bash
#
# check-sleep-blockers.sh
# Affiche en clair les processus qui empêchent la mise en veille sur macOS
#

BLOCKERS=$(pmset -g assertions | awk '
  /PreventUserIdleSystemSleep|PreventSystemSleep|PreventUserIdleDisplaySleep/ {
    in_block=1
  }
  in_block && /pid/ {
    print $0
  }')

if [ -z "$BLOCKERS" ]; then
  echo "✅ Aucun processus ne bloque la mise en veille."
else
  echo "🚫 Processus qui empêchent la mise en veille :"
  echo "$BLOCKERS" | while read -r line; do
    pid=$(echo "$line" | awk '{print $2}' | sed "s/(\(.*\)):/\1/")
    proc=$(echo "$line" | awk '{print $2}' | sed "s/.*(\(.*\)).*/\1/")
    reason=$(echo "$line" | cut -d '"' -f2)
    echo "- $proc (pid $pid) → $reason"
  done
fi
