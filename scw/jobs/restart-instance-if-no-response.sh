#!/bin/bash
set -euo pipefail

# ==============================
# Variables principales
# ==============================
SERVER_IP="${SERVER_IP}"
INSTANCE_UUID="${INSTANCE_UUID}"
ZONE="${ZONE}"
TOKEN="${SCW_SECRET_KEY}"

MAX_RETRIES=5
DELAY=5

# ==============================
# Variables Mailjet
# ==============================
MJ_APIKEY_PUBLIC="${MJ_APIKEY_PUBLIC}"
MJ_APIKEY_PRIVATE="${MJ_APIKEY_PRIVATE}"
MJ_SENDER_EMAIL="${MJ_SENDER_EMAIL}"
MJ_RECIPIENT_EMAIL="${MJ_RECIPIENT_EMAIL}"

# ==============================
# Vérifications
# ==============================
if [ -z "$TOKEN" ]; then
  echo "❌ Erreur : variable d'environnement SCW_SECRET_KEY manquante."
  exit 1
fi

# ==============================
# Fonction d’envoi d’alerte Mailjet
# ==============================
send_mailjet_alert() {
    local subject="$1"
    local text="$2"

    if [ -z "$MJ_APIKEY_PUBLIC" ] || [ -z "$MJ_APIKEY_PRIVATE" ] || [ -z "$MJ_SENDER_EMAIL" ] || [ -z "$MJ_RECIPIENT_EMAIL" ]; then
        echo "⚠️ Mailjet non configuré (aucun mail envoyé)."
        return
    fi

    payload=$(jq -nc \
      --arg fromEmail "$MJ_SENDER_EMAIL" \
      --arg toEmail "$MJ_RECIPIENT_EMAIL" \
      --arg subject "$subject" \
      --arg text "$text" \
      '{
        Messages: [
          {
            From: { Email: $fromEmail, Name: "Ping-Reboot Bot" },
            To: [ { Email: $toEmail, Name: "Supervisor" } ],
            Subject: $subject,
            TextPart: $text
          }
        ]
      }'
    )

    echo "📧 Envoi de l’alerte Mailjet..."
    resp=$(curl -s -X POST \
        --user "$MJ_APIKEY_PUBLIC:$MJ_APIKEY_PRIVATE" \
        -H "Content-Type: application/json" \
        -d "$payload" \
        https://api.mailjet.com/v3.1/send)

    echo "📨 Réponse Mailjet : $resp"
}

# ==============================
# Test de connectivité
# ==============================
echo "➡️ Test de connectivité vers $SERVER_IP (max $MAX_RETRIES tentatives)..."

for i in $(seq 1 $MAX_RETRIES); do
    if ping -c 3 -W 2 "$SERVER_IP" > /dev/null 2>&1; then
        echo "✅ Serveur joignable (tentative $i/$MAX_RETRIES)."
        exit 0
    else
        echo "⚠️ Tentative $i/$MAX_RETRIES échouée. Nouvelle tentative dans $DELAY secondes..."
        sleep $DELAY
    fi
done

# ==============================
# Reboot Scaleway si KO
# ==============================
echo "Serveur injoignable après $MAX_RETRIES tentatives, redémarrage en cours..."

response=$(curl -s -X POST \
  -H "X-Auth-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"reboot"}' \
  "https://api.scaleway.com/instance/v1/zones/$ZONE/servers/$INSTANCE_UUID/action")

echo "$response" | jq .

# ==============================
# Envoi de l’alerte Mailjet
# ==============================
send_mailjet_alert \
  "⚠️ [ALERTE] Reboot déclenché sur $INSTANCE_UUID" \
  "❌ Le serveur $SERVER_IP est injoignable après $MAX_RETRIES tentatives. Un reboot a été lancé."
