#!/usr/bin/env bash
# =====================================================================
#  Simorgh Bot — Server Manager
#  Command : sudo simorgh
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
INSTALL_DIR="/var/www/simorgh-bot"
VHOST_NAME="simorgh-bot"
VHOST_FILE="/etc/apache2/sites-available/${VHOST_NAME}.conf"
PORTS_CONF="/etc/apache2/ports.conf"
PORTS_BACKUP="/etc/apache2/ports.conf.simorgh-orig"
POLICY_FILE="/usr/sbin/policy-rc.d"
CF_CREDS="/root/.secrets/simorgh-cloudflare.ini"
DEPLOY_HOOK="/etc/letsencrypt/renewal-hooks/deploy/simorgh-reload-apache.sh"
BACKUP_DIR="/root/simorgh-backups"
INFO_FILE="/root/simorgh-install-info.txt"
LOG_FILE="/var/log/simorgh-manager.log"
CHANNEL_URL="https://t.me/SimorghFree"
GROUP_URL="https://t.me/SimorghBotGroup"
LOG_LINES=100
REMOTE_VERSION=""

# Choices made during installation (set by plan_network)
DOMAIN=""          # domain name, empty when installing on the server IP
EMAIL=""           # Let's Encrypt contact email
PUBLIC_HOST=""     # domain or public IP used in the address
CERT_METHOD=""     # none | http01 | cloudflare | manual
HTTPS_PORT=""      # 443 or 8443 (empty when no HTTPS)
SCHEME="http"      # http | https

C_RED='\033[0;31m'; C_GREEN='\033[0;32m'; C_YELLOW='\033[1;33m'
C_CYAN='\033[0;36m'; C_BOLD='\033[1m'; C_RESET='\033[0m'

# ---------- Logging and output ----------
log() {
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null
  echo "$(date '+%F %T') $*" >> "$LOG_FILE" 2>/dev/null
}

ok()    { echo -e "  ${C_GREEN}✔${C_RESET} $*" >&2; log "OK    $*"; }
warn()  { echo -e "  ${C_YELLOW}⚠${C_RESET} $*" >&2; log "WARN  $*"; }
err()   { echo -e "  ${C_RED}✘${C_RESET} $*" >&2; log "ERROR $*"; }
info()  { echo -e "  ${C_CYAN}➜${C_RESET} $*" >&2; log "INFO  $*"; }
step()  { echo; echo -e "  ${C_BOLD}${C_CYAN}── $* ──${C_RESET}"; echo; }
pause() { echo; read -r -p "  Press Enter to continue..." _; }

ask_yes_no() {
  local answer
  while true; do
    read -r -p "  $1 [y/n]: " answer
    case "${answer,,}" in
      y|yes) return 0 ;;
      n|no)  return 1 ;;
      *) warn "Please enter y or n." ;;
    esac
  done
}

# ---------- Checks ----------
require_root() {
  if [[ $EUID -ne 0 ]]; then
    err "This script must be run with sudo:  sudo simorgh"
    exit 1
  fi
}

