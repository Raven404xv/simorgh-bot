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
BACKUP_DIR="/root/simorgh-backups"
INFO_FILE="/root/simorgh-install-info.txt"
LOG_FILE="/var/log/simorgh-manager.log"
DEPLOY_HOOK="/etc/letsencrypt/renewal-hooks/deploy/simorgh-reload-apache.sh"
CHANNEL_URL="https://t.me/SimorghFree"
GROUP_URL="https://t.me/SimorghBotGroup"
LOG_LINES=100
REMOTE_VERSION=""

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

# ---------- Port helpers ----------
# Prints the name of the process listening on a TCP port.
# Returns 1 (and prints nothing) if the port is free.
port_owner() {
  local port="$1" line name
  line=$(ss -H -ltnp "( sport = :${port} )" 2>/dev/null | head -n 1)
  [[ -z "$line" ]] && return 1
  name=$(sed -n 's/.*users:(("\([^"]*\)".*/\1/p' <<< "$line")
  echo "${name:-unknown}"
  return 0
}

show_port_status() {
  local port name
  for port in 80 443; do
    if name=$(port_owner "$port"); then
      if [[ "$name" == "apache2" ]]; then
        ok "Port ${port}: used by Apache (this bot's web server)"
      else
        warn "Port ${port}: in use by ${name}"
      fi
    else
      ok "Port ${port}: free"
    fi
  done
}

free_port() {
  local port="$1" name
  name=$(port_owner "$port") || return 0
  [[ "$name" == "apache2" ]] && return 0
  info "Stopping ${name} to free port ${port}..."
  systemctl stop "$name" >>"$LOG_FILE" 2>&1
  sleep 2
  if port_owner "$port" >/dev/null; then
    err "Port ${port} is still in use by ${name}."
    return 1
  fi
  ok "Port ${port} is now free."
  warn "${name} is stopped for now. It may start again after a reboot unless you disable it."
  return 0
}

prepare_ports() {
  local need_ssl="$1" port name
  local ports=(80)
  [[ "$need_ssl" == true ]] && ports+=(443)

  for port in "${ports[@]}"; do
    name=$(port_owner "$port") || continue
    if [[ "$name" == "apache2" ]]; then
      ok "Port ${port} is already used by Apache."
      continue
    fi
    warn "Port ${port} is in use by: ${name}"
    if ask_yes_no "Stop ${name} so the bot can use port ${port}?"; then
      free_port "$port" || return 1
    else
      err "Installation stopped because port ${port} is busy."
      return 1
    fi
  done
  return 0
}

# ---------- Dependencies and Apache ----------
install_dependencies() {
  info "Installing dependencies (Apache, PHP, Git, Certbot). This may take a few minutes..."
  export DEBIAN_FRONTEND=noninteractive
  if ! apt-get update -y >>"$LOG_FILE" 2>&1; then
    err "apt-get update failed. Details: ${LOG_FILE}"
    return 1
  fi
  if ! apt-get install -y apache2 git curl unzip certbot python3-certbot-apache \
      php libapache2-mod-php php-mysql php-curl php-mbstring php-xml >>"$LOG_FILE" 2>&1; then
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

# Validates the Apache configuration, then starts or reloads Apache and verifies it is running.
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

# mode: http  -> serve the bot on port 80
#       https -> redirect port 80 to HTTPS and serve the bot on port 443
write_vhost() {
  local host="$1" mode="$2"
  if [[ "$mode" == "https" ]]; then
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName ${host}
    DocumentRoot ${INSTALL_DIR}

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerName ${host}
    DocumentRoot ${INSTALL_DIR}

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${host}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${host}/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-ssl-access.log combined
</VirtualHost>
EOF
  else
    cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName ${host}
    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-access.log combined
</VirtualHost>
EOF
  fi
  a2ensite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
}

# ---------- SSL ----------
install_deploy_hook() {
  mkdir -p "$(dirname "$DEPLOY_HOOK")"
  cat > "$DEPLOY_HOOK" <<'EOF'
#!/usr/bin/env bash
systemctl reload apache2
EOF
  chmod +x "$DEPLOY_HOOK"
}

# Uses the webroot method: Certbot writes a challenge file into the bot folder,
# so Apache must already be serving port 80 for the domain.
issue_certificate() {
  local host="$1" email="$2"
  mkdir -p "${INSTALL_DIR}/.well-known/acme-challenge"
  info "Requesting an SSL certificate for ${host} from Let's Encrypt..."
  if certbot certonly --webroot -w "$INSTALL_DIR" -d "$host" -m "$email" \
      --agree-tos --non-interactive --keep-until-expiring >>"$LOG_FILE" 2>&1 \
     && [[ -f "/etc/letsencrypt/live/${host}/fullchain.pem" ]]; then
    install_deploy_hook
    ok "SSL certificate issued. It will renew automatically."
    return 0
  fi
  err "Could not obtain an SSL certificate. Details: ${LOG_FILE}"
  warn "Check that the domain's A record points to this server and that port 80 is reachable from the internet."
  return 1
}

# ---------- Verification ----------
health_check() {
  local host="$1" code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
    -H "Host: ${host}" "http://127.0.0.1/install.php")
  case "$code" in
    200|301|302|303|307|308)
      ok "Local health check passed (HTTP ${code})."
      return 0 ;;
    *)
      warn "Local health check returned HTTP ${code}. Check the logs (main menu, option 4)."
      return 1 ;;
  esac
}

