<div align="center">

# 🖥️ SimorghBot — Server Installation

**راهنمای نصب سیمرغ بات روی سرور مجازی (VPS) با یک دستور و منوی مدیریت**

[![Telegram Channel](https://img.shields.io/badge/TELEGRAM-CHANNEL-29A7E1?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/SimorghFree)
[![Telegram Group](https://img.shields.io/badge/TELEGRAM-GROUP-29A7E1?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/SimorghBotGroup)
[![Host Guide](https://img.shields.io/badge/GUIDE-CPANEL%20HOSTING-3FB6A8?style=for-the-badge)](https://raven404xv.github.io/simorgh-bot/server/)

![Ubuntu](https://img.shields.io/badge/Ubuntu-22.04%2B-E95420?style=flat-square&logo=ubuntu&logoColor=white)
![Debian](https://img.shields.io/badge/Debian-12%2B-A81D33?style=flat-square&logo=debian&logoColor=white)
![Bash](https://img.shields.io/badge/Bash-Installer-4EAA25?style=flat-square&logo=gnubash&logoColor=white)
![Version](https://img.shields.io/badge/Version-3.3.0-3FB6A8?style=flat-square)

</div>

---

<a name="toc"></a>

## 📚 Table of Contents

- [🧭 Overview](#overview)
- [✅ Requirements](#requirements)
- [⚡ Quick Install](#quick-install)
- [🧰 The Manager Menu](#menu)
- [🚀 Step-by-Step Installation](#step-by-step)
- [🌐 Finishing Setup in the Browser](#finish-setup)
- [🔧 Fixing the Telegram Connection](#fix-telegram)
- [🔁 Updating](#updating)
- [🗑️ Uninstalling](#uninstalling)
- [📜 Logs](#logs)
- [🔒 Security Checklist](#security)
- [🛠️ Troubleshooting](#troubleshooting)
- [❓ FAQ](#faq)
- [🤝 Support](#support)

---

<a name="overview"></a>

## 🧭 Overview

The server installer is a single script, `simorgh.sh`, that prepares a fresh Ubuntu or Debian server and installs SimorghBot on it. It:

- installs the required software (Apache, PHP, MariaDB, Git, Certbot),
- creates the database and a dedicated database user automatically,
- gets an HTTPS certificate (trusted with a domain, self-signed with an IP address),
- downloads the bot and configures the web server,
- installs a `simorgh` command that opens a management menu.

You only need to answer a few questions. The rest is automatic.

---

<a name="requirements"></a>

## ✅ Requirements

| Item | Requirement |
|---|---|
| Operating system | Ubuntu 22.04 or newer, or Debian 12 or newer (a fresh server is recommended) |
| Access | `root`, or a user with `sudo` |
| Ports | `443` or `8443` free (the installer suggests a free one) |
| Domain (optional) | An `A` record that points to the server IP. A domain gives a trusted certificate. |
| Telegram | A bot token from [@BotFather](https://t.me/BotFather) and your numeric ID from [@userinfobot](https://t.me/userinfobot) |

> 💡 Without a domain the bot still works on the server IP, but the browser shows a certificate warning, which is expected.

---

<a name="quick-install"></a>

## ⚡ Quick Install

Connect to the server with SSH and run this single command:

```bash
sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/Raven404xv/simorgh-bot/main/simorgh.sh)"
```

The manager menu opens and the `simorgh` command is created. From now on, just type:

```bash
simorgh
```

---

<a name="menu"></a>

## 🧰 The Manager Menu

| Option | Action |
|---|---|
| **1** | Install the bot |
| **2** | Uninstall the bot (asks for confirmation) |
| **3** | Update the bot (makes a safety copy of your settings first) |
| **4** | Live logs (press `Ctrl+C` to return to the menu) |
| **5** | Fix the Telegram connection (registers the webhook again and shows its status) |
| **6** | Exit |

---

<a name="step-by-step"></a>

## 🚀 Step-by-Step Installation

### Step 1 — Start the installer

Run the quick install command above, then choose **1**.

### Step 2 — Domain or IP

The installer asks whether you have a domain.

- **Yes:** enter the domain, for example `bot.example.com`. The installer checks that the domain points to this server, then asks for your email.
- **No:** the bot is installed on the server IP with a self-signed certificate.

### Step 3 — Port and certificate

- If port **80** is free and you have a domain, the installer offers to verify the certificate through port 80. The certificate then renews automatically.
- If port 80 is busy, the certificate is verified through **DNS** instead. With **Cloudflare** it renews automatically. With another DNS provider you must renew it by hand every 90 days.
- The HTTPS port is **443** when it is free, otherwise **8443**. Telegram accepts **80, 88, 443 and 8443** for the webhook.

### Step 4 — Firewall

If `ufw` is active and the port is closed, the installer offers to open it.

> ☁️ Your hosting provider may have its own firewall panel. Open the same port there too.

### Step 5 — Review and confirm

The installer shows the plan: domain, certificate, ports and the final address. Press **Enter** to start.

### Step 6 — Automatic installation

The installer installs the software, creates the database and its user, downloads the bot, configures Apache and runs a health check. This takes several minutes.

---

<a name="finish-setup"></a>

## 🌐 Finishing Setup in the Browser

When the installation is done, the menu shows an address such as:

```
https://bot.example.com/install.php
```

or, without a domain:

```
https://203.0.113.10:8443/install.php
```

1. Open the address. For the self-signed case, click **Advanced**, then **Proceed**.
2. Get the database details:
   ```bash
   sudo cat /root/simorgh-credentials.txt
   ```
3. Enter your bot token, your numeric admin ID, the store name and the database details, then submit.

> 📧 With a domain and an email, the same details are also sent to your email. Check the spam folder if it does not arrive.

---

<a name="fix-telegram"></a>

## 🔧 Fixing the Telegram Connection

If the bot does not answer `/start`, run `simorgh` and choose **5**. This option:

- reads the bot settings from `config.php`,
- registers the webhook again, uploading the certificate when it is self-signed,
- shows the current webhook status without printing your token.

Then send `/start` again.

---

<a name="updating"></a>

## 🔁 Updating

Run `simorgh` and choose **3**. If a new version exists, it is installed. Your settings are backed up first. If you are already up to date, the menu says so.

---

<a name="uninstalling"></a>

## 🗑️ Uninstalling

Run `simorgh` and choose **2**. You will be asked to confirm:

- `y` removes the bot, its web server settings, its certificate and the database created by the installer.
- `n` returns to the menu without changes.

Common software such as Apache or PHP is not removed.

---

<a name="logs"></a>

## 📜 Logs

| File | Contents |
|---|---|
| `/var/log/simorgh-manager.log` | Installer and manager log |
| `/var/log/apache2/simorgh-bot-ssl-error.log` | Web server and bot errors |

Use menu option **4** to watch the logs live.

---

<a name="security"></a>

## 🔒 Security Checklist

- [ ] Delete `install.php` after setup.
- [ ] Delete `selftest.php` after you have checked the bot.
- [ ] Move or delete `/root/simorgh-credentials.txt` after you saved the details.
- [ ] Never share `config.php` or your bot token.
- [ ] If the token was ever exposed, revoke it with `/revoke` in BotFather, then run option **5**.
- [ ] Keep the system updated and open only the ports you need.

---

<a name="troubleshooting"></a>

## 🛠️ Troubleshooting

| Problem | Solution |
|---|---|
| The install command fails to download | Check the internet connection and run the command again |
| The setup page shows error 500 | Run `simorgh`, choose **3** to update, then check the logs with **4** |
| The bot does not answer `/start` | Run `simorgh`, choose **5**, then send `/start` again |
| The browser shows a certificate warning | Expected with an IP address. Use a domain for a trusted certificate. |
| The email did not arrive | Check the spam folder, then read `/root/simorgh-credentials.txt` |
| A port is busy | The installer suggests another allowed port. Do not stop other services unless you are sure. |

---

<a name="faq"></a>

## ❓ FAQ

**Do I need a domain?**
No. Without a domain the bot runs on the server IP with a self-signed certificate.

**Do I need to create the database myself?**
No. The installer creates it on the server.

**Can I use the same server for other websites?**
Yes, but do not stop other services that use ports 80 or 443 unless you are sure.

**Is the database removed when I uninstall?**
Yes, the database and user created by the installer are removed.

---

<a name="support"></a>

## 🤝 Support

If something does not work, post in the support group with the **error message or a screenshot** and the step where it happened.

- 💬 **Support group:** [@SimorghBotGroup](https://t.me/SimorghBotGroup)
- 📢 **Channel:** [@SimorghFree](https://t.me/SimorghFree)

<div align="center">

**💎 Simorgh is always watching 💎**

</div>
