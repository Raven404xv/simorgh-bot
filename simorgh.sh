#!/usr/bin/env bash
# =====================================================================
#  Simorgh Bot — Server Manager
#  Command : simorgh   (also: simorgh-bot)
#  Repo    : https://github.com/Raven404xv/simorgh-bot
#  Channel : https://t.me/SimorghFree
#  Group   : https://t.me/SimorghBotGroup
# =====================================================================

set -u
set -o pipefail

# ---------- Configuration ----------
REPO_URL="https://github.com/Raven404xv/simorgh-bot.git"
RAW_BASE="https://raw.githubusercontent.com/Raven404xv/simorgh-bot/main"
COMMAND_PATH="/usr/local/bin/simorgh"
ALIAS_PATH="/usr/local/bin/simorgh-bot"
INSTALL_DIR="/var/www/simorgh-bot"
VHOST_NAME="simorgh-bot"
VHOST_FILE="/etc/apache2/sites-available/${VHOST_NAME}.conf"
PORTS_CONF="/etc/apache2/ports.conf"
PORTS_BACKUP="/etc/apache2/ports.conf.simorgh-orig"
DEFAULT_SITE_STATE="/etc/simorgh/default-site.state"
SELF_SIGNED_DIR="/etc/simorgh/ssl"
DB_ENV="/etc/simorgh/db.env"
POLICY_FILE="/usr/sbin/policy-rc.d"
CF_CREDS="/root/.secrets/simorgh-cloudflare.ini"
DEPLOY_HOOK="/etc/letsencrypt/renewal-hooks/deploy/simorgh-reload-apache.sh"
BACKUP_DIR="/root/simorgh-backups"
INFO_FILE="/root/simorgh-install-info.txt"
CREDENTIALS_FILE="/root/simorgh-credentials.txt"
LOG_FILE="/var/log/simorgh-manager.log"
CHANNEL_URL="https://t.me/SimorghFree"
GROUP_URL="https://t.me/SimorghBotGroup"
TELEGRAM_PORTS="80 88 443 8443"
REMOTE_VERSION=""

# Installation choices
DOMAIN=""          # empty when the bot is hosted on the server IP
EMAIL=""
PUBLIC_HOST=""     # domain, or the server's public IP
HTTPS_PORT=""      # 443 or 8443
CERT_METHOD=""     # selfsigned | http01 | cloudflare | manual
CERT_FILE=""
KEY_FILE=""
HEALTH_OK=true

C_RED='\033[0;31m'; C_GREEN='\033[0;32m'; C_YELLOW='\033[1;33m'
C_CYAN='\033[0;36m'; C_MAGENTA='\033[0;35m'; C_BOLD='\033[1m'; C_RESET='\033[0m'

# ---------- Logging and output ----------
log() {
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null
  echo "$(date '+%F %T') $*" >> "$LOG_FILE" 2>/dev/null
}

ok()    { echo -e "  ${C_GREEN}✔${C_RESET} $*" >&2; log "OK    $*"; }
warn()  { echo -e "  ${C_YELLOW}!${C_RESET} $*" >&2; log "WARN  $*"; }
err()   { echo -e "  ${C_RED}✘${C_RESET} $*" >&2; log "ERROR $*"; }
info()  { echo -e "  ${C_CYAN}›${C_RESET} $*" >&2; log "INFO  $*"; }
say()   { echo -e "  $*"; }
step()  { echo; echo -e "  ${C_BOLD}${C_CYAN}$*${C_RESET}"; echo; }
tip()   { echo -e "  ${C_MAGENTA}$*${C_RESET}"; }
pause() { echo; read -r -p "  Press Enter to return to the menu..." _; }

# y / n, no default
ask_yes_no() {
  local answer
  while true; do
    read -r -p "  $1 [y/n]: " answer
    case "${answer,,}" in
      y|yes) return 0 ;;
      n|no)  return 1 ;;
      *) warn "Please type y or n." ;;
    esac
  done
}

# Enter = yes, n = no
ask_enter_yes() {
  local answer
  while true; do
    read -r -p "  $1 [Enter = yes / n = no]: " answer
    case "${answer,,}" in
      ""|y|yes) return 0 ;;
      n|no)     return 1 ;;
      *) warn "Press Enter for yes, or type n for no." ;;
    esac
  done
}

# ---------- Checks ----------
require_apt() {
  if ! command -v apt-get >/dev/null 2>&1; then
    err "This manager supports Ubuntu and Debian only."
    exit 1
  fi
}

# ---------- Self-install as the 'simorgh' command ----------
install_self() {
  local self
  self=$(readlink -f "$0" 2>/dev/null)
  if [[ "$self" != "$COMMAND_PATH" ]]; then
    info "Installing the 'simorgh' command..."
    if curl -fsSL --max-time 30 "${RAW_BASE}/simorgh.sh" -o "$COMMAND_PATH" 2>/dev/null; then
      chmod +x "$COMMAND_PATH"
      ln -sf "$COMMAND_PATH" "$ALIAS_PATH"
      ok "Done. From now on, just type:  simorgh"
      exec "$COMMAND_PATH"
    else
      warn "Could not install the 'simorgh' command. Check the internet connection."
    fi
  fi
}

# ---------- Version ----------
parse_version() {
  sed -n "s/^define('SB_VERSION', '\([^']*\)');.*/\1/p" | head -n 1
}

read_local_version() {
  [[ -f "$INSTALL_DIR/src/bootstrap.php" ]] || return 0
  parse_version < "$INSTALL_DIR/src/bootstrap.php"
}

