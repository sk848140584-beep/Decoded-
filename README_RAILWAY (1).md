# Railway Deployment

## Files

- `main.py` - Telegram bot
- `requirements.txt` - Python dependencies
- `Procfile` and `railway.json` - Railway start configuration
- `.gitignore` - local secrets and runtime files

## Railway Variables

Add these variables in Railway before deploying:

```text
BOT_TOKEN=your_new_telegram_bot_token
BOT_USERNAME=your_bot_username_without_@
ADMIN_ID=your_telegram_user_id
SECOND_ADMIN_ID=your_second_admin_user_id
ADMIN_CONTACT=@your_support_username
DATA_DIR=/data
```

`BOT_TOKEN`, `ADMIN_ID`, and `SECOND_ADMIN_ID` are required for the bot to work correctly.

## Start command

```bash
python main.py
```

## Persistent storage

The bot creates its SQLite database automatically at `DATA_DIR/yp_shop.db`.
For production, attach a Railway Volume and mount it at `/data`, then keep:

```text
DATA_DIR=/data
```

Without a volume, users, products, wallet balances, and settings can be lost
when Railway recreates the container.

## Important security note

The old uploaded source contained a Telegram bot token. Revoke that token in
@BotFather and create a new one before deploying. Never commit the new token
to GitHub or put it directly in `main.py`.

After deployment, configure FamPay, Binance Pay, external API settings, products,
product keys, and support links from the bot's admin panel.

At checkout, users can pay from Wallet Balance or use Direct UPI. Direct UPI
payments are auto-verified in the background and delivered automatically; the
payment screen also keeps a manual Verify Payment fallback.

When adding an API product, the bot now shows the configured API slots. Choose
the API slot that should create that product's key, or choose Auto / Failover All
APIs. Manual-key products do not use an API slot.