require_apt() {
  if ! command -v apt-get >/dev/null 2>&1; then
    err "This script only supports Ubuntu and Debian."
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
      ok "Command installed. From now on, run: sudo simorgh"
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

# ---------- Status and validation ----------
is_installed() {
  [[ -d "$INSTALL_DIR/.git" && -f "$INSTALL_DIR/src/bootstrap.php" ]]
}

valid_domain() {
  [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]
}

valid_email() {
  [[ "$1" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]
}

ask_domain() {
  local d
  while true; do
    read -r -p "  Domain name (e.g. bot.example.com): " d
    d="${d,,}"
    d="${d// /}"
    d="${d#http://}"
    d="${d#https://}"
    d="${d%/}"
    if valid_domain "$d"; then
      echo "$d"
      return 0
    fi
    warn "Enter a domain name only, without http:// and without a path."
  done
}

ask_email() {
  local e
  while true; do
    read -r -p "  Email for Let's Encrypt notices: " e
    e="${e// /}"
    if valid_email "$e"; then
      echo "$e"
      return 0
    fi
    warn "Enter a valid email address."
  done
}

mark_safe() {
  git config --system --add safe.directory "$INSTALL_DIR" 2>/dev/null
}

# ---------- Network helpers ----------
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

check_dns() {
  local d="$1" dns_ip server_ip
  dns_ip=$(domain_ipv4 "$d")
  if [[ -z "$dns_ip" ]]; then
    warn "${d} does not resolve yet. Create an A record that points to this server."
    ask_yes_no "Continue anyway? (the certificate will fail until DNS works)"
    return $?
  fi
  server_ip=$(server_public_ip)
  if [[ -n "$server_ip" && "$dns_ip" != "$server_ip" ]]; then
    warn "${d} points to ${dns_ip}, but this server's public IP is ${server_ip}."
    warn "If the domain uses a proxy (for example Cloudflare's orange cloud), HTTP-01 will fail; DNS-01 still works."
    ask_yes_no "Continue anyway?"
    return $?
  fi
  ok "${d} points to this server (${dns_ip})."
  return 0
}

# ---------- Port helpers ----------
# Prints the name of the process listening on a TCP port.
# Returns 1 (and prints nothing) when the port is free.
port_owner() {
  local port="$1" line name
  line=$(ss -H -ltnp "( sport = :${port} )" 2>/dev/null | head -n 1)
  [[ -z "$line" ]] && return 1
  name=$(sed -n 's/.*users:(("\([^"]*\)".*/\1/p' <<< "$line")
  echo "${name:-unknown}"
  return 0
}

# Returns 0 when the port is free or already belongs to this bot's Apache.
port_usable() {
  local name
  name=$(port_owner "$1") || return 0
  [[ "$name" == "apache2" ]]
}

port_label() {
  local name
  if name=$(port_owner "$1"); then
    if [[ "$name" == "apache2" ]]; then
      echo "used by Apache"
    else
      echo "in use by ${name}"
    fi
  else
    echo "free"
  fi
}

show_port_table() {
  local p
  echo -e "   ${C_BOLD}Port   Status${C_RESET}"
  for p in 80 443 8443; do
    printf "   %-6s %s\n" "$p" "$(port_label "$p")"
  done
}

ensure_firewall_port() {
  local p="$1"
  command -v ufw >/dev/null 2>&1 || return 0
  ufw status 2>/dev/null | grep -q "Status: active" || return 0
  if ufw status 2>/dev/null | grep -Eq "^${p}(/tcp)?[[:space:]]"; then
    return 0
  fi
  if ask_yes_no "The firewall (ufw) is active and port ${p} is closed. Open port ${p} now?"; then
    if ufw allow "${p}/tcp" >>"$LOG_FILE" 2>&1; then
      ok "Port ${p} opened in ufw."
    else
      warn "Could not open port ${p} in ufw. Open it manually."
    fi
  else
    warn "Port ${p} stays closed. Telegram will not reach the bot until it is opened."
  fi
}

required_public_ports() {
  if [[ "$CERT_METHOD" == "none" || "$CERT_METHOD" == "http01" ]]; then
    echo 80
  fi
  if [[ -n "$HTTPS_PORT" ]]; then
    echo "$HTTPS_PORT"
  fi
}

# ---------- Apache ports and vhosts ----------
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

vhost_redirect_block() { # host target_base  (e.g. https://bot.example.com)
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

vhost_ssl_block() { # host port
  cat <<EOF
<VirtualHost *:$2>
    ServerName $1
    DocumentRoot ${INSTALL_DIR}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$1/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$1/privkey.pem
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

activate_site() {
  a2ensite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
}

# Validates the Apache configuration, then starts or reloads Apache and checks it is running.
apache_apply() {
  if ! apache2ctl configtest >>"$LOG_FILE" 2>&1; then
    err "Apache configuration is invalid. Details: ${LOG_FILE}"
    return 1
  fi
  if systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  else
    systemctl start apache2 >>"$LOG_FILE" 2>&1
  fi
  sleep 1
  if ! systemctl is-active --quiet apache2; then
    err "Apache did not start. Recent errors:"
    journalctl -u apache2 -n 10 --no-pager >&2
    return 1
  fi
  return 0
}

fix_permissions() {
  mkdir -p "$INSTALL_DIR/storage"
  chown -R www-data:www-data "$INSTALL_DIR"
  chmod -R u+rwX,g+rwX "$INSTALL_DIR/storage"
}

# ---------- Dependencies ----------
block_service_autostart() {
  printf '#!/bin/sh\nexit 101\n' > "$POLICY_FILE" && chmod +x "$POLICY_FILE"
}

install_dependencies() {
  info "Installing dependencies (Apache, PHP, Git, Certbot). This may take a few minutes..."
  export DEBIAN_FRONTEND=noninteractive
  local created_policy=false rc=0
  # Stop apt from starting Apache on port 80 during installation; the manager starts it on the right ports.
  if [[ ! -e "$POLICY_FILE" ]]; then
    block_service_autostart
    created_policy=true
  fi
  apt-get update -y >>"$LOG_FILE" 2>&1 || rc=1
  if [[ $rc -eq 0 ]]; then
    apt-get install -y apache2 git curl unzip certbot python3-certbot-apache \
      php libapache2-mod-php php-mysql php-curl php-mbstring php-xml >>"$LOG_FILE" 2>&1 || rc=1
  fi
  if [[ "$created_policy" == true ]]; then
    rm -f "$POLICY_FILE"
  fi
  if [[ $rc -ne 0 ]]; then
    err "Dependency installation failed. Details: ${LOG_FILE}"
    return 1
  fi
  if ! php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    err "This server's PHP version is below 8.0. The bot requires PHP 8.0 or newer."
    return 1
  fi
  a2enmod rewrite headers ssl >>"$LOG_FILE" 2>&1
  systemctl enable apache2 >>"$LOG_FILE" 2>&1
  ok "Dependencies are ready."
  return 0
}

install_cloudflare_plugin() {
  info "Installing the Cloudflare DNS plugin for Certbot..."
  if apt-get install -y python3-certbot-dns-cloudflare >>"$LOG_FILE" 2>&1; then
    return 0
  fi
  err "Could not install python3-certbot-dns-cloudflare. Details: ${LOG_FILE}"
  return 1
}

write_cloudflare_credentials() {
  local token
  read -r -s -p "  Cloudflare API token (input is hidden; needs Zone:DNS:Edit): " token
  echo
  token="${token// /}"
  if [[ -z "$token" ]]; then
    err "No token was entered."
    return 1
  fi
  mkdir -p "$(dirname "$CF_CREDS")"
  ( umask 077; printf 'dns_cloudflare_api_token = %s\n' "$token" > "$CF_CREDS" )
  chmod 600 "$CF_CREDS"
  ok "Cloudflare credentials saved (readable by root only)."
  return 0
}

install_deploy_hook() {
  mkdir -p "$(dirname "$DEPLOY_HOOK")"
  cat > "$DEPLOY_HOOK" <<'EOF'
#!/usr/bin/env bash
systemctl reload apache2
EOF
  chmod +x "$DEPLOY_HOOK"
}

# ---------- Certificates ----------
cert_file() {
  echo "/etc/letsencrypt/live/$1/fullchain.pem"
}

obtain_certificate() {
  local ready=false
  case "$CERT_METHOD" in
    http01)
      mkdir -p "${INSTALL_DIR}/.well-known/acme-challenge"
      info "Requesting a certificate from Let's Encrypt (HTTP-01 on port 80)..."
      if certbot certonly --webroot -w "$INSTALL_DIR" -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --non-interactive --keep-until-expiring >>"$LOG_FILE" 2>&1; then
        ready=true
      fi
      ;;
    cloudflare)
      install_cloudflare_plugin || return 1
      write_cloudflare_credentials || return 1
      info "Requesting a certificate via Cloudflare DNS (no open port needed)..."
      if certbot certonly --dns-cloudflare --dns-cloudflare-credentials "$CF_CREDS" \
          --dns-cloudflare-propagation-seconds 60 -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --non-interactive --keep-until-expiring >>"$LOG_FILE" 2>&1; then
        ready=true
      fi
      ;;
    manual)
      warn "Certbot will now show a TXT record. Add it to your DNS, wait about a minute, then press Enter."
      warn "Automatic renewal is not possible in this mode. Renew the certificate by hand every 90 days."
      if certbot certonly --manual --preferred-challenges dns -d "$DOMAIN" -m "$EMAIL" \
          --agree-tos --keep-until-expiring; then
        ready=true
      fi
      ;;
  esac
  if [[ "$ready" == true && -f "$(cert_file "$DOMAIN")" ]]; then
    ok "SSL certificate is ready."
    return 0
  fi
  err "Could not obtain an SSL certificate. Details: ${LOG_FILE}"
  return 1
}

choose_dns_provider() {
  echo "    1) Cloudflare                  (automatic renewal)"
  echo "    2) Another DNS provider        (manual renewal every 90 days)"
  echo "    3) Cancel the installation"
  echo
  local c
  while true; do
    read -r -p "  Select your DNS provider [1-3]: " c
    case "$c" in
      1) CERT_METHOD="cloudflare"; return 0 ;;
      2) CERT_METHOD="manual"; return 0 ;;
      3) return 1 ;;
      *) warn "Enter 1, 2 or 3." ;;
    esac
  done
}

