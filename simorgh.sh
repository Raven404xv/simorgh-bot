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
pause() { echo; read -r -p "  برای ادامه Enter بزنید..." _; }

ask_yes_no() {
  local answer
  while true; do
    read -r -p "  $1 [y/n]: " answer
    case "${answer,,}" in
      y|yes) return 0 ;;
      n|no)  return 1 ;;
      *) warn "فقط y یا n وارد کنید." ;;
    esac
  done
}

require_root() {
  if [[ $EUID -ne 0 ]]; then
    err "این اسکریپت باید با sudo اجرا شود:  sudo simorgh"
    exit 1
  fi
}

require_apt() {
  if ! command -v apt-get >/dev/null 2>&1; then
    err "این اسکریپت فقط روی Ubuntu و Debian پشتیبانی می‌شود."
    exit 1
  fi
}

# ---------- نسخه ----------
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

# ---------- وضعیت و اعتبارسنجی ----------
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
    read -r -p "  دامنه‌ی ربات (مثلاً bot.example.com): " d
    d="${d,,}"
    if valid_domain "$d"; then
      echo "$d"
      return 0
    fi
    warn "دامنه‌ی معتبر وارد کنید، بدون http:// و بدون /."
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
  echo -e "   ${C_BOLD}کانال :${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_BOLD}گروه  :${C_RESET} ${GROUP_URL}"
  echo
  if is_installed; then
    local_ver=$(read_local_version)
    echo -e "   ${C_BOLD}وضعیت:${C_RESET} ${C_GREEN}نصب شده${C_RESET}"
    echo -e "   ${C_BOLD}نسخه :${C_RESET} v${local_ver:-نامشخص}"
    if [[ -n "$REMOTE_VERSION" && -n "$local_ver" && "$REMOTE_VERSION" != "$local_ver" ]]; then
      echo -e "   ${C_YELLOW}نسخه‌ی جدید v${REMOTE_VERSION} موجود است. گزینه‌ی ۳ را بزنید.${C_RESET}"
    fi
  else
    echo -e "   ${C_BOLD}وضعیت:${C_RESET} ${C_RED}نصب نشده${C_RESET}"
    [[ -n "$REMOTE_VERSION" ]] && echo -e "   ${C_BOLD}نسخه :${C_RESET} v${REMOTE_VERSION} (آخرین نسخه)"
  fi
  echo
}

# ---------- نصب پیش‌نیازها و فایل‌های سرور ----------
install_dependencies() {
  info "نصب پیش‌نیازها (Apache، PHP، Git، Certbot). ممکن است چند دقیقه طول بکشد..."
  export DEBIAN_FRONTEND=noninteractive
  if ! apt-get update -y >/dev/null 2>&1; then
    err "apt-get update ناموفق بود."
    return 1
  fi
  if ! apt-get install -y apache2 git curl unzip certbot python3-certbot-apache \
      php libapache2-mod-php php-mysql php-curl php-mbstring php-xml >/dev/null 2>&1; then
    err "نصب پیش‌نیازها ناموفق بود."
    return 1
  fi
  if ! php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    err "نسخه‌ی PHP این سرور کمتر از 8.0 است. ربات به PHP 8.0 یا جدیدتر نیاز دارد."
    return 1
  fi
  a2enmod rewrite headers >/dev/null 2>&1
  systemctl enable apache2 >/dev/null 2>&1
  systemctl restart apache2 >/dev/null 2>&1
  ok "پیش‌نیازها آماده است."
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
    err "تنظیمات Apache نامعتبر است."
    return 1
  fi
  systemctl reload apache2
  return 0
}

setup_ssl() {
  local domain="$1" email
  while true; do
    read -r -p "  ایمیل برای Let's Encrypt: " email
    valid_email "$email" && break
    warn "ایمیل معتبر وارد کنید."
  done
  info "دریافت گواهی SSL برای ${domain} ..."
  if certbot --apache -d "$domain" -m "$email" --agree-tos --non-interactive --redirect >/dev/null 2>&1; then
    ok "گواهی SSL با موفقیت فعال شد."
    return 0
  fi
  err "دریافت گواهی SSL ناموفق بود."
  warn "مطمئن شوید رکورد A دامنه به IP این سرور اشاره می‌کند و پورت‌های 80 و 443 باز هستند."
  return 1
}

