# Deploy assets

These files configure the VPS host (outside Docker) to terminate Cloudflare-origin TLS and reverse-proxy to the Docker stack. The configuration **drops non-Cloudflare traffic** at the nginx layer so the origin is never exposed directly.

## One-time setup on VPS

```bash
# 1. Cloudflare Origin Certificate
#    SSL/TLS → Origin Server → Create Certificate (RSA 2048, 15 years)
sudo mkdir -p /etc/ssl/cloudflare
sudo nano /etc/ssl/cloudflare/soa.duyet.vn.pem   # paste origin cert
sudo nano /etc/ssl/cloudflare/soa.duyet.vn.key   # paste origin key
sudo chmod 600 /etc/ssl/cloudflare/*

# 2. Install nginx config (3 files)
sudo cp deploy/nginx-soa.duyet.vn.conf /etc/nginx/sites-available/
sudo cp deploy/cloudflare-ips.conf     /etc/nginx/cloudflare-ips.conf
sudo cp deploy/cloudflare-geo.conf     /etc/nginx/conf.d/00-cloudflare-geo.conf

sudo ln -sf /etc/nginx/sites-available/nginx-soa.duyet.vn.conf \
            /etc/nginx/sites-enabled/soa.duyet.vn

sudo nginx -t && sudo systemctl reload nginx
```

## What each file does

| File | Context | Purpose |
|---|---|---|
| `cloudflare-ips.conf` | `server { }` | `set_real_ip_from` — tells realip module which TCP peers are trusted edges (Cloudflare). |
| `cloudflare-geo.conf` | `http { }` (via `conf.d`) | Geo block setting `$cf_allowed = 1` when the original TCP peer is in a CF range or loopback. |
| `nginx-soa.duyet.vn.conf` | `sites-available` | TLS server. Uses `$cf_allowed` to reject (`return 444`) any connection not from Cloudflare. |

## Verify

```bash
curl -sI https://soa.duyet.vn               # 200 OK (via Cloudflare)
curl -s  https://soa.duyet.vn/api/health    # {"status":"ok",...}

# Direct origin test — must NOT respond (connection closed):
curl -k --resolve soa.duyet.vn:443:<VPS_IP> https://soa.duyet.vn -I
# expect: curl: (52) Empty reply from server
```

## Cloudflare dashboard

- SSL mode: **Full (strict)** (matches our origin cert).
- DNS: A record `soa.duyet.vn` → VPS IP, **Proxied** (orange cloud).
- Optional hardening: enable **Authenticated Origin Pulls** for even tighter origin protection.

## When Cloudflare IP ranges change

Refresh both files from https://www.cloudflare.com/ips-v4 and `/ips-v6`, then run:
```bash
sudo nginx -t && sudo systemctl reload nginx
```
