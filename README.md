# 🎬 Vidmoly Telegram Upload Bot (Firebase Realtime Database Edition)

A production-ready, lightweight Telegram Bot backend written in **PHP 8.x** and powered by **Firebase Realtime Database (Cloud NoSQL)**, designed to manage Vidmoly accounts, upload remote URLs and Telegram videos directly, organize folders, inspect statistics, and support multiple accounts per user.

Adheres strictly to the architecture and security specifications in the [Vidmoly Telegram Upload Bot PRD](Vidmoly%20Telegram%20Upload%20Bot%20%E2%80%94%20Complete%20PRD.md).

---

## 🌟 Key Features

- **Zero-Setup Database (Firebase Realtime Database):** 
  - No MySQL servers, phpMyAdmin, or SQL migrations required!
  - Operates globally via Firebase REST API with lightning-fast cloud latency.
- **Multilingual System with Country Flags:**
  - 🇬🇧 **English** (Default)
  - 🇧🇩 **বাংলা (Bangla)**
  - 🇮🇳 **हिन्दी (Hindi)**
  - Automatic prompt on first `/start` with interactive flag selection.
  - Dynamic switching anytime using `/language`.
- **Comprehensive Interactive Command Guides:**
  - Every single command provides an easy-to-follow step-by-step tutorial when called without parameters or when requested.
- **Force Subscribe Verification System:**
  - Admins can enforce channel membership (`/forcesub @channel`).
  - Verifies membership via Telegram's `getChatMember` API with a direct "Join Channel" & "Check Membership" button.
- **Multiple Vidmoly API Accounts:**
  - Users can store up to `MAX_APIS_PER_USER` (default: 5) Vidmoly API keys.
  - Active API switching on the fly.
  - Automatic fallback when the active API is removed.
  - Duplicate API key prevention.
- **Top-Tier Security:**
  - **AES-256-CBC Encryption at Rest:** Vidmoly API keys are never stored as plaintext in Firebase.
  - **Credential Masking:** API keys are masked in Telegram messages (e.g. `6200••••••••••mx`).
  - **SSRF Protection:** Remote URL uploads strictly block private IP ranges (RFC1918), `localhost`, and cloud metadata endpoints (`169.254.169.254`).
  - **Zero Credential Leakage:** Sensitive parameters and bot tokens are automatically redacted from error logs.
- **Full Vidmoly Integration:**
  - Account info: email, balance, premium status, disk storage.
  - Daily statistics: views, downloads, profits (7 days / 30 days).
  - File management: list files with interactive pagination, file details lookup, file renaming.
  - Folder management: list folders, create new folders, organize content.
  - Remote URL upload: submit direct video links to Vidmoly's upload pipeline.
  - Telegram video/document upload: automatically interfaces with Vidmoly's upload gateway.
- **Admin Dashboard:**
  - View real-time user statistics, total APIs saved, and upload counters.
  - Broadcast messages to all active users with rate throttling.
  - Block / unblock abusive users (`/block <id>`, `/unblock <id>`).
  - Toggle maintenance mode dynamically.
- **Dual Execution Modes:**
  - **Webhook Mode (`bot.php`):** Ideal for standard web servers with HTTPS.
  - **Long-Polling Mode (`poll.php`):** Run locally or on any server/VPS without needing a domain, SSL certificate, or webhook!

---

## 📁 Project Structure

```text
MediaCDN/
├── bot.php                               # Unified, single-file application logic (Firebase REST)
├── poll.php                              # Long-polling runner (no webhook/domain required)
├── .env                                  # Pre-configured environment variables
├── firebase_schema.json                  # Firebase Realtime Database schema structure
├── README.md                             # Documentation and deployment guide
└── Vidmoly Telegram Upload Bot — Complete PRD.md
```

---

## 🚀 Pre-Configured Credentials

Your configuration is already baked into `.env` and `bot.php`:

- **Bot Username:** `@media_dlnBOT`
- **Bot Token:** `8751725729:AAFgIlWsFaLCfIA44H1KevDumLwXUOC8rZE`
- **Admin ID:** `6966969676`
- **Firebase Project:** `mediacdn-f36e3`
- **Firebase Realtime DB:** `https://mediacdn-f36e3-default-rtdb.asia-southeast1.firebasedatabase.app`
- **Firebase API Key:** `AIzaSyBUK9txoYiiFABssphG4XvhuW7MORkrV5s`

