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

REPO_URL="https://github.com/Raven404xv/simorgh-bot.git"
RAW_BASE="https://raw.githubusercontent.com/Raven404xv/simorgh-bot/main"
COMMAND_PATH="/usr/local/bin/simorgh"
INSTALL_DIR="/var/www/simorgh-bot"
VHOST_NAME="simorgh-bot"
VHOST_FILE="/etc/apache2/sites-available/${VHOST_NAME}.conf"
BACKUP_DIR="/root/simorgh-backups"
INFO_FILE="/root/simorgh-install-info.txt"
CHANNEL_URL="https://t.me/SimorghFree"
GROUP_URL="https://t.me/SimorghBotGroup"
LOG_LINES=100
REMOTE_VERSION=""

C_RED='\033[0;31m'; C_GREEN='\033[0;32m'; C_YELLOW='\033[1;33m'
C_CYAN='\033[0;36m'; C_BOLD='\033[1m'; C_RESET='\033[0m'

ok()    { echo -e "  ${C_GREEN}✔${C_RESET} $*" >&2; }
warn()  { echo -e "  ${C_YELLOW}⚠${C_RESET} $*" >&2; }
err()   { echo -e "  ${C_RED}✘${C_RESET} $*" >&2; }
info()  { echo -e "  ${C_CYAN}➜${C_RESET} $*" >&2; }
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
  [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]
}