# ---------- Planning (every decision is asked explicitly) ----------
plan_network() {
  step "Step 1 of 4 — Domain"
  if ask_yes_no "Do you have a domain name that points to this server? (required for HTTPS and Telegram)"; then
    DOMAIN=$(ask_domain)
    check_dns "$DOMAIN" || { err "Installation cancelled."; return 1; }
    EMAIL=$(ask_email)
    PUBLIC_HOST="$DOMAIN"
  else
    DOMAIN=""
    PUBLIC_HOST=$(server_public_ip)
    if [[ -z "$PUBLIC_HOST" ]]; then
      err "Could not detect the server's public IP."
      return 1
    fi
  fi

  step "Step 2 of 4 — Ports and certificate"
  show_port_table
  echo

  if [[ -z "$DOMAIN" ]]; then
    if ! port_usable 80; then
      err "Port 80 is in use by $(port_owner 80). Without a domain the bot needs port 80."
      err "Add a domain name, or free port 80 first."
      return 1
    fi
    warn "Without a domain the bot runs over plain HTTP on port 80."
    warn "Telegram does not accept plain HTTP webhooks, so the bot will not receive messages until you add a domain and SSL."
    if ! ask_yes_no "Do you want to use port 80 and continue with HTTP only?"; then
      err "Installation cancelled."
      return 1
    fi
    CERT_METHOD="none"
    HTTPS_PORT=""
    SCHEME="http"
    return 0
  fi

  # Certificate method: port 80 first, DNS challenge as the alternative.
  if port_usable 80; then
    if ask_yes_no "Port 80 is free. Do you want to use port 80 to get the SSL certificate automatically? (recommended)"; then
      CERT_METHOD="http01"
    else
      info "Port 80 will not be used. The certificate will use a DNS challenge instead."
    fi
  else
    warn "Port 80 is in use by $(port_owner 80). It will not be used or stopped."
    info "The certificate will use a DNS challenge, which does not need port 80."
  fi

  if [[ -z "$CERT_METHOD" ]]; then
    if ! choose_dns_provider; then
      err "Installation cancelled."
      return 1
    fi
  fi

  # HTTPS port: 443 first, 8443 as the alternative.
  if port_usable 443; then
    if ask_yes_no "Port 443 is free. Use port 443 for the bot's HTTPS? (recommended)"; then
      HTTPS_PORT=443
    fi
  else
    warn "Port 443 is in use by $(port_owner 443)."
  fi

  if [[ -z "$HTTPS_PORT" ]]; then
    if port_usable 8443; then
      if ask_yes_no "Use port 8443 for the bot's HTTPS instead? (the address will include :8443)"; then
        HTTPS_PORT=8443
      fi
    else
      warn "Port 8443 is also in use by $(port_owner 8443)."
    fi
  fi

  if [[ -z "$HTTPS_PORT" ]]; then
    err "No HTTPS port is available. Ports 443 and 8443 are both in use or were declined."
    return 1
  fi

  SCHEME="https"
  return 0
}