---

## ⚡ Deployment Options

### Option 1: Long-Polling Runner (`poll.php`) — *Recommended & Easiest*

Because free hosts (like InfinityFree) often block automated incoming webhook POST requests with browser JavaScript challenges (`aes.js`), **long-polling** bypasses all web firewalls, requires **no domain**, and needs **no SSL setup**.

1. Run on any computer, server, or VPS with PHP installed:
   ```bash
   php poll.php
   ```
2. You will see:
   ```text
   =======================================================
    Vidmoly Telegram Bot - Long Polling Started
    Bot: @media_dlnBOT
    Status: Listening for updates from Telegram...
   =======================================================
   ```
3. Test your bot immediately in Telegram!

---

### Option 2: Webhook Mode (`bot.php`)

If you prefer hosting `bot.php` on a web server:

1. Upload `bot.php` and `.env` to your web server (e.g., cPanel, Render, Koyeb, Alwaysdata, or a VPS with Apache/Nginx).
   > **Note on InfinityFree:** InfinityFree free plan blocks Telegram webhook POST requests with an anti-bot cookie verification (`aes.js`). If using webhooks, host on any standard PHP host (such as Alwaysdata, Render, Railway, or VPS) that allows incoming POST requests from Telegram.
2. Set your Telegram webhook:
   ```text
   https://api.telegram.org/bot8751725729:AAFgIlWsFaLCfIA44H1KevDumLwXUOC8rZE/setWebhook?url=https://YOUR_DOMAIN/bot.php
   ```
3. Verify status anytime:
   ```text
   https://api.telegram.org/bot8751725729:AAFgIlWsFaLCfIA44H1KevDumLwXUOC8rZE/getWebhookInfo
   ```

---

## 🤖 Bot Commands & Usage

### User Commands

| Command | Action |
|---|---|
| `/start` | Opens the welcome greeting, language selection prompt, and interactive menu. |
| `/language` | Choose bot language (🇬🇧 English, 🇧🇩 বাংলা, 🇮🇳 हिन्दी). |
| `/help` | Displays command documentation and usage guide. |
| `/addapi` | Adds a new Vidmoly API key. Verifies with Vidmoly and auto-activates if first key. |
| `/myapis` | Lists all saved Vidmoly accounts with masked keys and status. |
| `/switchapi` | Interactive menu to switch active API account. |
| `/removeapi` | Prompts confirmation to delete a saved API key. |
| `/account` | Displays active account balance, premium tier, and disk storage usage. |
| `/stats` | View active account views, downloads, and earnings (7 or 30 days). |
| `/upload` | Initiates remote URL upload. Checks URL against SSRF and queues to Vidmoly. |
| `/files` | Lists uploaded files with page navigation and per-file action buttons. |
| `/fileinfo` | Inspect details of a video using its file code. |
| `/rename` | Renames a video file on Vidmoly. |
| `/folders` | Displays all created folders. |
| `/addfolder` | Creates a new folder on Vidmoly. |
| `/cancel` | Cancels any ongoing conversation state and returns to main menu. |

### Administrator Commands

| Command | Action |
|---|---|
| `/admin` | Displays the admin dashboard with real-time Firebase stats and toggles. |
| `/forcesub <@channel>` | Set required channel for Force Subscribe (or `/forcesub off` to disable). |
| `/broadcast` | Broadcasts an announcement to all active users with rate limits. |
| `/block <telegram_user_id>` | Restricts a user from interacting with the bot. |
| `/unblock <telegram_user_id>` | Restores access for a blocked user. |

---

## 🛡️ Security Best Practices

1. **Keep Webhook Secret Active:** If using webhooks, configure `BOT_SECRET_TOKEN` to reject unauthorized HTTP requests.
2. **Never Commit Secrets:** Do not commit `.env` or real tokens into public git repositories.
3. **AES-256 Encryption:** Your `APP_ENCRYPTION_KEY` encrypts all stored API keys before writing to Firebase Realtime Database.