valid_email() {
  [[ "$1" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]
}

ask_domain() {
  local d
  while true; do
    read -r -p "  Bot domain (e.g. bot.example.com): " d
    d="${d,,}"
    if valid_domain "$d"; then
      echo "$d"
      return 0
    fi
    warn "Enter a valid domain, without http:// and without a trailing /."
  done
}

mark_safe() {
  git config --system --add safe.directory "$INSTALL_DIR" 2>/dev/null
}

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

# ---------- Dependencies and server setup ----------
install_dependencies() {
  info "Installing dependencies (Apache, PHP, Git, Certbot). This may take a few minutes..."
  export DEBIAN_FRONTEND=noninteractive
  if ! apt-get update -y >/dev/null 2>&1; then
    err "apt-get update failed."
    return 1
  fi
  if ! apt-get install -y apache2 git curl unzip certbot python3-certbot-apache \
      php libapache2-mod-php php-mysql php-curl php-mbstring php-xml >/dev/null 2>&1; then
    err "Dependency installation failed."
    return 1
  fi
  if ! php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    err "This server's PHP version is below 8.0. The bot requires PHP 8.0 or newer."
    return 1
  fi
  a2enmod rewrite headers >/dev/null 2>&1
  systemctl enable apache2 >/dev/null 2>&1
  systemctl restart apache2 >/dev/null 2>&1
  ok "Dependencies are ready."
  return 0
}

fix_permissions() {
  mkdir -p "$INSTALL_DIR/storage"
  chown -R www-data:www-data "$INSTALL_DIR"
  chmod -R u+rwX,g+rwX "$INSTALL_DIR/storage"
}

write_vhost() {
  local host="$1"
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
  a2ensite "${VHOST_NAME}.conf" >/dev/null 2>&1
  if ! apache2ctl configtest >/dev/null 2>&1; then
    err "Apache configuration is invalid."
    return 1
  fi
  systemctl reload apache2
  return 0
}

setup_ssl() {
  local domain="$1" email
  while true; do
    read -r -p "  Email for Let's Encrypt: " email
    valid_email "$email" && break
    warn "Enter a valid email address."
  done
  info "Requesting an SSL certificate for ${domain} ..."
  if certbot --apache -d "$domain" -m "$email" --agree-tos --non-interactive --redirect >/dev/null 2>&1; then
    ok "SSL certificate is active."
    return 0
  fi
  err "SSL certificate request failed."
  warn "Make sure the domain's A record points to this server's IP and ports 80 and 443 are open."
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
Channel     : ${CHANNEL_URL}
Group       : ${GROUP_URL}
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
    err "${INSTALL_DIR} exists but the installation is incomplete. Check or remove it first."
    pause; return
  fi
  if [[ -z "$REMOTE_VERSION" ]]; then
    err "Could not reach GitHub. Check the server's internet connection."
    pause; return
  fi

  install_dependencies || { pause; return; }

  echo
  local host="" ssl_wanted=false
  if ask_yes_no "Do you want an SSL certificate (HTTPS) for the bot? (Telegram requires HTTPS)"; then
    ssl_wanted=true
    host=$(ask_domain)
  else
    host=$(hostname -I 2>/dev/null | awk '{print $1}')
    if [[ -z "$host" ]]; then
      err "Could not detect the server IP."
      pause; return
    fi
    warn "Installing without SSL. Temporary address: http://${host}"
    warn "Telegram webhooks only work over HTTPS. Add SSL later."
  fi

  info "Downloading the bot code (v${REMOTE_VERSION}) from GitHub..."
  if ! git clone --quiet "$REPO_URL" "$INSTALL_DIR"; then
    err "Cloning from GitHub failed. Check the internet connection."
    rm -rf "$INSTALL_DIR"
    pause; return
  fi
  mark_safe
  fix_permissions
  ok "Bot files downloaded."

  if ! write_vhost "$host"; then
    pause; return
  fi
  ok "Apache configured."

  local scheme="http"
  if $ssl_wanted; then
    if setup_ssl "$host"; then
      scheme="https"
    else
      warn "Continuing without HTTPS. Telegram will not reach the bot until SSL is active."
    fi
  fi

  show_success "${scheme}://${host}"
  pause
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

# ---------- Option 2: Remove ----------
do_uninstall() {
  show_banner
  if ! is_installed; then
    warn "The bot is not installed."
    pause; return
  fi

  warn "This removes the bot code, the Apache configuration and the storage folder."
  if ! ask_yes_no "Remove the bot completely? (y = remove, n = back to menu)"; then
    info "Returning to the main menu."
    pause; return
  fi

  if ! make_backup; then
    ask_yes_no "No backup was created. Continue anyway?" || { info "Removal cancelled."; pause; return; }
  fi

  local host="" certs=""
  if [[ -f "$VHOST_FILE" ]]; then
    host=$(awk '/ServerName/{print $2; exit}' "$VHOST_FILE")
  fi

  a2dissite "${VHOST_NAME}.conf" >/dev/null 2>&1
  rm -f "$VHOST_FILE"
  systemctl reload apache2 >/dev/null 2>&1
  rm -rf "$INSTALL_DIR"
  ok "Bot files and Apache configuration removed."

  if [[ -n "$host" ]]; then
    certs=$(certbot certificates 2>/dev/null)
    if grep -qF "Domains: ${host}" <<< "$certs"; then
      if ask_yes_no "Also remove the SSL certificate for ${host}?"; then
        if certbot delete --cert-name "$host" --non-interactive >/dev/null 2>&1; then
          ok "SSL certificate removed."
        else
          warn "Could not remove the SSL certificate."
        fi
      fi
    fi
  fi

  warn "The MySQL database was not removed. Delete it from phpMyAdmin if needed."
  ok "Bot removal complete."
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
  if ! git -C "$INSTALL_DIR" fetch --quiet origin main; then
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
  if ! git -C "$INSTALL_DIR" pull --ff-only --quiet origin main; then
    err "Update failed. There may be local changes to the files."
    pause; return
  fi
  fix_permissions
  systemctl reload apache2 >/dev/null 2>&1
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
  if ! is_installed; then
    warn "The bot is not installed."
    pause; return
  fi

  echo "    1) Application log"
  echo "    2) Apache error log"
  echo "    3) Apache access log"
  echo "    4) Back"
  echo
  read -r -p "  Select [1-4]: " choice
  case "$choice" in
    1)
      local file
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
    4) return ;;
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
  install_self
  show_banner
  info "Fetching version information from GitHub..."
  REMOTE_VERSION=$(fetch_remote_version)
  main_menu
}

main "$@"