fetch_remote_version() {
  curl -fsSL --max-time 15 "${RAW_BASE}/src/bootstrap.php" 2>/dev/null | parse_version
}

is_installed() {
  [[ -d "$INSTALL_DIR/.git" && -f "$INSTALL_DIR/src/bootstrap.php" ]]
}

# ---------- Validation ----------
valid_domain() {
  [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]
}

valid_email() {
  [[ "$1" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]
}

ask_domain() {
  local d
  while true; do
    read -r -p "  Your domain (for example bot.example.com): " d
    d="${d,,}"; d="${d// /}"; d="${d#http://}"; d="${d#https://}"; d="${d%/}"
    if valid_domain "$d"; then
      echo "$d"
      return 0
    fi
    warn "That does not look like a domain. Example: bot.example.com"
  done
}

ask_email() {
  local e
  while true; do
    read -r -p "  Your email (the setup details will be sent here): " e
    e="${e// /}"
    if valid_email "$e"; then
      echo "$e"
      return 0
    fi
    warn "That does not look like an email address. Example: you@example.com"
  done
}

mark_safe() {
  git config --system --add safe.directory "$INSTALL_DIR" 2>/dev/null
}

# ---------- Network ----------
server_public_ip() {
  local ip
  ip=$(curl -4 -fsS --max-time 10 https://api.ipify.org 2>/dev/null)
  if [[ -z "$ip" ]]; then
    ip=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+(\.[0-9]+){3}$' | head -n 1)
  fi
  echo "$ip"
}

domain_ipv4() {
  getent ahostsv4 "$1" 2>/dev/null | awk '{print $1; exit}'
}

# Returns 0 when the domain points to this server, or when the user accepts the mismatch.
check_dns() {
  local d="$1" dns_ip server_ip
  dns_ip=$(domain_ipv4 "$d")
  if [[ -z "$dns_ip" ]]; then
    warn "${d} does not point anywhere yet. Add an A record that points to this server's IP."
    ask_yes_no "Continue anyway? (the certificate will fail until the record works)"
    return $?
  fi
  server_ip=$(server_public_ip)
  if [[ -n "$server_ip" && "$dns_ip" != "$server_ip" ]]; then
    warn "${d} points to ${dns_ip}, but this server is ${server_ip}."
    tip "If you use a proxy such as Cloudflare's orange cloud, choose DNS verification later."
    ask_yes_no "Continue anyway?"
    return $?
  fi
  ok "${d} points to this server."
  return 0
}

# ---------- Ports ----------
# Prints the process name listening on a TCP port. Returns 1 when the port is free.
port_owner() {
  local port="$1" line name
  line=$(ss -H -ltnp "( sport = :${port} )" 2>/dev/null | head -n 1)
  [[ -z "$line" ]] && return 1
  name=$(sed -n 's/.*users:(("\([^"]*\)".*/\1/p' <<< "$line")
  echo "${name:-unknown}"
  return 0
}

# Returns 0 when the port is free or already used by this bot's Apache.
port_usable() {
  local name
  name=$(port_owner "$1") || return 0
  [[ "$name" == "apache2" ]]
}

write_ports_conf() {
  [[ -f "$PORTS_BACKUP" ]] || cp "$PORTS_CONF" "$PORTS_BACKUP"
  {
    echo "# Managed by the Simorgh Bot manager. Original file: ${PORTS_BACKUP}"
    local p
    for p in "$@"; do
      echo "Listen ${p}"
    done
  } > "$PORTS_CONF"
}

restore_ports_conf() {
  if [[ -f "$PORTS_BACKUP" ]]; then
    mv -f "$PORTS_BACKUP" "$PORTS_CONF"
  fi
}

disable_default_site() {
  if [[ -L /etc/apache2/sites-enabled/000-default.conf ]]; then
    mkdir -p "$(dirname "$DEFAULT_SITE_STATE")"
    echo "000-default" > "$DEFAULT_SITE_STATE"
    a2dissite 000-default >>"$LOG_FILE" 2>&1
  fi
}

restore_default_site() {
  if [[ -f "$DEFAULT_SITE_STATE" ]] && grep -q "000-default" "$DEFAULT_SITE_STATE"; then
    a2ensite 000-default >>"$LOG_FILE" 2>&1
  fi
  rm -f "$DEFAULT_SITE_STATE"
}

ensure_firewall_port() {
  local p="$1"
  command -v ufw >/dev/null 2>&1 || return 0
  ufw status 2>/dev/null | grep -q "Status: active" || return 0
  if ufw status 2>/dev/null | grep -Eq "^${p}(/tcp)?[[:space:]]"; then
    return 0
  fi
  if ask_enter_yes "The firewall is on and port ${p} is closed. Open it for the bot?"; then
    if ufw allow "${p}/tcp" >>"$LOG_FILE" 2>&1; then
      ok "Port ${p} is open in the firewall."
    else
      warn "Could not open port ${p}. Please open it manually."
    fi
  else
    warn "Port ${p} stays closed. Telegram will not reach the bot until it is open."
  fi
}

required_public_ports() {
  if [[ "$CERT_METHOD" == "http01" ]]; then
    echo 80
  fi
  echo "$HTTPS_PORT"
}

# ---------- Apache vhosts ----------
vhost_app_block() { # host port
  cat <<EOF
<VirtualHost *:$2>
    ServerName $1
    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-access.log combined
</VirtualHost>
EOF
}

vhost_redirect_block() { # host target_base
  cat <<EOF
<VirtualHost *:80>
    ServerName $1
    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ $2%{REQUEST_URI} [R=301,L]

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-error.log
</VirtualHost>
EOF
}

vhost_ssl_block() { # host port certfile keyfile
  cat <<EOF
<VirtualHost *:$2>
    ServerName $1
    DocumentRoot ${INSTALL_DIR}

    SSLEngine on
    SSLCertificateFile $3
    SSLCertificateKeyFile $4
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-ssl-access.log combined
</VirtualHost>
EOF
}

https_base() { # host port
  if [[ "$2" == "443" ]]; then
    echo "https://$1"
  else
    echo "https://$1:$2"
  fi
}

public_base() {
  https_base "$PUBLIC_HOST" "$HTTPS_PORT"
}

activate_site() {
  a2ensite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
}

apache_apply() {
  if ! apache2ctl configtest >>"$LOG_FILE" 2>&1; then
    err "The web server configuration is invalid. Details: ${LOG_FILE}"
    return 1
  fi
  if systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  else
    systemctl start apache2 >>"$LOG_FILE" 2>&1
  fi
  sleep 1
  if ! systemctl is-active --quiet apache2; then
    err "The web server did not start. Recent errors:"
    journalctl -u apache2 -n 10 --no-pager >&2
    return 1
  fi
  return 0
}

# Apache does not allow <DirectoryMatch> inside .htaccess, which returns HTTP 500 for every request.
# The same protection is written with a RewriteRule, which Apache accepts in .htaccess.
patch_htaccess() {
  local f="$INSTALL_DIR/.htaccess"
  [[ -f "$f" ]] || return 0
  grep -q 'DirectoryMatch' "$f" || return 0
  if python3 - "$f" <<'PATCH_PY' >>"$LOG_FILE" 2>&1
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
text = re.sub(r'<DirectoryMatch\s+"[^"]*">.*?</DirectoryMatch>[ \t]*\n?', '', text, flags=re.S)
rule = "RewriteRule ^(src|storage|" + chr(92) + ".git)(/|$) - [F,L]" + chr(10)
if "RewriteEngine On" in text:
    text = text.replace("RewriteEngine On" + chr(10), "RewriteEngine On" + chr(10) + rule, 1)
else:
    text = "RewriteEngine On" + chr(10) + rule + text
open(path, "w", encoding="utf-8").write(text)
PATCH_PY
  then
    ok "Compatibility fix applied."
    return 0
  fi
  err "Could not apply the compatibility fix. Details: ${LOG_FILE}"
  return 1
}

fix_permissions() {
  mkdir -p "$INSTALL_DIR/storage"
  chown -R www-data:www-data "$INSTALL_DIR"
  chmod -R u+rwX,g+rwX "$INSTALL_DIR/storage"
}

# ---------- Software ----------
install_dependencies() {
  info "Installing the required software. This takes a few minutes..."
  export DEBIAN_FRONTEND=noninteractive
  local created_policy=false rc=0
  if [[ ! -e "$POLICY_FILE" ]]; then
    printf '#!/bin/sh\nexit 101\n' > "$POLICY_FILE" && chmod +x "$POLICY_FILE"
    created_policy=true
  fi
  apt-get update -y >>"$LOG_FILE" 2>&1 || rc=1
  if [[ $rc -eq 0 ]]; then
    apt-get install -y apache2 git curl unzip openssl python3 certbot python3-certbot-apache \
      mariadb-server php libapache2-mod-php php-mysql php-curl php-mbstring php-xml >>"$LOG_FILE" 2>&1 || rc=1
  fi
  if [[ "$created_policy" == true ]]; then
    rm -f "$POLICY_FILE"
  fi
  if [[ $rc -ne 0 ]]; then
    err "Installing the required software failed. Details: ${LOG_FILE}"
    return 1
  fi
  if ! php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    err "This server needs PHP 8.0 or newer for the bot."
    return 1
  fi
  a2enmod rewrite headers ssl >>"$LOG_FILE" 2>&1
  systemctl enable apache2 >>"$LOG_FILE" 2>&1
  systemctl enable --now mariadb >>"$LOG_FILE" 2>&1
  ok "Required software is ready."
  return 0
}

install_mail() {
  if command -v sendmail >/dev/null 2>&1; then
    return 0
  fi
  info "Preparing email delivery (Postfix)..."
  export DEBIAN_FRONTEND=noninteractive
  debconf-set-selections <<< "postfix postfix/main_mailer_type select Internet Site" >>"$LOG_FILE" 2>&1
  debconf-set-selections <<< "postfix postfix/mailname string ${DOMAIN}" >>"$LOG_FILE" 2>&1
  if apt-get install -y postfix >>"$LOG_FILE" 2>&1; then
    ok "Email delivery is ready."
    return 0
  fi
  warn "Email delivery could not be prepared. The details will be saved on the server instead."
  return 1
}

install_deploy_hook() {
  mkdir -p "$(dirname "$DEPLOY_HOOK")"
  printf '#!/usr/bin/env bash\nsystemctl reload apache2\n' > "$DEPLOY_HOOK"
  chmod +x "$DEPLOY_HOOK"
}

# ---------- Database ----------
random_alnum() { # length
  openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "$1"
}

install_database() {
  if [[ -f "$DB_ENV" ]]; then
    ok "The bot database already exists."
    return 0
  fi
  info "Creating the bot's database and its own user..."
  local db user pass
  db="simorgh_$(random_alnum 6)"
  user="simorgh_$(random_alnum 6)"
  pass=$(random_alnum 24)
  if ! mysql -u root >>"$LOG_FILE" 2>&1 <<SQL
CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${user}'@'localhost' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL
  then
    err "Could not create the database. Details: ${LOG_FILE}"
    return 1
  fi
  mkdir -p "$(dirname "$DB_ENV")"
  ( umask 077
    printf 'DB_HOST=localhost\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$db" "$user" "$pass" > "$DB_ENV" )
  chmod 600 "$DB_ENV"
  ok "Database created."
  return 0
}

drop_database() {
  [[ -f "$DB_ENV" ]] || return 0
  local DB_HOST DB_NAME DB_USER DB_PASS
  # shellcheck disable=SC1090
  source "$DB_ENV"
  if mysql -u root >>"$LOG_FILE" 2>&1 <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  then
    ok "The bot's database and its user were removed."
  else
    warn "Could not remove the database automatically. Details: ${LOG_FILE}"
  fi
  rm -f "$DB_ENV"
}

# ---------- Certificates ----------
create_self_signed_cert() { # ip
  local ip="$1"
  mkdir -p "$SELF_SIGNED_DIR"
  chmod 700 "$SELF_SIGNED_DIR"
  CERT_FILE="${SELF_SIGNED_DIR}/${ip}.crt"
  KEY_FILE="${SELF_SIGNED_DIR}/${ip}.key"
  info "Creating a certificate for ${ip}..."
  if openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
      -keyout "$KEY_FILE" -out "$CERT_FILE" \
      -subj "/CN=${ip}" \
      -addext "subjectAltName=IP:${ip}" \
      -addext "basicConstraints=critical,CA:FALSE" \
      -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
      -addext "extendedKeyUsage=serverAuth" >>"$LOG_FILE" 2>&1; then
    chmod 600 "$KEY_FILE"
    ok "Certificate created."
    return 0
  fi
  err "Could not create the certificate. Details: ${LOG_FILE}"
  return 1
}

write_cloudflare_credentials() {
  local token
  read -r -s -p "  Cloudflare API token (hidden as you type): " token
  echo
  token="${token// /}"
  if [[ -z "$token" ]]; then
    err "No token was entered."
    return 1
  fi
  mkdir -p "$(dirname "$CF_CREDS")"
  ( umask 077; printf 'dns_cloudflare_api_token = %s\n' "$token" > "$CF_CREDS" )
  chmod 600 "$CF_CREDS"
  return 0
}

obtain_certificate() {
  local ready=false
  case "$CERT_METHOD" in
    http01)
      mkdir -p "${INSTALL_DIR}/.well-known/acme-challenge"
      info "Requesting your certificate from Let's Encrypt..."
      if certbot certonly --webroot -w "$INSTALL_DIR" -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --non-interactive --keep-until-expiring >>"$LOG_FILE" 2>&1; then
        ready=true
      fi
      ;;
    cloudflare)
      info "Installing the Cloudflare plugin..."
      if ! apt-get install -y python3-certbot-dns-cloudflare >>"$LOG_FILE" 2>&1; then
        err "Could not install the Cloudflare plugin. Details: ${LOG_FILE}"
        return 1
      fi
      write_cloudflare_credentials || return 1
      info "Requesting your certificate through Cloudflare DNS..."
      if certbot certonly --dns-cloudflare --dns-cloudflare-credentials "$CF_CREDS" \
          --dns-cloudflare-propagation-seconds 60 -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --non-interactive --keep-until-expiring >>"$LOG_FILE" 2>&1; then
        ready=true
      fi
      ;;
    manual)
      tip "Certbot will show a TXT record. Add it to your DNS, wait a minute, then press Enter here."
      tip "In this mode you will need to renew the certificate by hand every 90 days."
      if certbot certonly --manual --preferred-challenges dns -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --keep-until-expiring; then
        ready=true
      fi
      ;;
  esac
  if [[ "$ready" == true && -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    CERT_FILE="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    KEY_FILE="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
    ok "Your certificate is ready."
    return 0
  fi
  err "The certificate could not be issued. Details: ${LOG_FILE}"
  return 1
}

choose_dns_provider() {
  echo "    1) Cloudflare           (renews automatically)"
  echo "    2) Another DNS provider (renew by hand every 90 days)"
  echo "    3) Cancel"
  echo
  local c
  while true; do
    read -r -p "  Your DNS provider [1-3]: " c
    case "$c" in
      1) CERT_METHOD="cloudflare"; return 0 ;;
      2) CERT_METHOD="manual"; return 0 ;;
      3) err "Installation cancelled. Nothing was changed."; return 1 ;;
      *) warn "Please type 1, 2 or 3." ;;
    esac
  done
}