# ---------- هارد اکسترنال ----------
choose_external_drive() {
  local mounts=() m n i dest
  while IFS= read -r m; do
    [[ -n "$m" ]] && mounts+=("$m")
  done < <(findmnt -rn -o TARGET 2>/dev/null | grep -E '^/(media|mnt)/' | sort -u)

  if [[ ${#mounts[@]} -eq 0 ]]; then
    warn "هارد اکسترنال متصل پیدا نشد. فایل اطلاعات در ${INFO_FILE} است."
    return 1
  fi

  echo "  درایوهای متصل:"
  i=1
  for m in "${mounts[@]}"; do
    echo "    ${i}) ${m}"
    i=$((i + 1))
  done
  echo
  read -r -p "  شماره‌ی درایو برای کپی اطلاعات (Enter = رد شدن): " n
  [[ -z "$n" ]] && return 1
  if ! [[ "$n" =~ ^[0-9]+$ ]] || (( n < 1 || n > ${#mounts[@]} )); then
    warn "شماره‌ی نامعتبر است."
    return 1
  fi
  dest="${mounts[$((n - 1))]}/simorgh-bot"
  if mkdir -p "$dest" && cp "$INFO_FILE" "$dest/" 2>/dev/null; then
    sync
    ok "اطلاعات نصب در ${dest}/ کپی شد."
    return 0
  fi
  err "کپی روی درایو انجام نشد. فایل را از ${INFO_FILE} دستی منتقل کنید."
  return 1
}

show_success() {
  local url="$1" ver
  ver=$(read_local_version)
  echo
  echo -e "  ${C_GREEN}${C_BOLD}ربات (نسخه v${ver:-?}) با موفقیت اجرا شد.${C_RESET}"
  echo
  echo -e "  ${C_BOLD}۱) آدرس نصب:${C_RESET}"
  echo -e "     ${C_CYAN}${url}/install.php${C_RESET}"
  echo
  echo -e "  ${C_BOLD}۲) ورود و تکمیل نصب:${C_RESET}"
  echo "     - آدرس بالا را در مرورگر باز کنید."
  echo "     - وارد شوید و مشخصات ربات را وارد کنید:"
  echo "       توکن ربات (از BotFather)، شناسه‌ی عددی ادمین،"
  echo "       مشخصات دیتابیس MySQL، نام کاربری و رمز ورود به پنل."
  echo
  echo -e "  ${C_BOLD}۳) امنیت:${C_RESET} بعد از نصب، فایل‌های install.php و selftest.php را از سرور حذف کنید."
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
  ok "اطلاعات نصب در ${INFO_FILE} ذخیره شد."
  choose_external_drive
}

# ---------- گزینه‌ی ۱: نصب ----------
do_install() {
  show_banner
  if is_installed; then
    warn "ربات از قبل نصب است. برای به‌روزرسانی گزینه‌ی ۳ را بزنید."
    pause; return
  fi
  if [[ -e "$INSTALL_DIR" ]]; then
    err "مسیر ${INSTALL_DIR} وجود دارد ولی نصب کامل نیست. آن را بررسی یا حذف کنید."
    pause; return
  fi
  if [[ -z "$REMOTE_VERSION" ]]; then
    err "اتصال به GitHub برقرار نشد. اینترنت سرور را بررسی کنید."
    pause; return
  fi

  install_dependencies || { pause; return; }

  echo
  local host="" ssl_wanted=false
  if ask_yes_no "آیا برای ربات گواهی SSL (HTTPS) دریافت شود؟ (تلگرام به HTTPS نیاز دارد)"; then
    ssl_wanted=true
    host=$(ask_domain)
  else
    host=$(hostname -I 2>/dev/null | awk '{print $1}')
    if [[ -z "$host" ]]; then
      err "IP سرور پیدا نشد."
      pause; return
    fi
    warn "بدون SSL نصب می‌شود. آدرس موقت: http://${host}"
    warn "وب‌هوک تلگرام فقط با HTTPS کار می‌کند. بعداً SSL را اضافه کنید."
  fi

  info "دریافت کد ربات (v${REMOTE_VERSION}) از GitHub..."
  if ! git clone --quiet "$REPO_URL" "$INSTALL_DIR"; then
    err "کلون از GitHub ناموفق بود. اتصال اینترنت را بررسی کنید."
    rm -rf "$INSTALL_DIR"
    pause; return
  fi
  mark_safe
  fix_permissions
  ok "فایل‌های ربات دریافت شد."

  if ! write_vhost "$host"; then
    pause; return
  fi
  ok "تنظیمات Apache انجام شد."

  local scheme="http"
  if $ssl_wanted; then
    if setup_ssl "$host"; then
      scheme="https"
    else
      warn "نصب بدون HTTPS ادامه می‌یابد. تا فعال شدن SSL، تلگرام به ربات پیام نمی‌دهد."
    fi
  fi

  show_success "${scheme}://${host}"
  pause
}

# ---------- پشتیبان‌گیری ----------
make_backup() {
  mkdir -p "$BACKUP_DIR"
  local file="$BACKUP_DIR/simorgh-$(date +%Y%m%d-%H%M%S).tar.gz"
  local items=()
  [[ -f "$INSTALL_DIR/config.php" ]] && items+=("config.php")
  [[ -f "$INSTALL_DIR/installed.lock" ]] && items+=("installed.lock")
  [[ -d "$INSTALL_DIR/storage" ]] && items+=("storage")
  if [[ ${#items[@]} -eq 0 ]]; then
    warn "فایلی برای پشتیبان‌گیری پیدا نشد."
    return 0
  fi
  if tar -czf "$file" -C "$INSTALL_DIR" "${items[@]}" 2>/dev/null; then
    chmod 600 "$file"
    ok "پشتیبان ذخیره شد: ${file}"
    return 0
  fi
  err "پشتیبان‌گیری ناموفق بود."
  return 1
}

# ---------- گزینه‌ی ۲: حذف کامل ----------
do_uninstall() {
  show_banner
  if ! is_installed; then
    warn "ربات نصب نیست."
    pause; return
  fi

  warn "این کار کد ربات، تنظیمات Apache و پوشه‌ی storage را حذف می‌کند."
  if ! ask_yes_no "آیا ربات کاملاً حذف شود؟ (y = حذف، n = بازگشت به منو)"; then
    info "بازگشت به منوی اصلی."
    pause; return
  fi

  if ! make_backup; then
    ask_yes_no "پشتیبان ساخته نشد. باز هم ادامه می‌دهید؟" || { info "حذف لغو شد."; pause; return; }
  fi

  local host="" certs=""
  if [[ -f "$VHOST_FILE" ]]; then
    host=$(awk '/ServerName/{print $2; exit}' "$VHOST_FILE")
  fi

  a2dissite "${VHOST_NAME}.conf" >/dev/null 2>&1
  rm -f "$VHOST_FILE"
  systemctl reload apache2 >/dev/null 2>&1
  rm -rf "$INSTALL_DIR"
  ok "فایل‌های ربات و تنظیمات Apache حذف شد."

  if [[ -n "$host" ]]; then
    certs=$(certbot certificates 2>/dev/null)
    if grep -qF "Domains: ${host}" <<< "$certs"; then
      if ask_yes_no "گواهی SSL دامنه‌ی ${host} هم حذف شود؟"; then
        if certbot delete --cert-name "$host" --non-interactive >/dev/null 2>&1; then
          ok "گواهی SSL حذف شد."
        else
          warn "حذف گواهی ناموفق بود."
        fi
      fi
    fi
  fi

  warn "دیتابیس MySQL حذف نشد. در صورت نیاز از phpMyAdmin آن را حذف کنید."
  ok "حذف ربات انجام شد."
  pause
}

# ---------- گزینه‌ی ۳: به‌روزرسانی ----------
do_update() {
  show_banner
  if ! is_installed; then
    warn "ربات نصب نیست."
    pause; return
  fi

  mark_safe
  info "بررسی نسخه‌ی جدید..."
  if ! git -C "$INSTALL_DIR" fetch --quiet origin main; then
    err "بررسی به‌روزرسانی ناموفق بود. اتصال اینترنت را بررسی کنید."
    pause; return
  fi

  local local_rev remote_rev local_ver new_ver
  local_rev=$(git -C "$INSTALL_DIR" rev-parse HEAD)
  remote_rev=$(git -C "$INSTALL_DIR" rev-parse origin/main)
  local_ver=$(read_local_version)
  new_ver=$(git -C "$INSTALL_DIR" show origin/main:src/bootstrap.php 2>/dev/null | parse_version)

  if [[ "$local_rev" == "$remote_rev" ]]; then
    ok "ربات به‌روز است (v${local_ver:-?})."
    pause; return
  fi

  echo
  echo -e "  ${C_BOLD}نسخه‌ی فعلی :${C_RESET} v${local_ver:-?}"
  echo -e "  ${C_BOLD}نسخه‌ی جدید:${C_RESET} v${new_ver:-?}"
  echo
  info "تغییرات جدید:"
  git -C "$INSTALL_DIR" log --oneline HEAD..origin/main | head -n 15 | sed 's/^/     /' >&2
  echo

  if ! ask_yes_no "آیا به‌روزرسانی انجام شود؟"; then
    info "به‌روزرسانی لغو شد."
    pause; return
  fi

  make_backup
  if ! git -C "$INSTALL_DIR" pull --ff-only --quiet origin main; then
    err "به‌روزرسانی ناموفق بود. احتمالاً تغییرات محلی در فایل‌ها وجود دارد."
    pause; return
  fi
  fix_permissions
  systemctl reload apache2 >/dev/null 2>&1
  REMOTE_VERSION="${new_ver:-$REMOTE_VERSION}"
  ok "ربات به نسخه‌ی v${new_ver:-?} به‌روز شد."

  if [[ -f "$INSTALL_DIR/install.php" ]]; then
    warn "فایل install.php دوباره آمده است. بعد از بررسی، آن را از سرور حذف کنید."
  fi
  pause
}

# ---------- گزینه‌ی ۴: لاگ‌ها ----------
do_logs() {
  show_banner
  if ! is_installed; then
    warn "ربات نصب نیست."
    pause; return
  fi

  echo "    1) لاگ برنامه"
  echo "    2) لاگ خطای Apache"
  echo "    3) لاگ دسترسی Apache"
  echo "    4) بازگشت"
  echo
  read -r -p "  انتخاب [1-4]: " choice
  case "$choice" in
    1)
      local file
      file=$(ls -t "$INSTALL_DIR"/storage/logs/*.log 2>/dev/null | head -n 1)
      echo
      if [[ -z "$file" ]]; then
        warn "فایل لاگ برنامه پیدا نشد."
      else
        info "فایل: ${file}"
        tail -n "$LOG_LINES" "$file"
      fi
      pause ;;
    2)
      echo
      if [[ -f "/var/log/apache2/${VHOST_NAME}-error.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-error.log"
      else
        warn "لاگ خطای ربات پیدا نشد."
      fi
      pause ;;
    3)
      echo
      if [[ -f "/var/log/apache2/${VHOST_NAME}-access.log" ]]; then
        tail -n "$LOG_LINES" "/var/log/apache2/${VHOST_NAME}-access.log"
      else
        warn "لاگ دسترسی ربات پیدا نشد."
      fi
      pause ;;
    4) return ;;
    *) warn "گزینه‌ی نامعتبر است."; sleep 1 ;;
  esac
}

# ---------- خداحافظی ----------
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
  echo -e "   ${C_BOLD}سیمرغ همیشه ناظر است.${C_RESET}"
  echo
  echo -e "   هر زمان به سیمرغ نیاز داشتی، فقط این را بزن:"
  echo
  echo -e "       ${C_YELLOW}${C_BOLD}sudo simorgh${C_RESET}"
  echo
  echo -e "   ${C_CYAN}Channel:${C_RESET} ${CHANNEL_URL}"
  echo -e "   ${C_CYAN}Group  :${C_RESET} ${GROUP_URL}"
  echo
}

# ---------- منوی اصلی ----------
main_menu() {
  while true; do
    show_banner
    echo "    1) نصب ربات"
    echo "    2) حذف کامل ربات"
    echo "    3) به‌روزرسانی ربات"
    echo "    4) نمایش لاگ‌ها"
    echo "    5) خروج"
    echo
    read -r -p "  گزینه را انتخاب کنید [1-5]: " choice
    case "$choice" in
      1) do_install ;;
      2) do_uninstall ;;
      3) do_update ;;
      4) do_logs ;;
      5) farewell; exit 0 ;;
      *) warn "گزینه‌ی نامعتبر است."; sleep 1 ;;
    esac
  done
}

main() {
  require_root
  require_apt
  trap 'echo; warn "عملیات لغو شد."; farewell; exit 130' INT
  show_banner
  info "دریافت اطلاعات نسخه از GitHub..."
  REMOTE_VERSION=$(fetch_remote_version)
  main_menu
}

main "$@"