public_base() {
  if [[ "$SCHEME" == "http" ]]; then
    echo "http://${PUBLIC_HOST}"
  else
    https_base "$PUBLIC_HOST" "$HTTPS_PORT"
  fi
}

show_plan() {
  echo
  echo -e "  ${C_BOLD}Installation plan${C_RESET}"
  echo "    Domain       : ${DOMAIN:-none (server IP)}"
  case "$CERT_METHOD" in
    none)       echo "    SSL          : none (HTTP only)" ;;
    http01)     echo "    SSL          : Let's Encrypt, HTTP-01 on port 80 (auto renewal)" ;;
    cloudflare) echo "    SSL          : Let's Encrypt, DNS-01 via Cloudflare (auto renewal)" ;;
    manual)     echo "    SSL          : Let's Encrypt, DNS-01 manual (renew every 90 days by hand)" ;;
  esac
  if [[ "$CERT_METHOD" == "none" || "$CERT_METHOD" == "http01" ]]; then
    echo "    Port 80      : used"
  else
    echo "    Port 80      : not used"
  fi
  echo "    HTTPS port   : ${HTTPS_PORT:-none}"
  echo "    Address      : $(public_base)/install.php"
  echo
}

# ---------- Rollback ----------
rollback_install() {
  warn "Rolling back the changes made in this attempt..."
  a2dissite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
  rm -f "$VHOST_FILE"
  restore_ports_conf
  if apache2ctl configtest >>"$LOG_FILE" 2>&1 && systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  fi
  rm -rf "$INSTALL_DIR"
  ok "Rollback complete. The server is back to its previous web configuration."
}

