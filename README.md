
<div align="center">

# 💎 SimorghBot

A powerful Telegram store bot for selling panels, with fully automated setup on cPanel hosting.

**ربات فروشگاه پنل تلگرام با PHP و MySQL، با فروش خودکار و نصب آسان روی هاست cPanel**

[![Telegram Channel](https://img.shields.io/badge/TELEGRAM-CHANNEL-29A7E1?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/SimorghFree)
[![Telegram Group](https://img.shields.io/badge/TELEGRAM-GROUP-29A7E1?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/SimorghBotGroup)
[![Docs](https://img.shields.io/badge/DOCS-INSTALLATION-3FB6A8?style=for-the-badge&logo=readthedocs&logoColor=white)](https://raven404xv.github.io/simorgh-bot/)

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Webhook](https://img.shields.io/badge/Telegram-Webhook-26A5E4?style=flat-square&logo=telegram&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

</div>

---

<a name="toc"></a>

## 📚 Table of Contents

- [✨ Overview](#overview)
- [📖 Documentation](#documentation)
- [🧭 Requirements](#requirements)
- [🚀 Quick Start](#quick-start)
- [🧩 Features](#features)
- [♻️ Updating](#updating)
- [🔒 Security](#security)
- [🛠️ Troubleshooting](#troubleshooting)
- [🤝 Support](#support)
- [📄 License](#license)

---

<a name="overview"></a>

## ✨ Overview

SimorghBot is a Telegram store bot written in PHP and MySQL. It runs on a shared cPanel host and works with a Telegram **Webhook**, so it does not need a permanent process or SSH access.

Customers buy panels from the bot, pay from their wallet or by bank card with a receipt, and receive their service automatically. Administrators manage everything from the admin panel inside Telegram.

---

<a name="documentation"></a>

## 📖 Documentation

The complete step-by-step installation guide is on the documentation site:

**👉 [Open the installation guide](https://raven404xv.github.io/simorgh-bot/)**

The guide covers:

- Creating the MySQL database in cPanel
- Creating the bot and getting its token from @BotFather
- Downloading the files with Git™ Version Control or a ZIP archive
- Completing the setup form in `install.php`
- Testing the bot and removing the setup files
- Troubleshooting and frequently asked questions

---

<a name="requirements"></a>

## 🧭 Requirements

| Item | Requirement |
|---|---|
| PHP | Version 8.0 or newer |
| PHP extensions | `pdo_mysql`, `curl`, `json`, `mbstring` |
| SSL | An active SSL certificate for your domain (Telegram requires HTTPS) |
| Hosting | cPanel shared hosting with MySQL access |

---

<a name="quick-start"></a>

## 🚀 Quick Start

1. Create a MySQL database and user in cPanel, then grant the user all privileges on the database.
2. Create your bot with @BotFather and copy the token. Get your numeric ID from @userinfobot.
3. Put the files in `public_html/SimorghBot` using Git™ Version Control or a ZIP archive.
4. Open `https://yourdomain.com/SimorghBot/install.php` and complete the form.
5. Send `/start` to your bot, check `selftest.php`, then delete the setup files.

For the full details of each step, see the [installation guide](https://raven404xv.github.io/simorgh-bot/).

---

<a name="features"></a>

## 🧩 Features

**For customers**
- 🛒 Panel sales in 1, 2 and 3 month plans
- 💳 Payment from wallet, or bank card with receipt
- 💰 Wallet top-up approved by the admin (each receipt is credited once)
- 🎁 Free trial with an adjustable quota
- 👥 Referral system with rewards
- 🎧 Support tickets and frequently asked questions
- 📱 Persian Mini App with light and dark themes

**For administrators**
- 📦 Manage products, payment cards and admins
- 📢 Broadcast messages and mandatory channel membership
- 🎨 Customize button layout and colours
- 🔀 Enable or disable the free trial from the admin panel
- 🧾 Reports in an admin group, with support for topics

---

<a name="updating"></a>

## ♻️ Updating

- **Git:** in Git™ Version Control, select the installed repository and click **Update from Remote**.
- **ZIP:** replace the `src/` folder and the `*.php` files. Do not touch `config.php` or `installed.lock`, so your settings are kept.

---

<a name="security"></a>

## 🔒 Security

- `config.php`, `installed.lock` and the `storage/` folder are blocked from public access.
- The `src/` folder cannot be opened from a browser.
- Payment receipts are visible only to administrators.
- Mini App requests are verified with Telegram's official signature.
- Never share your bot token or `config.php`.

---

<a name="troubleshooting"></a>

## 🛠️ Troubleshooting

| Problem | Solution |
|---|---|
| The setup page shows error 500 | Use PHP 8.0 or newer and enable `pdo_mysql` |
| Database connection failed | Check the full database and user names, including the cPanel prefix |
| The bot does not answer `/start` | Open `selftest.php` and read the "last Webhook error" row |
| The Mini App button does not appear | The address must use HTTPS |

More solutions are in the [troubleshooting section of the guide](https://raven404xv.github.io/simorgh-bot/#troubleshoot).

---

<a name="support"></a>

## 🤝 Support

If you run into a problem or find a bug, post in the support group. Please include the **error message** or a **screenshot**, and the step where it happened.

- 💬 **Support group:** [@SimorghBotGroup](https://t.me/SimorghBotGroup)
- 📢 **Channel:** [@SimorghFree](https://t.me/SimorghFree)

---

<a name="license"></a>

## 📄 License

This project is released under the **MIT** license. See [LICENSE](LICENSE).

<div align="center">

**💎 Simorgh is always watching 💎**

</div>
