#!/usr/bin/env bash
# Create or update the A record soa.duyet.vn → VPS_IP on Cloudflare.
# Requires:
#   CF_API_TOKEN  — scoped Zone:DNS:Edit on the duyet.vn zone   (preferred)
#                OR
#   CF_EMAIL + CF_API_KEY  — Cloudflare global API key fallback
#   VPS_IP        — origin IP to publish
#   ZONE_NAME     — defaults to "duyet.vn"
#   RECORD_NAME   — defaults to "soa.duyet.vn"
#   PROXIED       — "true" (default) or "false"
set -euo pipefail

: "${VPS_IP:?VPS_IP is required}"
ZONE_NAME="${ZONE_NAME:-duyet.vn}"
RECORD_NAME="${RECORD_NAME:-soa.duyet.vn}"
PROXIED="${PROXIED:-true}"

API='https://api.cloudflare.com/client/v4'
auth_header_args=()
if [[ -n "${CF_API_TOKEN:-}" ]]; then
  auth_header_args+=( -H "Authorization: Bearer ${CF_API_TOKEN}" )
elif [[ -n "${CF_EMAIL:-}" && -n "${CF_API_KEY:-}" ]]; then
  auth_header_args+=( -H "X-Auth-Email: ${CF_EMAIL}" -H "X-Auth-Key: ${CF_API_KEY}" )
else
  echo "FATAL: provide CF_API_TOKEN OR (CF_EMAIL + CF_API_KEY)" >&2
  exit 1
fi

echo "[cf] resolving zone id for $ZONE_NAME"
ZONE_ID=$(curl -sf "${auth_header_args[@]}" "$API/zones?name=$ZONE_NAME" | jq -r '.result[0].id')
if [[ -z "$ZONE_ID" || "$ZONE_ID" == "null" ]]; then
  echo "FATAL: cannot resolve zone $ZONE_NAME" >&2; exit 2
fi

echo "[cf] checking existing record $RECORD_NAME"
RECORD_ID=$(curl -sf "${auth_header_args[@]}" \
  "$API/zones/$ZONE_ID/dns_records?type=A&name=$RECORD_NAME" \
  | jq -r '.result[0].id // empty')

PAYLOAD=$(jq -nc --arg type A --arg name "$RECORD_NAME" --arg content "$VPS_IP" --argjson proxied "$PROXIED" \
  '{type:$type, name:$name, content:$content, ttl:1, proxied:$proxied}')

if [[ -z "$RECORD_ID" ]]; then
  echo "[cf] creating new A record → $VPS_IP"
  curl -sf -X POST "${auth_header_args[@]}" \
    -H 'Content-Type: application/json' \
    -d "$PAYLOAD" \
    "$API/zones/$ZONE_ID/dns_records" | jq '.result | {name, content, proxied}'
else
  echo "[cf] updating record $RECORD_ID → $VPS_IP"
  curl -sf -X PUT "${auth_header_args[@]}" \
    -H 'Content-Type: application/json' \
    -d "$PAYLOAD" \
    "$API/zones/$ZONE_ID/dns_records/$RECORD_ID" | jq '.result | {name, content, proxied}'
fi

echo "[cf] done."