# ---------- Verification ----------
health_check() {
  local code external
  if [[ "$SCHEME" == "https" ]]; then
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 \
      --resolve "${DOMAIN}:${HTTPS_PORT}:127.0.0.1" \
      "https://${DOMAIN}:${HTTPS_PORT}/install.php")
  else
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
      -H "Host: ${PUBLIC_HOST}" "http://127.0.0.1/install.php")
  fi
  case "$code" in
    200|301|302|303|307|308) ok "Local check passed (HTTP ${code})." ;;
    *) warn "Local check returned HTTP ${code}. Check the logs (main menu, option 4)." ;;
  esac

  if [[ "$SCHEME" == "https" ]]; then
    external=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$(public_base)/install.php")
    if [[ "$external" == "000" ]]; then
      warn "The address is not reachable from the internet yet. Check the cloud provider's firewall and port ${HTTPS_PORT}."
    else
      ok "Address is reachable from the internet (HTTP ${external})."
    fi
  fi
}

# ---------- Banner ----------
show_banner() {
  clear
  local local_ver
  echo -e "${C_CYAN}${C_BOLD}"
  echo "   ╔══════════════════════════════════════════════╗"
  echo "   ║                 SIMORGH BOT                  ║"
  echo "   ║              Server Manager Tool             ║"
  echo "   ╚══════════════════════════════════════════════╝"
  echo -e "${C_RESET}"
  echo -e "   ${C_BOLD}Channel:${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_BOLD}Group  :${C_RESET} ${GROUP_URL}"
  echo
  if is_installed; then
    local_ver=$(read_local_version)
    echo -e "   ${C_BOLD}Status :${C_RESET} ${C_GREEN}Installed${C_RESET}"
    echo -e "   ${C_BOLD}Version:${C_RESET} v${local_ver:-unknown}"
    if [[ -n "$REMOTE_VERSION" && -n "$local_ver" && "$REMOTE_VERSION" != "$local_ver" ]]; then
      echo -e "   ${C_YELLOW}New version v${REMOTE_VERSION} is available. Choose option 3.${C_RESET}"
    fi
  else
    echo -e "   ${C_BOLD}Status :${C_RESET} ${C_RED}Not installed${C_RESET}"
    [[ -n "$REMOTE_VERSION" ]] && echo -e "   ${C_BOLD}Version:${C_RESET} v${REMOTE_VERSION} (latest)"
  fi
  echo
}