rollback_install() {
  warn "Rolling back the partial installation..."
  a2dissite "${VHOST_NAME}.conf" >>"$LOG_FILE" 2>&1
  rm -f "$VHOST_FILE"
  systemctl is-active --quiet apache2 && systemctl reload apache2 >>"$LOG_FILE" 2>&1
  rm -rf "$INSTALL_DIR"
  ok "Rollback complete."
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

  step "Step 1 of 4 — Domain and network"
  show_port_status
  echo

  local host="" email="" ssl_wanted=false
  if ask_yes_no "Do you have a domain name that points to this server? (required for SSL)"; then
    host=$(ask_domain)
    local ip_domain ip_server
    ip_domain=$(domain_ipv4 "$host")
    ip_server=$(server_public_ip)
    if [[ -z "$ip_domain" ]]; then
      warn "${host} does not resolve yet. Add an A record first, or wait for DNS to update."
      ask_yes_no "Continue anyway?" || { pause; return; }
    elif [[ -n "$ip_server" && "$ip_domain" != "$ip_server" ]]; then
      warn "${host} points to ${ip_domain}, but this server's IP is ${ip_server}."
      ask_yes_no "Continue anyway?" || { pause; return; }
    else
      ok "${host} points to this server."
    fi
    ssl_wanted=true
    email=$(ask_email)
  else
    host=$(server_public_ip)
    if [[ -z "$host" ]]; then
      err "Could not detect the server's public IP."
      pause; return
    fi
    warn "No domain: the bot will run over HTTP at http://${host}"
    warn "Telegram webhooks require HTTPS. Add a domain and SSL later."
  fi

  step "Step 2 of 4 — Port check"
  prepare_ports "$ssl_wanted" || { pause; return; }
  ok "Ports are ready."

  step "Step 3 of 4 — Installing the bot"
  install_dependencies || { pause; return; }

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

  write_vhost "$host" http
  if ! apache_apply; then
    rollback_install
    pause; return
  fi
  ok "Apache is running."

  local scheme="http"
  if [[ "$ssl_wanted" == true ]]; then
    step "Step 4 of 4 — SSL certificate"
    if issue_certificate "$host" "$email"; then
      write_vhost "$host" https
      if apache_apply; then
        scheme="https"
        ok "HTTPS is active."
      else
        warn "HTTPS could not be enabled. Switching back to HTTP."
        write_vhost "$host" http
        apache_apply || warn "Apache could not be restarted with the HTTP configuration either."
      fi
    else
      warn "Continuing over HTTP. Telegram will not reach the bot until HTTPS is active."
    fi
  fi

  step "Verification"
  health_check "$host"

  show_success "${scheme}://${host}"
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
  systemctl is-active --quiet apache2 && systemctl reload apache2 >>"$LOG_FILE" 2>&1
  rm -rf "$INSTALL_DIR"
  ok "Bot files and Apache site removed."

  if [[ -n "$host" && -d "/etc/letsencrypt/live/${host}" ]]; then
    if ask_yes_no "Also remove the SSL certificate for ${host}? (recommended)"; then
      if certbot delete --cert-name "$host" --non-interactive >>"$LOG_FILE" 2>&1; then
        rm -f "$DEPLOY_HOOK"
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
  apache_apply || warn "Apache could not be reloaded. Check the logs (main menu, option 4)."
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
      else
        warn "No bot error log found."
      fi
      pause ;;
    3)
      echo
      if [[ -f "/var/log/apache2/${VHOST_NAME}-access.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-access.log"
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