# ---------- Guided questions ----------
show_welcome() {
  clear
  echo -e "${C_CYAN}${C_BOLD}"
  echo "   ╔══════════════════════════════════════════════╗"
  echo "   ║             SIMORGH BOT INSTALLER            ║"
  echo "   ╚══════════════════════════════════════════════╝"
  echo -e "${C_RESET}"
  say "Welcome. I will set up your bot in a few guided steps."
  say "Nothing changes on this server until you confirm at the end."
  echo
}

ask_certificate_target() {
  step "Step 1 · Where will the bot live?"
  tip "A domain name (like bot.example.com) gives you a trusted certificate that every browser accepts."
  tip "Without a domain, the bot runs on your server IP with a self-signed certificate. Browsers show a warning, but it works."
  echo
  if ask_yes_no "Do you have a domain name for this server?"; then
    DOMAIN=$(ask_domain)
    check_dns "$DOMAIN" || { err "Installation cancelled."; return 1; }
    echo
    tip "Enter your email. Once the bot is ready, I will send you the links and the database details there."
    EMAIL=$(ask_email)
    PUBLIC_HOST="$DOMAIN"
  else
    DOMAIN=""
    EMAIL=""
    PUBLIC_HOST=$(server_public_ip)
    if [[ -z "$PUBLIC_HOST" ]]; then
      err "Could not detect this server's public IP. Check the internet connection."
      return 1
    fi
    ok "The bot will run on ${PUBLIC_HOST}."
    tip "Your browser will say 'Not secure' for a self-signed certificate. That is expected for an IP address."
  fi
  return 0
}