# ---------- Backup ----------
make_backup() {
  mkdir -p "$BACKUP_DIR"
  local file="$BACKUP_DIR/simorgh-$(date +%Y%m%d-%H%M%S).tar.gz"
  local items=()
  [[ -f "$INSTALL_DIR/config.php" ]] && items+=("config.php")
  [[ -f "$INSTALL_DIR/installed.lock" ]] && items+=("installed.lock")
  [[ -d "$INSTALL_DIR/storage" ]] && items+=("storage")
  if [[ ${#items[@]} -eq 0 ]]; then
    warn "No files found to back up."
    return 0
  fi
  if tar -czf "$file" -C "$INSTALL_DIR" "${items[@]}" 2>/dev/null; then
    chmod 600 "$file"
    ok "Backup saved: ${file}"
    return 0
  fi
  err "Backup failed."
  return 1
}

# ---------- External drive ----------
choose_external_drive() {
  local mounts=() m n i dest
  while IFS= read -r m; do
    [[ -n "$m" ]] && mounts+=("$m")
  done < <(findmnt -rn -o TARGET 2>/dev/null | grep -E '^/(media|mnt)/' | sort -u)

  if [[ ${#mounts[@]} -eq 0 ]]; then
    warn "No external drive detected. The install info is saved at ${INFO_FILE}."
    return 1
  fi

  echo "  Connected drives:"
  i=1
  for m in "${mounts[@]}"; do
    echo "    ${i}) ${m}"
    i=$((i + 1))
  done
  echo
  read -r -p "  Drive number to copy the install info to (Enter = skip): " n
  [[ -z "$n" ]] && return 1
  if ! [[ "$n" =~ ^[0-9]+$ ]] || (( n < 1 || n > ${#mounts[@]} )); then
    warn "Invalid drive number."
    return 1
  fi
  dest="${mounts[$((n - 1))]}/simorgh-bot"
  if mkdir -p "$dest" && cp "$INFO_FILE" "$dest/" 2>/dev/null; then
    sync
    ok "Install info copied to ${dest}/"
    return 0
  fi
  err "Could not copy to the drive. Copy ${INFO_FILE} manually."
  return 1
}

show_success() {
  local url="$1" ver
  ver=$(read_local_version)
  echo
  echo -e "  ${C_GREEN}${C_BOLD}The bot (v${ver:-?}) has been successfully deployed.${C_RESET}"
  echo
  echo -e "  ${C_BOLD}1) Installation address:${C_RESET}"
  echo -e "     ${C_CYAN}${url}/install.php${C_RESET}"
  echo
  echo -e "  ${C_BOLD}2) Log in and complete the setup:${C_RESET}"
  echo "     - Open the address above in your browser."
  echo "     - Log in and enter the bot details:"
  echo "       Bot token (from BotFather), numeric admin ID,"
  echo "       MySQL database details, and panel username and password."
  echo
  echo -e "  ${C_BOLD}3) Security:${C_RESET} After setup, delete install.php and selftest.php from the server."
  echo

  cat > "$INFO_FILE" <<EOF
Simorgh Bot — Install Info
Date        : $(date '+%F %T')
Version     : v${ver:-unknown}
Install URL : ${url}/install.php
EOF
  chmod 600 "$INFO_FILE"
  ok "Install info saved to ${INFO_FILE}"
  choose_external_drive
}

# ---------- Option 1: Install ----------
do_install() {
  show_banner
  if is_installed; then
    warn "The bot is already installed. Use option 3 to update it."
    pause; return
  fi
  if [[ -e "$INSTALL_DIR" ]]; then
    err "${INSTALL_DIR} exists but the installation is incomplete. Remove it first (option 2 or manually)."
    pause; return
  fi
  if [[ -z "$REMOTE_VERSION" ]]; then
    err "Could not reach GitHub. Check the server's internet connection."
    pause; return
  fi

  if ! plan_network; then
    pause; return
  fi

  show_plan
  if ! ask_yes_no "Proceed with this plan?"; then
    info "Installation cancelled. Nothing was changed."
    pause; return
  fi

  step "Step 3 of 4 — Installing the bot"
  if ! install_dependencies; then
    pause; return
  fi

  local p ports_list=()
  mapfile -t ports_list < <(required_public_ports)
  for p in "${ports_list[@]}"; do
    ensure_firewall_port "$p"
  done

  info "Downloading the bot code (v${REMOTE_VERSION}) from GitHub..."
  if ! git clone --quiet "$REPO_URL" "$INSTALL_DIR" >>"$LOG_FILE" 2>&1; then
    err "Cloning from GitHub failed. Check the internet connection."
    rm -rf "$INSTALL_DIR"
    pause; return
  fi
  if [[ ! -f "$INSTALL_DIR/install.php" ]]; then
    err "The downloaded code is incomplete (install.php is missing)."
    rollback_install
    pause; return
  fi
  mark_safe
  fix_permissions
  ok "Bot files downloaded."

  # Stage 1: Apache serves the bot on port 80 (for HTTP, or for the HTTP-01 challenge).
  # DNS challenge methods do not need Apache before the certificate exists.
  if [[ "$CERT_METHOD" == "none" || "$CERT_METHOD" == "http01" ]]; then
    local stage_host="$PUBLIC_HOST"
    [[ "$CERT_METHOD" == "http01" ]] && stage_host="$DOMAIN"
    write_ports_conf 80
    vhost_app_block "$stage_host" 80 > "$VHOST_FILE"
    activate_site
    if ! apache_apply; then
      rollback_install
      pause; return
    fi
    ok "Apache is running on port 80."
  fi

  # Stage 2: HTTPS certificate and final Apache configuration.
  if [[ "$CERT_METHOD" != "none" ]]; then
    step "Step 4 of 4 — SSL certificate"
    if ! obtain_certificate; then
      rollback_install
      pause; return
    fi
    if [[ "$CERT_METHOD" == "http01" || "$CERT_METHOD" == "cloudflare" ]]; then
      install_deploy_hook
    fi

    if [[ "$CERT_METHOD" == "http01" ]]; then
      write_ports_conf 80 "$HTTPS_PORT"
      {
        vhost_redirect_block "$DOMAIN" "$(https_base "$DOMAIN" "$HTTPS_PORT")"
        vhost_ssl_block "$DOMAIN" "$HTTPS_PORT"
      } > "$VHOST_FILE"
    else
      write_ports_conf "$HTTPS_PORT"
      vhost_ssl_block "$DOMAIN" "$HTTPS_PORT" > "$VHOST_FILE"
    fi
    activate_site

    if ! apache_apply; then
      rollback_install
      pause; return
    fi
    ok "HTTPS is active on port ${HTTPS_PORT}."
  fi

  step "Verification"
  health_check
  show_success "$(public_base)"
  pause
}

# ---------- Option 2: Remove ----------
do_uninstall() {
  show_banner
  if ! is_installed; then
    warn "The bot is not installed."
    pause; return
  fi

  warn "This removes the bot code, its Apache site and the storage folder."
  if ! ask_yes_no "Remove the bot completely? (y = remove, n = back to menu)"; then
    info "Returning to the main menu."
    pause; return
  fi

  if ! make_backup; then
    ask_yes_no "No backup was created. Continue anyway?" || { info "Removal cancelled."; pause; return; }
  fi

  local host=""
  if [[ -f "$VHOST_FILE" ]]; then
    host=$(awk '/ServerName/{print $2; exit}' "$VHOST_FILE")
  fi

  a2dissite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
  rm -f "$VHOST_FILE"
  restore_ports_conf
  if apache2ctl configtest >>"$LOG_FILE" 2>&1 && systemctl is-active --quiet apache2; then
    systemctl reload apache2 >>"$LOG_FILE" 2>&1
  fi
  rm -rf "$INSTALL_DIR"
  ok "Bot files and Apache site removed."

  if [[ -n "$host" && -d "/etc/letsencrypt/live/${host}" ]]; then
    if ask_yes_no "Also remove the SSL certificate for ${host}? (recommended)"; then
      if certbot delete --cert-name "$host" --non-interactive >>"$LOG_FILE" 2>&1; then
        rm -f "$DEPLOY_HOOK" "$CF_CREDS"
        ok "SSL certificate removed."
      else
        warn "Could not remove the SSL certificate. Details: ${LOG_FILE}"
      fi
    else
      warn "The certificate was kept. Its automatic renewal may fail because the bot folder is gone."
    fi
  fi

  warn "The MySQL database was not removed. Delete it from phpMyAdmin if needed."
  ok "Removal complete."
  pause
}

# ---------- Option 3: Update ----------
do_update() {
  show_banner
  if ! is_installed; then
    warn "The bot is not installed."
    pause; return
  fi

  mark_safe
  info "Checking for a new version..."
  if ! git -C "$INSTALL_DIR" fetch --quiet origin main >>"$LOG_FILE" 2>&1; then
    err "Update check failed. Check the internet connection."
    pause; return
  fi

  local local_rev remote_rev local_ver new_ver
  local_rev=$(git -C "$INSTALL_DIR" rev-parse HEAD)
  remote_rev=$(git -C "$INSTALL_DIR" rev-parse origin/main)
  local_ver=$(read_local_version)
  new_ver=$(git -C "$INSTALL_DIR" show origin/main:src/bootstrap.php 2>/dev/null | parse_version)

  if [[ "$local_rev" == "$remote_rev" ]]; then
    ok "The bot is up to date (v${local_ver:-?})."
    pause; return
  fi

  echo
  echo -e "  ${C_BOLD}Current version:${C_RESET} v${local_ver:-?}"
  echo -e "  ${C_BOLD}New version    :${C_RESET} v${new_ver:-?}"
  echo
  info "Recent changes:"
  git -C "$INSTALL_DIR" log --oneline HEAD..origin/main | head -n 15 | sed 's/^/     /' >&2
  echo

  if ! ask_yes_no "Apply the update now?"; then
    info "Update cancelled."
    pause; return
  fi

  make_backup
  if ! git -C "$INSTALL_DIR" pull --ff-only --quiet origin main >>"$LOG_FILE" 2>&1; then
    err "Update failed. There may be local changes to the files."
    pause; return
  fi
  fix_permissions
  if ! apache_apply; then
    warn "Apache could not be reloaded. Check the logs (main menu, option 4)."
  fi
  REMOTE_VERSION="${new_ver:-$REMOTE_VERSION}"
  ok "The bot has been updated to v${new_ver:-?}."

  if [[ -f "$INSTALL_DIR/install.php" ]]; then
    warn "install.php came back with the update. Delete it from the server after checking."
  fi
  pause
}

# ---------- Option 4: Logs ----------
do_logs() {
  show_banner
  echo "    1) Application log"
  echo "    2) Apache error log"
  echo "    3) Apache access log"
  echo "    4) Script log (this manager)"
  echo "    5) Back"
  echo
  read -r -p "  Select [1-5]: " choice
  local file
  case "$choice" in
    1)
      if ! is_installed; then warn "The bot is not installed."; pause; return; fi
      file=$(ls -t "$INSTALL_DIR"/storage/logs/*.log 2>/dev/null | head -n 1)
      echo
      if [[ -z "$file" ]]; then
        warn "No application log file found."
      else
        info "File: ${file}"
        tail -n "$LOG_LINES" "$file"
      fi
      pause ;;
    2)
      echo
      if [[ -f "/var/log/apache2/${VHOST_NAME}-error.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-error.log"
      elif [[ -f "/var/log/apache2/${VHOST_NAME}-ssl-error.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-ssl-error.log"
      else
        warn "No bot error log found."
      fi
      pause ;;
    3)
      echo
      if [[ -f "/var/log/apache2/${VHOST_NAME}-access.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-access.log"
      elif [[ -f "/var/log/apache2/${VHOST_NAME}-ssl-access.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-ssl-access.log"
      else
        warn "No bot access log found."
      fi
      pause ;;
    4)
      echo
      if [[ -f "$LOG_FILE" ]]; then
        tail -n "$LOG_LINES" "$LOG_FILE"
      else
        warn "No script log found."
      fi
      pause ;;
    5) return ;;
    *) warn "Invalid option."; sleep 1 ;;
  esac
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
  echo -e "   Whenever you need Simorgh, just run:"
  echo
  echo -e "       ${C_YELLOW}${C_BOLD}sudo simorgh${C_RESET}"
  echo
  echo -e "   ${C_CYAN}Channel:${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_CYAN}Group  :${C_RESET} ${GROUP_URL}"
  echo
}

# ---------- Main menu ----------
main_menu() {
  while true; do
    show_banner
    echo "    1) Install the bot"
    echo "    2) Remove the bot completely"
    echo "    3) Update the bot"
    echo "    4) Show logs"
    echo "    5) Exit"
    echo
    read -r -p "  Select an option [1-5]: " choice
    case "$choice" in
      1) do_install ;;
      2) do_uninstall ;;
      3) do_update ;;
      4) do_logs ;;
      5) farewell; exit 0 ;;
      *) warn "Invalid option."; sleep 1 ;;
    esac
  done
}

main() {
  require_root
  require_apt
  trap 'echo; warn "Operation cancelled."; farewell; exit 130' INT
  mkdir -p "$(dirname "$LOG_FILE")"
  touch "$LOG_FILE" && chmod 600 "$LOG_FILE"
  log "---- Session started ----"
  install_self
  show_banner
  info "Fetching version information from GitHub..."
  REMOTE_VERSION=$(fetch_remote_version)
  main_menu
}

if [[ "${BASH_SOURCE[0]:-$0}" == "$0" ]]; then
  main "$@"
fi