ask_certificate_method() {
  if [[ -z "$DOMAIN" ]]; then
    CERT_METHOD="selfsigned"
    return 0
  fi

  step "Step 2 · Verifying your domain"
  if port_usable 80; then
    tip "Port 80 is the standard door for web traffic. Let's Encrypt knocks on it briefly to confirm the domain is yours,"
    tip "then it leaves. The bot itself does not need port 80."
    echo
    if ask_enter_yes "Verify the domain through port 80? (recommended)"; then
      CERT_METHOD="http01"
      ok "Port 80 will be used for verification."
      return 0
    fi
  else
    warn "Port 80 is busy with $(port_owner 80). That is fine: we will verify your domain through DNS instead."
  fi

  choose_dns_provider
}

ask_https_port() {
  step "Step 3 · The bot's secure door"
  tip "The bot talks to Telegram over HTTPS. Port 443 is the standard secure door and our first choice."
  tip "If 443 is busy, port 8443 is the backstage door. Telegram accepts it too."
  echo

  if port_usable 443; then
    HTTPS_PORT=443
    ok "Port 443 is free. The bot will use it."
  elif port_usable 8443; then
    HTTPS_PORT=8443
    warn "Port 443 is busy with $(port_owner 443). The bot will use port 8443 instead."
  else
    err "Ports 443 and 8443 are both busy. Free one of them, then try again."
    return 1
  fi
  return 0
}

show_summary() {
  local cert_text
  case "$CERT_METHOD" in
    selfsigned) cert_text="Self-signed for ${PUBLIC_HOST} (browser warning expected)" ;;
    http01)     cert_text="Let's Encrypt, verified through port 80 (renews automatically)" ;;
    cloudflare) cert_text="Let's Encrypt, verified through Cloudflare DNS (renews automatically)" ;;
    manual)     cert_text="Let's Encrypt, verified by hand (renew every 90 days)" ;;
  esac
  echo
  echo -e "  ${C_BOLD}Here is what I will set up${C_RESET}"
  echo "    Address     : $(public_base)/install.php"
  echo "    Certificate : ${cert_text}"
  echo "    HTTPS port  : ${HTTPS_PORT}"
  if [[ -n "$EMAIL" ]]; then
    echo "    Email to    : ${EMAIL}"
  fi
  echo
}

# ---------- Rollback ----------
rollback_install() {
  warn "Something went wrong, so I am undoing this attempt..."
  a2dissite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
  rm -f "$VHOST_FILE"
  restore_ports_conf
  restore_default_site
  if apache2ctl configtest >>"$LOG_FILE" 2>&1 && systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  fi
  rm -rf "$INSTALL_DIR"
  ok "Undone. Your server is back to its previous web configuration."
}

# ---------- Verification ----------
show_recent_errors() {
  local f
  for f in "/var/log/apache2/${VHOST_NAME}-ssl-error.log" "/var/log/apache2/error.log"; do
    if [[ -f "$f" ]]; then
      echo -e "  ${C_BOLD}Last lines of ${f}:${C_RESET}" >&2
      tail -n 15 "$f" >&2
      echo >&2
    fi
  done
}

health_check() {
  local code external
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 \
    --resolve "${PUBLIC_HOST}:${HTTPS_PORT}:127.0.0.1" \
    "https://${PUBLIC_HOST}:${HTTPS_PORT}/install.php")
  case "$code" in
    200|301|302|303|307|308)
      ok "The installation page responds correctly (HTTP ${code})."
      HEALTH_OK=true ;;
    *)
      err "The installation page returned HTTP ${code}."
      show_recent_errors
      HEALTH_OK=false ;;
  esac

  external=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$(public_base)/install.php")
  case "$external" in
    000)
      warn "The address cannot be reached from the internet yet. Check your cloud provider's firewall for port ${HTTPS_PORT}."
      HEALTH_OK=false ;;
    2??|3??)
      ok "The address is reachable from the internet." ;;
    *)
      warn "The address is reachable, but returned HTTP ${external}."
      HEALTH_OK=false ;;
  esac
}

# ---------- Credentials and email ----------
write_credentials() {
  local ver="$1" base
  base=$(public_base)
  # shellcheck disable=SC1090
  source "$DB_ENV"
  ( umask 077
    cat > "$CREDENTIALS_FILE" <<EOF
Simorgh Bot — Setup Details
===========================
Version         : v${ver:-unknown}
Date            : $(date '+%F %T')

Links
-----
Installation / setup : ${base}/install.php
Mini App             : ${base}/miniapp.php
Telegram webhook     : ${base}/webhook.php

Database (MySQL / MariaDB)
--------------------------
Host     : ${DB_HOST}
Database : ${DB_NAME}
Username : ${DB_USER}
Password : ${DB_PASS}

Keep this file private. Delete install.php and selftest.php after setup.
EOF
  )
  chmod 600 "$CREDENTIALS_FILE"
}

send_setup_email() {
  local ver="$1" base msg
  base=$(public_base)
  # shellcheck disable=SC1090
  source "$DB_ENV"
  if ! command -v sendmail >/dev/null 2>&1; then
    warn "No mail service is available. Your details are saved in ${CREDENTIALS_FILE}."
    return 1
  fi
  msg=$(mktemp)
  cat > "$msg" <<EOF
From: Simorgh Bot <noreply@${DOMAIN}>
To: ${EMAIL}
Subject: Your Simorgh Bot is ready
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello,

Your Simorgh Bot v${ver:-unknown} is installed and running on ${base}.

Links
-----
Finish the setup : ${base}/install.php
Mini App         : ${base}/miniapp.php
Telegram webhook : ${base}/webhook.php

Database details (MySQL / MariaDB)
----------------------------------
Host     : ${DB_HOST}
Database : ${DB_NAME}
Username : ${DB_USER}
Password : ${DB_PASS}

Next steps
----------
1. Open the setup page and enter your bot token, your numeric Telegram ID,
   the database details above, and your panel username and password.
2. When setup is complete, delete install.php and selftest.php from the server.

Keep this email private. It contains your database password.

Simorgh Bot
${CHANNEL_URL}
EOF
  if sendmail -t -i < "$msg" >>"$LOG_FILE" 2>&1; then
    rm -f "$msg"
    ok "The setup details were emailed to ${EMAIL}."
    tip "If you do not see the email within a few minutes, check your spam folder."
    return 0
  fi
  rm -f "$msg"
  warn "The email could not be sent. Your details are saved in ${CREDENTIALS_FILE}."
  return 1
}

copy_info_to_drive() {
  local mounts=() m n i dest
  while IFS= read -r m; do
    [[ -n "$m" ]] && mounts+=("$m")
  done < <(findmnt -rn -o TARGET 2>/dev/null | grep -E '^/(media|mnt)/' | sort -u)
  if [[ ${#mounts[@]} -eq 0 ]]; then
    return 0
  fi
  echo
  say "I found external drives. Copy the setup details to one of them?"
  i=1
  for m in "${mounts[@]}"; do
    echo "    ${i}) ${m}"
    i=$((i + 1))
  done
  read -r -p "  Drive number (Enter = skip): " n
  [[ -z "$n" ]] && return 0
  if ! [[ "$n" =~ ^[0-9]+$ ]] || (( n < 1 || n > ${#mounts[@]} )); then
    warn "That is not a drive number from the list."
    return 0
  fi
  dest="${mounts[$((n - 1))]}/simorgh-bot"
  if mkdir -p "$dest" && cp "$CREDENTIALS_FILE" "$dest/" 2>/dev/null; then
    sync
    ok "Copied to ${dest}/"
  else
    warn "Could not copy to that drive."
  fi
}

# ---------- Option 1: Install ----------
do_install() {
  show_welcome
  if is_installed; then
    warn "The bot is already installed. Use the Update option to keep it current."
    pause; return
  fi
  if [[ -e "$INSTALL_DIR" ]]; then
    err "${INSTALL_DIR} exists but is incomplete. Remove the bot first (option 2)."
    pause; return
  fi
  if [[ -z "$REMOTE_VERSION" ]]; then
    err "I could not reach GitHub. Check the internet connection and try again."
    pause; return
  fi

  if ! ask_certificate_target; then pause; return; fi
  if ! ask_certificate_method; then pause; return; fi
  if ! ask_https_port; then pause; return; fi

  show_summary
  read -r -p "  Press Enter to start the installation (Ctrl+C to cancel)... " _

  step "Installing"
  if ! install_dependencies; then pause; return; fi
  if ! install_database; then rollback_install; pause; return; fi
  if [[ -n "$DOMAIN" ]]; then
    install_mail
  fi

  local p ports_list=()
  mapfile -t ports_list < <(required_public_ports | sort -un)
  for p in "${ports_list[@]}"; do
    ensure_firewall_port "$p"
  done

  info "Downloading the bot (v${REMOTE_VERSION})..."
  if ! git clone --quiet "$REPO_URL" "$INSTALL_DIR" >>"$LOG_FILE" 2>&1; then
    err "Downloading the bot from GitHub failed."
    rm -rf "$INSTALL_DIR"
    pause; return
  fi
  if [[ ! -f "$INSTALL_DIR/install.php" ]]; then
    err "The downloaded bot is incomplete."
    rollback_install
    pause; return
  fi
  mark_safe
  patch_htaccess || { rollback_install; pause; return; }
  # Telegram accepts a self-signed certificate only when it is uploaded with setWebhook.
  # The bot's installer reads it from storage/ (which the web server never serves).
  if [[ "$CERT_METHOD" == "selfsigned" && -f "$CERT_FILE" ]]; then
    mkdir -p "$INSTALL_DIR/storage"
    cp "$CERT_FILE" "$INSTALL_DIR/storage/webhook-cert.pem"
  fi
  fix_permissions
  ok "Bot files are in place."

  disable_default_site

  case "$CERT_METHOD" in
    selfsigned)
      if ! create_self_signed_cert "$PUBLIC_HOST"; then rollback_install; pause; return; fi
      write_ports_conf "$HTTPS_PORT"
      vhost_ssl_block "$PUBLIC_HOST" "$HTTPS_PORT" "$CERT_FILE" "$KEY_FILE" > "$VHOST_FILE"
      ;;
    http01)
      write_ports_conf 80
      vhost_app_block "$DOMAIN" 80 > "$VHOST_FILE"
      activate_site
      if ! apache_apply; then rollback_install; pause; return; fi
      if ! obtain_certificate; then rollback_install; pause; return; fi
      install_deploy_hook
      write_ports_conf 80 "$HTTPS_PORT"
      {
        vhost_redirect_block "$DOMAIN" "$(https_base "$DOMAIN" "$HTTPS_PORT")"
        vhost_ssl_block "$DOMAIN" "$HTTPS_PORT" "$CERT_FILE" "$KEY_FILE"
      } > "$VHOST_FILE"
      ;;
    cloudflare|manual)
      if ! obtain_certificate; then rollback_install; pause; return; fi
      if [[ "$CERT_METHOD" == "cloudflare" ]]; then
        install_deploy_hook
      fi
      write_ports_conf "$HTTPS_PORT"
      vhost_ssl_block "$DOMAIN" "$HTTPS_PORT" "$CERT_FILE" "$KEY_FILE" > "$VHOST_FILE"
      ;;
  esac

  activate_site
  if ! apache_apply; then rollback_install; pause; return; fi
  ok "The secure web server is running."

  step "Checking the installation"
  health_check

  local url ver
  url=$(public_base)
  ver=$(read_local_version)
  write_credentials "$ver"

  echo
  echo -e "  ${C_GREEN}${C_BOLD}Simorgh Bot v${ver:-?} is installed.${C_RESET}"
  echo
  echo -e "  ${C_BOLD}Open this address to finish the setup:${C_RESET}"
  echo -e "     ${C_CYAN}${C_BOLD}${url}/install.php${C_RESET}"
  echo
  echo -e "  ${C_BOLD}Mini App:${C_RESET}  ${url}/miniapp.php"
  echo -e "  ${C_BOLD}Webhook :${C_RESET}  ${url}/webhook.php"
  echo
  # shellcheck disable=SC1090
  source "$DB_ENV"
  echo -e "  ${C_BOLD}Database for the setup page${C_RESET}"
  echo "     Host     : ${DB_HOST}"
  echo "     Database : ${DB_NAME}"
  echo "     Username : ${DB_USER}"
  echo "     Password : ${DB_PASS}"
  echo
  ok "All of these details are saved in ${CREDENTIALS_FILE}."

  if [[ -n "$EMAIL" ]]; then
    send_setup_email "$ver"
  fi

  cp "$CREDENTIALS_FILE" "$INFO_FILE" 2>/dev/null
  copy_info_to_drive

  if [[ "$HEALTH_OK" != true ]]; then
    warn "The page did not respond correctly. Check the error lines above, or open Live logs from the menu."
  fi
  pause
}

# ---------- Option 2: Uninstall ----------
make_quick_backup() {
  local items=() file
  [[ -f "$INSTALL_DIR/config.php" ]] && items+=("config.php")
  [[ -f "$INSTALL_DIR/installed.lock" ]] && items+=("installed.lock")
  [[ -d "$INSTALL_DIR/storage" ]] && items+=("storage")
  [[ ${#items[@]} -eq 0 ]] && return 0
  mkdir -p "$BACKUP_DIR"
  file="$BACKUP_DIR/simorgh-$(date +%Y%m%d-%H%M%S).tar.gz"
  if tar -czf "$file" -C "$INSTALL_DIR" "${items[@]}" 2>/dev/null; then
    chmod 600 "$file"
    ok "Safety copy of your settings saved to ${file}"
  fi
}

do_uninstall() {
  show_banner_short
  if ! is_installed && [[ ! -f "$VHOST_FILE" ]]; then
    warn "The bot is not installed."
    pause; return
  fi

  warn "This removes the bot, its website settings, its certificate, its database and its files from this server."
  if ! ask_yes_no "Do you want to remove the bot completely?"; then
    info "Nothing was removed. Returning to the menu."
    pause; return
  fi

  if [[ -d "$INSTALL_DIR" ]]; then
    make_quick_backup
  fi

  local host=""
  if [[ -f "$VHOST_FILE" ]]; then
    host=$(awk '/ServerName/{print $2; exit}' "$VHOST_FILE")
  fi

  a2dissite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
  rm -f "$VHOST_FILE"
  restore_ports_conf
  restore_default_site
  if apache2ctl configtest >>"$LOG_FILE" 2>&1 && systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  fi
  rm -rf "$INSTALL_DIR" "$SELF_SIGNED_DIR"
  ok "Bot files and website settings removed."

  drop_database

  if [[ -n "$host" && -d "/etc/letsencrypt/live/${host}" ]]; then
    if certbot delete --cert-name "$host" --non-interactive >>"$LOG_FILE" 2>&1; then
      ok "Certificate for ${host} removed."
    else
      warn "Could not remove the certificate for ${host}. Details: ${LOG_FILE}"
    fi
  fi
  rm -f "$DEPLOY_HOOK" "$CF_CREDS" "$INFO_FILE" "$CREDENTIALS_FILE"

  echo
  ok "The bot has been removed from this server."
  pause
}

# ---------- Option 3: Update ----------
do_update() {
  show_banner_short
  if ! is_installed; then
    warn "The bot is not installed yet. Choose Install first."
    pause; return
  fi

  mark_safe
  info "Checking for updates..."
  if ! git -C "$INSTALL_DIR" fetch --quiet origin main >>"$LOG_FILE" 2>&1; then
    err "Could not check for updates. Check the internet connection."
    pause; return
  fi

  local local_rev remote_rev local_ver new_ver
  local_rev=$(git -C "$INSTALL_DIR" rev-parse HEAD)
  remote_rev=$(git -C "$INSTALL_DIR" rev-parse origin/main)
  local_ver=$(read_local_version)

  if [[ "$local_rev" == "$remote_rev" ]]; then
    ok "Your bot is up to date (v${local_ver:-?}). Nothing to do."
    pause; return
  fi

  new_ver=$(git -C "$INSTALL_DIR" show origin/main:src/bootstrap.php 2>/dev/null | parse_version)
  info "A new version is available: v${local_ver:-?} → v${new_ver:-?}. Updating now..."

  make_quick_backup
  git -C "$INSTALL_DIR" checkout -- .htaccess >>"$LOG_FILE" 2>&1
  if ! git -C "$INSTALL_DIR" pull --ff-only --quiet origin main >>"$LOG_FILE" 2>&1; then
    err "The update could not be applied. Details: ${LOG_FILE}"
    pause; return
  fi
  patch_htaccess
  fix_permissions
  if ! apache_apply; then
    warn "The web server did not reload. Check Live logs from the menu."
  fi
  REMOTE_VERSION="${new_ver:-$REMOTE_VERSION}"
  ok "Updated to v${new_ver:-?}."

  if [[ -f "$INSTALL_DIR/install.php" ]]; then
    warn "install.php came back with the update. Delete it from the server after checking."
  fi
  pause
}

# ---------- Option 4: Live logs ----------
show_live_logs() {
  show_banner_short
  if ! is_installed && [[ ! -f "$VHOST_FILE" ]]; then
    warn "The bot is not installed, so there are no bot logs yet."
    pause; return
  fi

  local files=() f app_log
  app_log=$(ls -t "$INSTALL_DIR"/storage/logs/*.log 2>/dev/null | head -n 1)
  [[ -n "$app_log" ]] && files+=("$app_log")
  for f in "/var/log/apache2/${VHOST_NAME}-ssl-error.log" \
           "/var/log/apache2/${VHOST_NAME}-ssl-access.log" \
           "$LOG_FILE"; do
    [[ -f "$f" ]] && files+=("$f")
  done

  if [[ ${#files[@]} -eq 0 ]]; then
    warn "No log files were found yet."
    pause; return
  fi

  info "Live logs. Press Ctrl+C to return to the menu."
  echo
  trap '' INT
  ( trap - INT; exec tail -n 30 -F "${files[@]}" )
  trap on_interrupt INT
  echo
}

# ---------- Farewell ----------
farewell() {
  clear
  echo -e "${C_CYAN}${C_BOLD}"
  cat <<'ART'
                  .
                 /|\
                / | \
               /  |  \
              /   |   \
        _____/____|____\_____
ART
  echo -e "${C_RESET}"
  echo -e "   ${C_BOLD}Simorgh is always watching.${C_RESET}"
  echo
  echo -e "   Whenever you need Simorgh, just type:  ${C_YELLOW}${C_BOLD}simorgh${C_RESET}"
  echo
  echo -e "   ${C_CYAN}Channel:${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_CYAN}Group  :${C_RESET} ${GROUP_URL}"
  echo
}

on_interrupt() {
  echo
  farewell
  exit 130
}

# ---------- Menu ----------
show_banner_short() {
  clear
  echo -e "${C_CYAN}${C_BOLD}"
  echo "   ╔══════════════════════════════════════════════╗"
  echo "   ║                 SIMORGH BOT                  ║"
  echo "   ║              Server Manager Tool             ║"
  echo "   ╚══════════════════════════════════════════════╝"
  echo -e "${C_RESET}"
  echo -e "   ${C_BOLD}Channel:${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_BOLD}Group  :${C_RESET} ${GROUP_URL}"
  echo
}

show_menu_status() {
  local local_ver
  if is_installed; then
    local_ver=$(read_local_version)
    echo -e "   ${C_BOLD}Status :${C_RESET} ${C_GREEN}Installed${C_RESET} (v${local_ver:-unknown})"
    if [[ -n "$REMOTE_VERSION" && -n "$local_ver" && "$REMOTE_VERSION" != "$local_ver" ]]; then
      echo -e "   ${C_YELLOW}A new version (v${REMOTE_VERSION}) is available. Choose option 3.${C_RESET}"
    fi
  else
    echo -e "   ${C_BOLD}Status :${C_RESET} ${C_RED}Not installed${C_RESET}"
    [[ -n "$REMOTE_VERSION" ]] && echo -e "   ${C_BOLD}Latest :${C_RESET} v${REMOTE_VERSION}"
  fi
  echo
}

main_menu() {
  while true; do
    show_banner_short
    show_menu_status
    echo "    1) Install the bot"
    echo "    2) Uninstall the bot"
    echo "    3) Update the bot"
    echo "    4) Live logs"
    echo "    5) Exit"
    echo
    read -r -p "  Choose an option [1-5]: " choice
    case "$choice" in
      1) do_install ;;
      2) do_uninstall ;;
      3) do_update ;;
      4) show_live_logs ;;
      5) farewell; exit 0 ;;
      *) warn "Please choose a number from 1 to 5."; sleep 1 ;;
    esac
  done
}

main() {
  require_apt
  mkdir -p "$(dirname "$LOG_FILE")"
  touch "$LOG_FILE" && chmod 600 "$LOG_FILE"
  log "---- Session started ----"

  if [[ $EUID -ne 0 ]]; then
    if [[ -f "$0" ]] && command -v sudo >/dev/null 2>&1; then
      exec sudo bash "$0" "$@"
    fi
    err "Please run:  sudo simorgh"
    exit 1
  fi

  trap on_interrupt INT
  install_self
  show_banner_short
  info "Connecting to GitHub..."
  REMOTE_VERSION=$(fetch_remote_version)
  main_menu
}

if [[ "${BASH_SOURCE[0]:-$0}" == "$0" ]]; then
  main "$@"
fi
