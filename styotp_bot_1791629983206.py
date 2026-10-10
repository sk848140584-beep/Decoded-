import os
import sqlite3
import re
import time
import logging
import aiohttp
import csv
import zipfile
import shutil
import html
import traceback
from datetime import datetime
import threading
import asyncio
import tempfile
import urllib.parse
import json

import telebot
from telebot import types
from telebot.types import InlineKeyboardMarkup, InlineKeyboardButton, ReplyKeyboardMarkup, KeyboardButton

from telethon import TelegramClient, events, Button
from telethon.errors import (
    SessionPasswordNeededError,
    MessageNotModifiedError,
    UserNotParticipantError,
    ChatAdminRequiredError
)
from telethon.tl.types import KeyboardButtonWebView
from telethon.tl.functions.channels import GetParticipantRequest
from telethon.tl.functions.account import GetPasswordRequest

# ================= CONFIGURATION =================
API_ID = 32208414
API_HASH = "628f11c05a44c8dda4b006e66f4bf7df"
BOT_TOKEN = "8976832599:AAE5F8oZY4rciMW_dweUKrOnRG7Nkj-Y2cY"
ADMIN_ID = 1404687158
SECOND_ADMIN_ID = 6452869652
SUPER_ADMIN_IDS = frozenset({ADMIN_ID, SECOND_ADMIN_ID})

# CHANNELS
LOG_CHANNEL_ID = -1003585551269
PURCHASE_LOG_CHANNEL_ID = -1003773535350
CHECK_CHANNELS = [-1003907878273,-1003773535350]
JOIN_URLS = [
    "https://t.me/+5phKn-dqFvUwY2Y5", "https://t.me/styotpshop",
]

# LINKS & MEDIA
TERMS_URL = "https://stytgshop.netlify.app/"
# ================= CONFIGURATION =================
# ... other config ...

# CWALLET
CWALLET_QR = "https://ibb.co/0RfRDMQG"
CWALLET_ID = "81323047"

# BINANCE
BINANCE_QR = "https://ibb.co/4RmwsxKw"
BINANCE_ID = "566560834"#place with your Binance ID/Address

# Add more payment methods here as needed
TRON_QR = "https://ibb.co/QFd6Zzj9"
TRON_ID ="TSQhx39pg43KdDpSwFBksGYWWxh7F7VkZZ"

POLYGON_QR = "https://ibb.co/NgNjtQNC"
POLYGON_ID = "0xb454937A5827712cc03b2471386f886a03F7a1D0"
# FAMPAY API DETAILS
FAMPAY_API_URL = "https://fampay-api-s34m.onrender.com"
FAMPAY_UPI_ID = "umar8593@fam"

OTP_REGEX = r"\b\d{4,8}\b"
AUTO_CANCEL_SECONDS = 600

# ================= CRASH-FREE HD EMOJIS =================
P_YES = '✅'
P_NO = '❌'
P_PKG = '📦'
P_MONEY = '💰'
P_USDT = '💲'
P_INR = '₹'
P_TG = '✈️'
P_GIFT = '🎁'
P_STATS = '📊'
P_CARD = '💳'
P_USERS = '👥'
P_CAL = '📅'
P_PC = '💻'
P_EYE = '👁️'
P_UPI = '🏦'
P_CW = '👛'
P_ON = '🟢'
P_OFF = '🔴'
P_ID = '🆔'
P_KEY = '⌨️'
P_GLOBE = '🌎'
P_CART = '🛒'
P_STORE = '🏬'
P_OTP = '🔢'
P_2FA = '🔐'
P_FLAG = '🏳️'
P_PHONE = '📱'
P_WAIT = '⏳'
P_TIME = '⏰'
P_WARN = '⚠️'
P_DOC = '📃'
P_SOS = '🆘'
P_ASST = '🤖'
P_ACC = '👤'

# ================= INITIALIZATION =================
logging.basicConfig(format='%(asctime)s - %(name)s - %(levelname)s - %(message)s', level=logging.INFO)
logger = logging.getLogger(__name__)

os.makedirs("sessions", exist_ok=True)

# Initialize pyTelegramBotAPI for interface
bot = telebot.TeleBot(BOT_TOKEN, parse_mode='HTML')

# Initialize Telethon for backend operations
session_name = f"bot_session_{BOT_TOKEN.split(':')[0]}"
telethon_client = TelegramClient(session_name, API_ID, API_HASH)

db = sqlite3.connect("otp_bot_final.db", check_same_thread=False, timeout=20)
db.execute("PRAGMA journal_mode=WAL;")
cur = db.cursor()

# Shared state between Telethon and pyTelegramBotAPI
active_orders = {}
waiting_proof = {}
deposit_input = {}
admin_dep_state = {}
user_spam_cooldown = {}
session_buy_state = {}
custom_dep_amt = {}
temp_data = {}
user_locks = {}
pending_messages = {}

# Async loop for Telethon operations
telethon_loop = None

def get_user_lock(uid):
    if uid not in user_locks:
        user_locks[uid] = asyncio.Lock()
    return user_locks[uid]

# ================= DATABASE SCHEMA =================
def setup_db():
    cur.executescript("""
    CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        balance INTEGER DEFAULT 0,
        referred_by INTEGER,
        total_deposited INTEGER DEFAULT 0,
        joined_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        banned INTEGER DEFAULT 0,
        discount INTEGER DEFAULT 0,
        terms_accepted INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS stock (
        phone TEXT PRIMARY KEY,
        session_file TEXT,
        country_name TEXT,
        country_icon TEXT DEFAULT '🌍',
        account_year INTEGER,
        category TEXT DEFAULT 'Good',
        price INTEGER,
        available INTEGER DEFAULT 1,
        twofa TEXT DEFAULT 'None',
        added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS auto_prices (
        country TEXT,
        year TEXT,
        price INTEGER,
        PRIMARY KEY (country, year)
    );
    CREATE TABLE IF NOT EXISTS deposits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        amount INTEGER,
        method_name TEXT,
        status TEXT,
        date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS upi_orders (
        order_id TEXT PRIMARY KEY,
        user_id INTEGER,
        amount INTEGER,
        status TEXT,
        date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        country TEXT,
        year INTEGER,
        price INTEGER,
        phone TEXT,
        otp TEXT,
        date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS custom_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        caption TEXT,
        qr_file_id TEXT
    );
    CREATE TABLE IF NOT EXISTS admins (
        user_id INTEGER PRIMARY KEY,
        p_add_stock INTEGER DEFAULT 0,
        p_manage_stock INTEGER DEFAULT 0,
        p_stats INTEGER DEFAULT 0,
        p_bal INTEGER DEFAULT 0,
        p_settings INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS custom_countries (
        code TEXT PRIMARY KEY,
        name TEXT,
        flag TEXT
    );
    """)
    db.commit()

setup_db()

# Insert default settings if not exists
cur.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('bot_status', 'on')")
cur.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('usdt_rate', '94.0')")
cur.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('ref_percent', '3.0')")
cur.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('support_url', 'https://t.me/draxionnn')")
cur.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('upi_revenue', '0')")
db.commit()

# ================= HELPER FUNCTIONS =================
def is_bot_online():
    try:
        # Use a separate cursor for this query
        temp_cur = db.cursor()
        res = temp_cur.execute("SELECT value FROM settings WHERE key='bot_status'").fetchone()
        temp_cur.close()
        return res[0] == 'on' if res else True
    except:
        return True

def is_super_admin(uid):
    return uid in SUPER_ADMIN_IDS

def is_admin(uid):
    if is_super_admin(uid): return True
    row = cur.execute("SELECT user_id FROM admins WHERE user_id=?", (uid,)).fetchone()
    return bool(row)

def has_perm(uid, perm):
    if is_super_admin(uid): return True
    row = cur.execute(f"SELECT {perm} FROM admins WHERE user_id=?", (uid,)).fetchone()
    return bool(row and row[0] == 1)

def ensure_user(uid):
    db.execute(
        "INSERT OR IGNORE INTO users (user_id) VALUES (?)",
        (uid,)
    )
    db.commit()

def get_usdt_rate():
    res = cur.execute("SELECT value FROM settings WHERE key='usdt_rate'").fetchone()
    try: return float(res[0]) if res else 94.0
    except: return 94.0
def format_price_with_usdt(price_inr):
    """Format price in INR with USDT conversion"""
    usdt_rate = get_usdt_rate()
    price_usdt = round(price_inr / usdt_rate, 2)
    return f"{P_INR}{price_inr} (${price_usdt} USDT @ 1 USDT = {P_INR}{usdt_rate})"
def get_support_url():
    res = cur.execute("SELECT value FROM settings WHERE key='support_url'").fetchone()
    url = res[0] if res and res[0] else "https://t.me/draxionnn"
    if not url.startswith("http"): url = "https://" + url.replace("@", "t.me/")
    return url

def to_usd(inr):
    return round(inr / get_usdt_rate(), 2)

def is_user_banned(uid):
    res = cur.execute("SELECT banned FROM users WHERE user_id=?", (uid,)).fetchone()
    return res and res[0] == 1

def update_balance(uid, amount):
    cur.execute("UPDATE users SET balance = balance + ? WHERE user_id=?", (amount, uid))
    db.commit()

def delete_session_files(session_path):
    base = session_path if not session_path.endswith('.session') else session_path[:-8]
    for ext in ['.session', '.session-wal', '.session-shm', '.session-journal']:
        try:
            if os.path.exists(base + ext): os.remove(base + ext)
        except: pass

COUNTRY_CODES = {
    '1': ('USA/Canada', '🇺🇸'), '7': ('Russia', '🇷🇺'), '20': ('Egypt', '🇪🇬'),
    '27': ('South Africa', '🇿🇦'), '31': ('Netherlands', '🇳🇱'), '32': ('Belgium', '🇧🇪'),
    '33': ('France', '🇫🇷'), '34': ('Spain', '🇪🇸'), '39': ('Italy', '🇮🇹'),
    '44': ('UK', '🇬🇧'), '46': ('Sweden', '🇸🇪'), '48': ('Poland', '🇵🇱'),
    '49': ('Germany', '🇩🇪'), '51': ('Peru', '🇵🇪'), '52': ('Mexico', '🇲🇽'),
    '54': ('Argentina', '🇦🇷'), '55': ('Brazil', '🇧🇷'), '56': ('Chile', '🇨🇱'),
    '57': ('Colombia', '🇨🇴'), '58': ('Venezuela', '🇻🇪'), '60': ('Malaysia', '🇲🇾'),
    '61': ('Australia', '🇦🇺'), '62': ('Indonesia', '🇮🇩'), '63': ('Philippines', '🇵🇭'),
    '66': ('Thailand', '🇹🇭'), '84': ('Vietnam', '🇻🇳'), '86': ('China', '🇨🇳'),
    '90': ('Turkey', '🇹🇷'), '91': ('India', '🇮🇳'), '92': ('Pakistan', '🇵🇰'),
    '93': ('Afghanistan', '🇦🇫'), '94': ('Sri Lanka', '🇱🇰'), '95': ('Myanmar', '🇲🇲'),
    '98': ('Iran', '🇮🇷'), '212': ('Morocco', '🇲🇦'), '213': ('Algeria', '🇩🇿'),
    '234': ('Nigeria', '🇳🇬'), '254': ('Kenya', '🇰🇪'), '255': ('Tanzania', '🇹🇿'),
    '380': ('Ukraine', '🇺🇦'), '880': ('Bangladesh', '🇧🇩'), '964': ('Iraq', '🇮🇶'),
    '966': ('Saudi Arabia', '🇸🇦'), '971': ('UAE', '🇦🇪'), '998': ('Uzbekistan', '🇺🇿')
}

def get_flag_by_country_name(name):
    for code, (c_name, c_flag) in COUNTRY_CODES.items():
        if c_name == name: return c_flag
    try:
        row = cur.execute("SELECT flag FROM custom_countries WHERE name=?", (name,)).fetchone()
        if row: return row[0]
    except: pass
    return "🌍"

def get_country_info(phone):
    phone = str(phone).replace(' ', '').replace('+', '')
    if not phone: return "Unknown", "🌍"
    
    try:
        customs = cur.execute("SELECT code, name, flag FROM custom_countries").fetchall()
        customs.sort(key=lambda x: len(x[0]), reverse=True)
        for code, name, flag in customs:
            if phone.startswith(code): return name, flag
    except: pass

    for length in (3, 2, 1):
        prefix = phone[:length]
        if prefix in COUNTRY_CODES: return COUNTRY_CODES[prefix]
    return "Unknown", "🌍"

# ================= FORCE JOIN FUNCTIONS =================

async def check_user_channels(uid):
    """Check if user is a member of required channels"""
    try:
        # Get user entity
        user = await telethon_client.get_entity(uid)
        print(f"🔍 Checking channels for user {uid}")
        
        for channel_id in CHECK_CHANNELS:
            print(f"📢 Checking channel: {channel_id}")
            try:
                # Get channel entity
                channel_entity = await telethon_client.get_entity(channel_id)
                print(f"📢 Channel found: {channel_entity.title}")
                
                # Try to get participant
                try:
                    await telethon_client(GetParticipantRequest(channel_entity, user))
                    print(f"✅ User is member of {channel_entity.title}")
                except UserNotParticipantError:
                    print(f"❌ User NOT in {channel_entity.title}")
                    return False, channel_id
                except Exception as e:
                    print(f"⚠️ Participant check error: {e}")
                    return False, channel_id
                    
            except Exception as e:
                print(f"⚠️ Error getting channel {channel_id}: {e}")
                return False, channel_id
        
        print(f"✅ User is in ALL channels")
        return True, None
    except Exception as e:
        print(f"❌ Error in check_user_channels: {e}")
        import traceback
        traceback.print_exc()
        return False, None

def get_force_join_markup():
    """Get markup with join buttons and verify button"""
    markup = InlineKeyboardMarkup(row_width=1)
    for url in JOIN_URLS:
        markup.add(InlineKeyboardButton(
            f"📢 Join Channel",
            url=url
        ))
    # Add the verify button at the bottom
    markup.add(InlineKeyboardButton(
        "✅ Verify Membership",
        callback_data="force_join_check"
    ))
    return markup

async def check_and_force_join(uid, chat_id):
    """Check if user is in required channels and force join if not"""
    try:
        is_member, missing_channel = await check_user_channels(uid)
        
        if not is_member:
            # User hasn't joined required channels
            markup = get_force_join_markup()
            
            bot.send_message(
                chat_id,
                f"{P_WARN} <b>⚠️ Please join our channels to use this bot!</b>\n\n"
                f"Click the buttons below to join, then click <b>'Verify Membership'</b>.\n\n"
                f"<i>You must join all channels to continue.</i>",
                reply_markup=markup
            )
            return
        
        # User is in all channels - show main menu
        send_main_menu(chat_id, uid)
        
    except Exception as e:
        logger.error(f"Error in check_and_force_join: {e}")
        bot.send_message(chat_id, f"{P_WARN} <b>Could not verify channel membership.</b>\nPlease try again later.")

async def check_and_force_join_after_click(uid, chat_id, message_id):
    """Check membership after user clicks 'Verify Membership'"""
    try:
        is_member, missing_channel = await check_user_channels(uid)
        
        if not is_member:
            # Still not joined
            markup = get_force_join_markup()
            bot.edit_message_text(
                f"{P_WARN} <b>⚠️ You haven't joined all required channels yet!</b>\n\n"
                f"Please join all channels and click <b>'Verify Membership'</b>.\n\n"
                f"<i>You must join all channels to continue.</i>",
                chat_id,
                message_id,
                reply_markup=markup
            )
            return
        
        # Success - joined all channels
        bot.edit_message_text(
            f"{P_YES} <b>✅ You have joined all required channels!</b>\n\n"
            f"You can now use the bot.",
            chat_id,
            message_id
        )
        
        # Show main menu
        send_main_menu(chat_id, uid)
        
    except Exception as e:
        logger.error(f"Error in check_and_force_join_after_click: {e}")
        bot.edit_message_text(
            f"{P_WARN} <b>Error checking membership.</b>\nPlease try again.",
            chat_id,
            message_id
        )

# ================= TELEGRAM CLIENT HELPERS (TELEPHON) =================
async def get_active_devices(client):
    """Get all active sessions/devices for the account"""
    devices = []
    try:
        from telethon.tl.functions.account import GetAuthorizationsRequest
        
        result = await client(GetAuthorizationsRequest())
        
        for auth in result.authorizations:
            auth_hash = getattr(auth, 'hash', None)
            
            device_info = {
                'device_model': getattr(auth, 'device_model', 'Unknown'),
                'device_platform': getattr(auth, 'platform', 'Unknown'),
                'app_name': getattr(auth, 'app_name', 'Unknown'),
                'app_version': getattr(auth, 'app_version', 'Unknown'),
                'date_created': getattr(auth, 'date_created', None),
                'date_active': getattr(auth, 'date_active', None),
                'ip_address': getattr(auth, 'ip', 'Unknown'),
                'location': getattr(auth, 'location', 'Unknown'),
                'is_current': getattr(auth, 'current', False),
                'session_id': auth_hash
            }
            devices.append(device_info)
    except Exception as e:
        logger.error(f"Failed to get devices: {e}")
    return devices

async def logout_device(client, session_id):
    """Logout a specific device by session ID (hash)"""
    try:
        from telethon.tl.functions.account import ResetAuthorizationRequest
        
        if isinstance(session_id, str) and session_id.isdigit():
            session_id = int(session_id)
        elif isinstance(session_id, str) and len(session_id) > 10:
            try:
                session_id = int(session_id)
            except:
                pass
                
        await client(ResetAuthorizationRequest(session_id))
        return True
    except Exception as e:
        logger.error(f"Failed to logout device: {e}")
        return False

async def detect_account_year_fixed(client):
    """Fixed year detection using TGDNAbot - Reads actual account year with multiple methods"""
    year = None
    try:
        # Step 1: Clear any existing dialog with TGDNAbot
        try:
            await client.delete_dialog('TGDNAbot')
            await asyncio.sleep(1)
        except:
            pass
        
        # Step 2: Send /start to TGDNAbot
        await client.send_message('TGDNAbot', '/start')
        await asyncio.sleep(2)
        
        # Step 3: Get user ID and send to bot
        me = await client.get_me()
        await client.send_message('TGDNAbot', str(me.id))
        await asyncio.sleep(4)  # Increased wait time for response
        
        # Step 4: Get messages from TGDNAbot
        msgs = await client.get_messages('TGDNAbot', limit=15)
        
        # Debug: Print all messages to see what we get
        for idx, msg in enumerate(msgs):
            if msg and msg.text:
                print(f"Message {idx}: {msg.text[:300]}...")
        
        # Step 5: Look for year patterns in messages
        for msg in msgs:
            if msg and msg.text:
                text = msg.text
                
                # Check for exact year patterns
                patterns = [
                    # Pattern 1: "Created: 2023" or "Created on 2023"
                    r'(?:Created|Creation|Registered|Registration|Account|Date)(?:\s+on|\s+at|\s*:|\s+)[''"\s]*(\d{4})',
                    
                    # Pattern 2: "2023 (created)" or "2023 - Age"
                    r'(\d{4})[\s\-]+(?:created|age|registration)',
                    
                    # Pattern 3: "Age: 2 years" -> calculate year
                    r'Age:?\s+(\d+)\s+years?',
                    
                    # Pattern 4: "Account created: 2023"
                    r'Account created:?\s+(\d{4})',
                    
                    # Pattern 5: "Registration date: 2023"
                    r'Registration date:?\s+(\d{4})',
                    
                    # Pattern 6: "Created: 2023-01-01" or "2023/01/01"
                    r'(\d{4})[/\-]\d{1,2}[/\-]\d{1,2}',
                    
                    # Pattern 7: Just a 4-digit year in the message
                    r'\b(20\d{2})\b',
                    
                    # Pattern 8: "Member since 2023"
                    r'Member since:?\s+(\d{4})',
                    
                    # Pattern 9: "Joined: 2023"
                    r'Joined:?\s+(\d{4})',
                ]
                
                for pattern in patterns:
                    match = re.search(pattern, text, re.IGNORECASE)
                    if match:
                        found = match.group(1)
                        # If it's a 4-digit year
                        if found.isdigit() and len(found) == 4:
                            year = int(found)
                            print(f"✅ Detected year: {year} from pattern: {pattern}")
                            return year
                        # If it's an age (years)
                        elif found.isdigit() and int(found) < 100:
                            age = int(found)
                            year = datetime.now().year - age
                            print(f"✅ Calculated year from age: {age} years -> {year}")
                            return year
        
        # Step 6: Try to get year from message timestamps
        for msg in msgs:
            if msg and msg.text and ('created' in msg.text.lower() or 'age' in msg.text.lower() or 'member' in msg.text.lower()):
                if msg.date:
                    # Get year from message date
                    year = msg.date.year
                    print(f"✅ Got year from message timestamp: {year}")
                    return year
        
        # Step 7: If no year found, try alternative method - check account creation date from Telegram
        try:
            # Get full user info
            me_full = await client.get_me()
            if hasattr(me_full, 'date'):
                # If the user object has a date attribute
                year = me_full.date.year
                print(f"✅ Got year from user date: {year}")
                return year
        except:
            pass
        
        # Step 8: Try to get from dialogs
        try:
            dialogs = await client.get_dialogs(limit=1)
            for dialog in dialogs:
                if dialog.date:
                    year = dialog.date.year
                    print(f"✅ Got year from dialog: {year}")
                    return year
        except:
            pass
        
        # Step 9: If still no year, use current year minus 1 (as fallback)
        if year is None:
            year = datetime.now().year - 1
            print(f"⚠️ No year detected, using fallback: {year}")
        
        return year
        
    except Exception as e:
        print(f"❌ Year detection error: {e}")
        # Return a reasonable default (current year minus 1)
        return datetime.now().year - 1

async def get_account_age(client):
    """Get account age directly from Telegram"""
    try:
        # Try to get user info
        me = await client.get_me()
        
        # Check if user has a date attribute
        if hasattr(me, 'date'):
            creation_date = me.date
            age_days = (datetime.now() - creation_date).days
            age_years = age_days // 365
            year = creation_date.year
            print(f"📅 Account created: {creation_date.strftime('%Y-%m-%d')}")
            print(f"📅 Account age: {age_years} years ({age_days} days)")
            return year, age_years, creation_date
        
        # Alternative: Try to get from account info via API
        from telethon.tl.functions.account import GetAccountTTLRequest
        # This is a different approach
        try:
            # Try to get the user's creation date from their ID
            # Telegram IDs are sequential, older accounts have smaller IDs
            # This is approximate but can help
            user_id = me.id
            # Rough estimation: IDs around 1-100 million are old accounts
            if user_id < 10000000:
                year = 2013
            elif user_id < 50000000:
                year = 2015
            elif user_id < 100000000:
                year = 2017
            elif user_id < 500000000:
                year = 2019
            elif user_id < 1000000000:
                year = 2021
            else:
                year = 2023
            print(f"📅 Estimated year from ID: {year}")
            return year, datetime.now().year - year, None
        except:
            pass
            
    except Exception as e:
        print(f"❌ Account age error: {e}")
    
    return None, None, None

# ================= LOGGING LOGIC =================
async def process_referral_bonus(uid, amount):
    row = cur.execute("SELECT referred_by FROM users WHERE user_id=?", (uid,)).fetchone()
    ref = row[0] if row else None
    if ref:
        pct_row = cur.execute("SELECT value FROM settings WHERE key='ref_percent'").fetchone()
        pct = float(pct_row[0]) if pct_row else 3.0
        if pct > 0:
            bonus = int(amount * (pct / 100))
            if bonus > 0:
                update_balance(ref, bonus)
                try:
                    bot.send_message(ref, f"{P_GIFT} <b>Referral Bonus!</b>\nYour referral <code>{uid}</code> deposited {P_INR}{amount}.\n{P_YES} You earned <b>{P_INR}{bonus}</b>!")
                except: pass

async def log_primary_deposit(uid, amt, method):
    try:
        try:
            user = bot.get_chat(uid)
            username = html.escape(user.username) if user.username else "NoUsername"
        except:
            username = "NoUsername"
            
        t = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        msg = (
            f"{P_YES} <b>NEW DEPOSIT SUCCESSFUL</b>\n\n"
            f"{P_ACC} Uꜱᴇʀ ID: <code>{uid}</code>\n"
            f"👤 Uꜱᴇʀɴᴀᴍᴇ: @{username}\n"
            f"{P_MONEY} Aᴍᴏᴜɴᴛ: {P_INR}{amt}\n"
            f"{P_CARD} Mᴇᴛʜᴏᴅ: {method}\n"
            f"{P_TIME} Tɪᴍᴇ: {t}\n\n"
            f"<i>Tʜᴀɴᴋꜱ Fᴏʀ Dᴇᴘᴏꜱɪᴛ Iɴ Fʀᴇꜱʜ Tɢ!</i>"
        )
        
        # Send to LOG_CHANNEL_ID using Telethon
        try:
            await telethon_client.send_message(LOG_CHANNEL_ID, msg, parse_mode='html')
            logger.info(f"✅ Deposit log sent to channel {LOG_CHANNEL_ID}")
        except Exception as e:
            logger.error(f"Failed to send deposit log via Telethon: {e}")
            # Fallback to bot
            try:
                bot.send_message(LOG_CHANNEL_ID, msg, parse_mode='HTML')
            except Exception as e2:
                logger.error(f"Bot fallback failed: {e2}")
                
    except Exception as e:
        logger.error(f"Global Dep Log Err: {e}")
        traceback.print_exc()

async def log_primary_purchase(uid, country, price, amount, year, qty):
    try:
        # Get user info
        try:
            user = bot.get_chat(uid)
            username = html.escape(user.username) if user.username else "NoUsername"
        except:
            username = "NoUsername"
            
        t = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        msg = (
            f"🛒 <b>NEW PURCHASE SUCCESSFUL</b>\n\n"
            f"🆔 User ID: <code>{uid}</code>\n"
            f"👤 Username: @{username}\n"
            f"🌎 Country: {country}\n"
            f"💰 Price: ₹{price}\n"
            f"💳 Total Paid: ₹{amount}\n"
            f"📅 Year: {year}\n"
            f"📦 Quantity: {qty}\n"
            f"⏰ Time: {t}\n\n"
            f"<i>Tʜᴀɴᴋꜱ Fᴏʀ Pᴜʀᴄʜᴀꜱᴇ Iɴ Fʀᴇꜱʜ Tɢ!</i>"
        )
        
        # Send to LOG_CHANNEL_ID
        try:
            await telethon_client.send_message(LOG_CHANNEL_ID, msg, parse_mode='html')
            logger.info(f"✅ Purchase log sent to channel {LOG_CHANNEL_ID}")
        except Exception as e:
            logger.error(f"Failed to send purchase log to channel: {e}")
            # Try with bot as fallback
            try:
                bot.send_message(LOG_CHANNEL_ID, msg, parse_mode='HTML')
            except Exception as e2:
                logger.error(f"Bot fallback also failed: {e2}")
        
        # Send to PURCHASE_LOG_CHANNEL_ID if different
        if PURCHASE_LOG_CHANNEL_ID and PURCHASE_LOG_CHANNEL_ID != LOG_CHANNEL_ID:
            try:
                await telethon_client.send_message(PURCHASE_LOG_CHANNEL_ID, msg, parse_mode='html')
            except:
                try:
                    bot.send_message(PURCHASE_LOG_CHANNEL_ID, msg, parse_mode='HTML')
                except:
                    pass
                    
    except Exception as e:
        logger.error(f"Pur Log Err: {e}")
        traceback.print_exc()

async def send_deposit_log_telethon(channel_id, caption, markup, message):
    """Send deposit log to channel using Telethon"""
    try:
        # Get the channel entity
        channel = await telethon_client.get_entity(channel_id)
        
        # Send the message with the photo or text
        if message.photo:
            # Download the photo
            file_info = bot.get_file(message.photo[-1].file_id)
            downloaded_file = bot.download_file(file_info.file_path)
            
            # Send with Telethon
            await telethon_client.send_file(
                channel,
                downloaded_file,
                caption=caption,
                parse_mode='html'
            )
        else:
            # Send text message
            await telethon_client.send_message(
                channel,
                caption + f"\n🔗 Hash: {html.escape(message.text)}",
                parse_mode='html'
            )
        
        # Note: Telethon doesn't support inline keyboards the same way,
        # so we also send with bot for the buttons
        try:
            if message.photo:
                bot.send_photo(channel_id, message.photo[-1].file_id, caption=caption, reply_markup=markup)
            else:
                bot.send_message(channel_id, caption + f"\n🔗 Hash: {html.escape(message.text)}", reply_markup=markup)
        except:
            pass
            
    except Exception as e:
        logger.error(f"Error in send_deposit_log_telethon: {e}")
        raise
# ================= MENU HELPERS =================
def get_persistent_menu(uid):
    markup = ReplyKeyboardMarkup(
    resize_keyboard=True,
    row_width=2
)

    markup.row(
        KeyboardButton(
            text="🛒 Buy Account",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        KeyboardButton(
            text="📁 Buy Sessions",
            style="success",
            icon_custom_emoji_id="5364125616801073577"
        )
    )

    markup.row(
        KeyboardButton(
            text="💰 Deposit",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        KeyboardButton(
            text="👤 My Profile",
            style="success",
            icon_custom_emoji_id="5364125616801073577"
        )
    )

    markup.row(
        KeyboardButton(
            text="📊 My Stats",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        KeyboardButton(
            text="📞 Support",
            style="success",
            icon_custom_emoji_id="5364125616801073577"
        )
    )

    if is_admin(uid):
        markup.row(
            KeyboardButton(
                text="🔐 Admin Panel",
                style="danger",
                icon_custom_emoji_id="5364125616801073577"
            )
        )

    return markup
def send_main_menu(chat_id, uid):
    me = bot.get_me()
    pct_row = cur.execute("SELECT value FROM settings WHERE key='ref_percent'").fetchone()
    pct = pct_row[0] if pct_row else "3"
    msg = (f"👋 <b>Welcome to STY Store!</b>\n\n"
           f"{P_GIFT} <b>Refer & Earn:</b>\nInvite friends and earn {pct}% of their deposits!\n"
           f"{P_GLOBE} <code>https://t.me/{me.username}?start=ref_{uid}</code>")
    
    bot.send_message(chat_id, msg, reply_markup=get_persistent_menu(uid))

# ================= DEPOSIT HANDLERS =================
def deposit_menu(chat_id):
    msg = f"{P_CARD} <b>Select Payment Method:</b>\n\n{P_ON} Choose Automatic for instant credit.\n{P_WAIT} Choose Manual for other methods."
    markup = InlineKeyboardMarkup(row_width=2)
    buttons = [
        InlineKeyboardButton(
            "⚡ UPI Automatic",
            callback_data="dep_upi",
            style="success",
            icon_custom_emoji_id="5364125616801073577"
        ),
        InlineKeyboardButton(
            "👛 Cwallet (USDT)",
            callback_data="depm_Cwallet",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        InlineKeyboardButton(
            "💲 Binance (USDT)",
            callback_data="depm_Binance",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        InlineKeyboardButton(
            "🔷 TRON (TRC20)",
            callback_data="depm_Tron",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        InlineKeyboardButton(
            "🟣 POLYGON (POS)",
            callback_data="depm_Polygon",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
    ]
    customs = cur.execute("SELECT name FROM custom_payments").fetchall()
    for c in customs:
        buttons.append(
            InlineKeyboardButton(
                f"💳 {c[0]}",
                callback_data=f"depm_{c[0]}",
                style="primary",
                icon_custom_emoji_id="5364125616801073577"
            )
        )
    
    buttons.append(
        InlineKeyboardButton(
            "❌ Cancel",
            callback_data="cancel_action",
            style="danger",
            icon_custom_emoji_id="5364125616801073577"
        )
    )
    markup.add(*buttons)
    bot.send_message(chat_id, msg, reply_markup=markup)
def get_keypad():
    markup = InlineKeyboardMarkup(row_width=3)
    buttons = [
        InlineKeyboardButton("1", callback_data="kp_1"),
        InlineKeyboardButton("2", callback_data="kp_2"),
        InlineKeyboardButton("3", callback_data="kp_3"),
        InlineKeyboardButton("4", callback_data="kp_4"),
        InlineKeyboardButton("5", callback_data="kp_5"),
        InlineKeyboardButton("6", callback_data="kp_6"),
        InlineKeyboardButton("7", callback_data="kp_7"),
        InlineKeyboardButton("8", callback_data="kp_8"),
        InlineKeyboardButton("9", callback_data="kp_9"),
        InlineKeyboardButton("🔙 Del", callback_data="kp_del"),
        InlineKeyboardButton("0", callback_data="kp_0"),
        InlineKeyboardButton("✅ Confirm", callback_data="kp_done"),
        InlineKeyboardButton("❌ Cancel", callback_data="cancel_action")
    ]
    markup.add(*buttons)
    return markup

def get_admin_custom_keypad(dep_id):
    markup = InlineKeyboardMarkup(row_width=3)
    buttons = [
        InlineKeyboardButton("1", callback_data=f"dkp|{dep_id}|1"),
        InlineKeyboardButton("2", callback_data=f"dkp|{dep_id}|2"),
        InlineKeyboardButton("3", callback_data=f"dkp|{dep_id}|3"),
        InlineKeyboardButton("4", callback_data=f"dkp|{dep_id}|4"),
        InlineKeyboardButton("5", callback_data=f"dkp|{dep_id}|5"),
        InlineKeyboardButton("6", callback_data=f"dkp|{dep_id}|6"),
        InlineKeyboardButton("7", callback_data=f"dkp|{dep_id}|7"),
        InlineKeyboardButton("8", callback_data=f"dkp|{dep_id}|8"),
        InlineKeyboardButton("9", callback_data=f"dkp|{dep_id}|9"),
        InlineKeyboardButton("🔙 Del", callback_data=f"dkp|{dep_id}|del"),
        InlineKeyboardButton("0", callback_data=f"dkp|{dep_id}|0"),
        InlineKeyboardButton("✅ Confirm", callback_data=f"dkp|{dep_id}|conf"),
        InlineKeyboardButton("❌ Cancel", callback_data=f"dkp|{dep_id}|cancel")
    ]
    markup.add(*buttons)
    return markup

def manual_deposit_init(call, method):
    uid = call.from_user.id
    deposit_input[uid] = {'step': 'wait_amt', 'method': method}
    markup = InlineKeyboardMarkup()
    markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
    
    # Show different message for USDT methods (Cwallet, Binance, Tron, Polygon)
    if method in ("Cwallet", "Binance", "Tron", "Polygon"):
        bot.edit_message_text(
            f"{P_CARD} <b>{method} Deposit</b>\n\n"
            f"{P_USDT} <b>Enter the AMOUNT in USDT you want to deposit</b>\n"
            f"<i>Example: 10 for $10 USDT</i>\n\n"
            f"💰 <b>Current Rate:</b> 1 USDT = {P_INR}{get_usdt_rate()}\n\n"
            f"👇 <b>Reply with the USDT amount:</b>",
            call.message.chat.id,
            call.message.message_id,
            reply_markup=markup
        )
    else:
        bot.edit_message_text(
            f"{P_CARD} <b>{method} Deposit</b>\n\n"
            f"{P_INR} <b>Enter the AMOUNT in INR you want to deposit</b>\n"
            f"<i>Minimum: ₹10</i>\n\n"
            f"💰 <b>Current Rate:</b> 1 USDT = {P_INR}{get_usdt_rate()}\n\n"
            f"👇 <b>Reply with the INR amount:</b>",
            call.message.chat.id,
            call.message.message_id,
            reply_markup=markup
        )
def init_upi_keypad(call):
    uid = call.from_user.id
    deposit_input[uid] = {'step': 'fampay_keypad', 'val': '0'}
    bot.edit_message_text(
        f"{P_KEY} <b>ENTER AMOUNT IN INR (Min ₹1, Max ₹50000)</b>\n\n{P_MONEY} <code>₹0</code>",
        call.message.chat.id,
        call.message.message_id,
        reply_markup=get_keypad()
    )

def keypad_logic(call):
    uid = call.from_user.id
    action = call.data.replace("kp_", "")
    curr = deposit_input.get(uid, {}).get('val', "0")

    if action.isdigit():
        if curr == "0": curr = action
        else: curr += action
        if len(curr) > 5: curr = curr[:5]
    elif action == "del":
        curr = curr[:-1] or "0"
    elif action == "done":
        amt = int(curr)
        if amt < 1:
            bot.answer_callback_query(call.id, "⚠️ Minimum Deposit is ₹1", show_alert=True)
            return
        show_upi_qr(call, amt)
        return
    
    deposit_input[uid] = {'step': 'upi_keypad', 'val': curr}
    bot.edit_message_text(
        f"{P_KEY} <b>ENTER AMOUNT IN INR</b>\n\n{P_MONEY} <code>₹{curr}</code>",
        call.message.chat.id,
        call.message.message_id,
        reply_markup=get_keypad()
    )

def show_upi_qr(call, amount):
    uid = call.from_user.id
    order_id = f"FAM_{uid}_{int(time.time())}"
    
    bot.answer_callback_query(call.id, "Generating Payment QR...")
    
    upi_link = f"upi://pay?pa={FAMPAY_UPI_ID}&pn=Merchant&am={amount}&cu=INR"
    qr_url = f"https://quickchart.io/qr?text={urllib.parse.quote(upi_link)}&size=400"
    
    cur.execute("INSERT INTO upi_orders (order_id, user_id, amount, status) VALUES (?,?,?,?)",
                (order_id, uid, amount, "pending"))
    db.commit()
    
    # Get USDT rate
    rate = get_usdt_rate()
    usdt_amt = round(amount / rate, 2)
    
    msg = (f"{P_UPI} <b>FamPay Automatic Payment</b>\n\n"
           f"{P_MONEY} <b>Amount to Pay:</b>\n"
           f"   {P_INR} <b>INR:</b> <code>{amount}</code>\n"
           f"{P_ID} Order ID: <code>{order_id}</code>\n\n"
           f"👇 Scan the QR below with any UPI app.\n"
           f"Click <b>✅ Check Payment</b> after paying.\n\n"
           f"<i>The bot will automatically verify and credit your balance.</i>")
    
    markup = InlineKeyboardMarkup(row_width=1)
    markup.add(
        InlineKeyboardButton("✅ Check Payment", callback_data=f"check_fampay_{order_id}"),
        InlineKeyboardButton("❌ Cancel", callback_data="cancel_action")
    )
    
    import requests
    try:
        response = requests.get(qr_url)
        if response.status_code == 200:
            with tempfile.NamedTemporaryFile(suffix='.png', delete=False) as tmp_file:
                tmp_file.write(response.content)
                tmp_path = tmp_file.name
            
            sent_msg = bot.send_photo(uid, open(tmp_path, 'rb'), caption=msg, reply_markup=markup)
            os.unlink(tmp_path)
        else:
            bot.send_message(uid, f"{P_NO} Failed to generate QR code")
            return
    except Exception as e:
        bot.send_message(uid, f"{P_NO} Failed to generate QR code: {e}")
        return
    
    asyncio.run_coroutine_threadsafe(
        auto_check_fampay_task(uid, order_id, amount, sent_msg),
        telethon_loop
    )

async def auto_check_fampay_task(uid, order_id, amount, sent_msg):
    for attempt in range(60):
        await asyncio.sleep(10)
        
        row = cur.execute("SELECT amount, status FROM upi_orders WHERE order_id=?", (order_id,)).fetchone()
        if not row or row[1] != 'pending':
            return
        
        requested_amount = row[0]
        verify_url = f"{FAMPAY_API_URL}/verify?amount={requested_amount}&utr={order_id}&txn_id={order_id}&tg_chat_id={uid}&tg_bot_token={BOT_TOKEN}"
        
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(verify_url, timeout=aiohttp.ClientTimeout(total=15)) as resp:
                    data = await resp.json()
                    
                    if data.get("success"):
                        amount_credited = data.get("amount_credited", requested_amount)
                        amount_paid = data.get("amount_paid", requested_amount)
                        is_partial = data.get("is_partial", False)
                        
                        async with get_user_lock(uid):
                            row2 = cur.execute("SELECT status FROM upi_orders WHERE order_id=?", (order_id,)).fetchone()
                            if row2 and row2[0] == 'pending':
                                prev_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (uid,)).fetchone()
                                prev_bal = prev_row[0] if prev_row else 0
                                
                                update_balance(uid, amount_credited)
                                cur.execute("UPDATE upi_orders SET status='success' WHERE order_id=?", (order_id,))
                                cur.execute("UPDATE users SET total_deposited = total_deposited + ? WHERE user_id=?", (amount_credited, uid))
                                
                                curr_rev_row = cur.execute("SELECT value FROM settings WHERE key='upi_revenue'").fetchone()
                                curr_rev = float(curr_rev_row[0]) if curr_rev_row else 0.0
                                cur.execute("UPDATE settings SET value=? WHERE key='upi_revenue'", (str(curr_rev + amount_credited),))
                                db.commit()
                                
                                await process_referral_bonus(uid, amount_credited)
                                await log_primary_deposit(uid, amount_credited, "FamPay Automatic")
                                
                                if is_partial:
                                    user_msg = (f"{P_YES} <b>Partial Payment Received!</b>\n\n"
                                                f"💰 Amount Paid: ₹{amount_paid}\n"
                                                f"💳 Amount Credited: ₹{amount_credited}\n"
                                                f"📉 Previous Balance: ₹{prev_bal}\n"
                                                f"📈 New Balance: ₹{prev_bal + amount_credited}\n\n"
                                                f"⚠️ You paid less than the requested amount.")
                                else:
                                    user_msg = (f"{P_YES} <b>FamPay Deposit Approved!</b>\n\n"
                                                f"{P_MONEY} <b>Amount Added:</b> ₹{amount_credited}\n"
                                                f"📉 <b>Previous Balance:</b> ₹{prev_bal}\n"
                                                f"📈 <b>New Balance:</b> ₹{prev_bal + amount_credited}")
                                
                                bot.send_message(uid, user_msg)
                                
                                try:
                                    bot.edit_message_text(
                                        f"{P_YES} <b>Payment Successful! ₹{amount_credited} added to your wallet.</b>",
                                        uid,
                                        sent_msg.message_id
                                    )
                                except:
                                    pass
                        return
        except Exception as e:
            print(f"FamPay verification error: {e}")
            continue

# ================= BUYING FLOW =================
def show_countries(call_or_msg, flow, page=1):
    if isinstance(call_or_msg, types.CallbackQuery):
        chat_id = call_or_msg.message.chat.id
        message_id = call_or_msg.message.message_id
        is_callback = True
    else:
        chat_id = call_or_msg.chat.id
        is_callback = False
    
    limit = 10
    offset = (page - 1) * limit
    
    total_row = cur.execute("SELECT COUNT(DISTINCT country_name) FROM stock WHERE available=1").fetchone()
    total = total_row[0] if total_row else 0
    rows = cur.execute("SELECT country_icon, country_name, COUNT(*) FROM stock WHERE available=1 GROUP BY country_name ORDER BY country_name ASC LIMIT ? OFFSET ?", (limit, offset)).fetchall()
    
    if not rows and page == 1:
        err_msg = f"{P_NO} <b>Stock is Empty right now. Check back later!</b>"
        if is_callback:
            bot.edit_message_text(err_msg, chat_id, message_id)
        else:
            bot.send_message(chat_id, err_msg)
        return
    
    title = f"{P_STORE} <b>Bulk Sessions Menu</b>" if flow == 'bulk' else f"{P_CART} <b>Single Account Menu</b>"
    msg = f"{title}\n\n{P_GLOBE} <b>Select a Region below to view available numbers (Page {page}).</b>\n{P_USDT} Rate: 1 USDT = {P_INR}{get_usdt_rate()}\n\n"
    
    markup = InlineKeyboardMarkup(row_width=1)
    for icon, name, count in rows:
        markup.add(
            InlineKeyboardButton(
                f"{icon} {name} ({count})",
                callback_data=f"bc|{flow}|{name[:20]}",
                style="primary",
                icon_custom_emoji_id="5364125616801073577"
            )
        )
    nav_buttons = []
    if page > 1:
        nav_buttons.append(
            InlineKeyboardButton(
                "Prev",
                callback_data=f"pg_c|{flow}|{page-1}",
                style="primary",
                icon_custom_emoji_id="5364125616801073577"
            )
        )
    if offset + limit < total:
        nav_buttons.append(
            InlineKeyboardButton(
                "Next",
                callback_data=f"pg_c|{flow}|{page+1}",
                style="primary",
                icon_custom_emoji_id="5364125616801073577"
            )
        )
    if nav_buttons:
        markup.row(*nav_buttons)
    markup.add(
        InlineKeyboardButton(
            "Cancel",
            callback_data="cancel_action",
            style="danger",
            icon_custom_emoji_id="5364125616801073577"
        )
    )
    
    if is_callback:
        bot.edit_message_text(msg, chat_id, message_id, reply_markup=markup)
    else:
        bot.send_message(chat_id, msg, reply_markup=markup)

def show_years(call, flow, country):
    rows = cur.execute("SELECT account_year, price, COUNT(*) FROM stock WHERE available=1 AND country_name LIKE ? GROUP BY account_year, price ORDER BY account_year DESC", (f"{country}%",)).fetchall()
    if not rows:
        return bot.answer_callback_query(call.id, "❌ Out of stock for this country.", show_alert=True)

    uid = call.from_user.id
    disc_row = cur.execute("SELECT discount FROM users WHERE user_id=?", (uid,)).fetchone()
    discount = disc_row[0] if disc_row else 0
    usdt_rate = get_usdt_rate()

    msg = f"{P_CAL} <b>Select Account Year</b>\n{P_GLOBE} Country: <b>{country}</b>\n"
    msg += f"💱 <b>Rate:</b> 1 USDT = {P_INR}{usdt_rate}\n\n"
    markup = InlineKeyboardMarkup(row_width=1)
    
    for year, price, count in rows:
        disp_p = price if discount == 0 else int(price * (100 - discount) / 100)
        usdt_price = round(disp_p / usdt_rate, 2)
        disc_text = f" (-{discount}%)" if discount > 0 else ""
        markup.add(InlineKeyboardButton(
            f"{year} | {P_INR}{disp_p} (${usdt_price}){disc_text} | {count}", 
            callback_data=f"by|{flow}|{country}|{year}|{price}"
        ))

    markup.add(InlineKeyboardButton("Back to Countries", callback_data=f"pg_c|{flow}|1"))
    bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)

def confirm_purchase(call, country, year, price_str):
    uid = call.from_user.id
    base_price = int(price_str)
    
    bal_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (uid,)).fetchone()
    bal = bal_row[0] if bal_row else 0
    disc_row = cur.execute("SELECT discount FROM users WHERE user_id=?", (uid,)).fetchone()
    discount = disc_row[0] if disc_row else 0
    final_price = base_price if discount == 0 else int(base_price * (100 - discount) / 100)
    
    # Get USDT rate
    usdt_rate = get_usdt_rate()
    price_usdt = round(final_price / usdt_rate, 2)
    bal_usdt = round(bal / usdt_rate, 2)

    msg = (f"{P_CART} <b>Confirm Your Purchase</b>\n\n"
           f"{P_FLAG} <b>Country:</b> {country}\n"
           f"{P_CAL} <b>Year:</b> {year}\n"
           f"{P_MONEY} <b>Final Price:</b>\n"
           f"   {P_INR} INR: <b>{final_price}</b>\n"
           f"   {P_USDT} USDT: <b>${price_usdt}</b>\n"
           f"   💱 Rate: 1 USDT = {P_INR}{usdt_rate}\n\n"
           f"{P_CARD} <b>Your Balance:</b>\n"
           f"   {P_INR} INR: <b>{bal}</b>\n"
           f"   {P_USDT} USDT: <b>${bal_usdt}</b>\n\n"
           f"❓ Do you want to proceed with this purchase?")
    
    markup = InlineKeyboardMarkup(row_width=2)
    markup.add(
        InlineKeyboardButton("Yes, Buy Now", callback_data=f"buy_cf|{country}|{year}|{base_price}"),
        InlineKeyboardButton("No, Cancel", callback_data="cancel_action")
    )
    bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)

def process_purchase(call, country, year_str, price_str):
    uid = call.from_user.id
    base_price = int(price_str)

    disc_row = cur.execute("SELECT discount FROM users WHERE user_id=?", (uid,)).fetchone()
    discount = disc_row[0] if disc_row else 0
    final_price = base_price if discount == 0 else int(base_price * (100 - discount) / 100)

    row = cur.execute("SELECT phone, session_file, country_icon, account_year, twofa FROM stock WHERE country_name LIKE ? AND account_year=? AND price=? AND available=1 LIMIT 1", (f"{country}%", int(year_str), base_price)).fetchone()

    if not row:
        return bot.answer_callback_query(call.id, "❌ Sold out! Another user just bought this account.", show_alert=True)
    
    phone, sess, c_icon, actual_year, twofa_pass = row

    cur.execute("UPDATE users SET balance = balance - ? WHERE user_id=? AND balance >= ?", (final_price, uid, final_price))
    if cur.rowcount == 0:
        return bot.answer_callback_query(call.id, f"❌ Insufficient Balance! Need ₹{final_price}", show_alert=True)

    cur.execute("UPDATE stock SET available=0 WHERE phone=?", (phone,))
    db.commit()

    bot.edit_message_text(f"🔄 <b>Fetching Number (+{phone})...</b>", call.message.chat.id, call.message.message_id)
    
    clean_sess = sess if not sess.endswith(".session") else sess[:-8]
    
    # Use Telethon for session management
    asyncio.run_coroutine_threadsafe(
        process_purchase_telethon(uid, phone, sess, clean_sess, c_icon, country, actual_year, twofa_pass, final_price, call.message.chat.id, call.message.message_id),
        telethon_loop
    )

async def process_purchase_telethon(uid, phone, sess, clean_sess, c_icon, country, actual_year, twofa_pass, final_price, chat_id, message_id):
    client = TelegramClient(clean_sess, API_ID, API_HASH)
    
    try:
        await client.connect()
        if not await client.is_user_authorized():
            raise Exception("Session dead")
    except Exception:
        async with get_user_lock(uid):
            cur.execute("DELETE FROM stock WHERE phone=?", (phone,))
            cur.execute("UPDATE users SET balance = balance + ? WHERE user_id=?", (final_price, uid))
            db.commit()
        await client.disconnect()
        delete_session_files(sess)
        bot.edit_message_text(
            f"{P_NO} <b>Account Invalid.</b> Money refunded. Try buying another.",
            chat_id,
            message_id
        )
        return

    msg = (f"{P_YES} <b>Order Active!</b>\n\n"
           f"{P_PHONE} <b>Phone:</b> <code>{phone}</code>\n"
           f"{P_FLAG} <b>Country:</b> {c_icon} {country}\n\n"
           f"🔻 <b>INSTRUCTIONS:</b>\n"
           f"1. Open Telegram & Add Account\n"
           f"2. Enter the number above.\n"
           f"3. {P_WAIT} <b>Please wait!</b> The bot is actively listening for your OTP and will send it automatically once Telegram delivers it.\n\n"
           f"<i>Note: If no OTP is received within 10 minutes, the bot will auto-cancel and refund your balance automatically.</i>")
    
    sent_msg = bot.edit_message_text(msg, chat_id, message_id)
    
    active_orders[phone] = {
        'uid': uid,
        'client': client,
        'sess': sess,
        'start_time': time.time(),
        'paid': False,
        'price': final_price,
        'country': country,
        'year': actual_year,
        'c_icon': c_icon,
        'twofa': twofa_pass,
        'msg_id': sent_msg.message_id,
        'chat_id': chat_id
    }
    asyncio.create_task(auto_otp_task(phone))

async def auto_otp_task(phone):
    if phone not in active_orders:
        return
    
    order = active_orders[phone]
    client = order['client']
    start_time = order['start_time']
    uid = order['uid']
    msg_id = order['msg_id']
    chat_id = order['chat_id']
    
    while time.time() - start_time < AUTO_CANCEL_SECONDS:
        if phone not in active_orders:
            return
        try:
            msgs = await client.get_messages(777000, limit=5)
            code = None
            for m in msgs:
                if m.date.timestamp() > start_time - 10:
                    if m.message and re.search(OTP_REGEX, m.message) and "Login detected" not in m.message:
                        code = re.search(OTP_REGEX, m.message).group()
                        break
            
            if code:
                if not order['paid']:
                    order['paid'] = True
                    async with get_user_lock(uid):
                        cur.execute("INSERT INTO orders (user_id, country, year, price, phone, otp) VALUES (?,?,?,?,?,?)", (uid, order['country'], order['year'], order['price'], phone, code))
                        cur.execute("DELETE FROM stock WHERE phone=?", (phone,))
                        db.commit()
                    
                    await log_primary_purchase(uid, order['country'], order['price'], order['price'], order['year'], 1)
                
                twofa_text = f"{P_2FA} <b>2FA:</b> <code>{order['twofa']}</code>" if order['twofa'] != "None" else f"🔓 <b>2FA:</b> <code>Disabled (No Password)</code>"
                msg_text = (f"{P_YES} <b>Latest OTP Fetched!</b>\n\n"
                            f"{P_PHONE} <b>Phone:</b> <code>{phone}</code>\n"
                            f"{P_FLAG} <b>Country:</b> {order['c_icon']} {order['country']}\n"
                            f"{P_OTP} <b>OTP:</b> <code>{code}</code>\n"
                            f"{twofa_text}\n\n"
                            f"{P_WARN} <b>⚠️ SECURITY ALERT!</b>\n"
                            f"Please click <b>📱 Manage Devices</b> below and logout any device that does NOT belong to you!\n"
                            f"<i>This ensures your account is secure.</i>")
                
                markup = InlineKeyboardMarkup(row_width=1)
                markup.add(
                    InlineKeyboardButton("🔄 Get OTP Again", callback_data=f"get_otp_again|{phone}"),
                    InlineKeyboardButton("📱 Manage Devices", callback_data=f"manage_devices|{phone}"),
                    InlineKeyboardButton("🚪 Finish & Logout", callback_data=f"logout_bot|{phone}")
                )
                
                try:
                    bot.edit_message_text(msg_text, chat_id, msg_id, reply_markup=markup)
                except:
                    bot.send_message(uid, msg_text, reply_markup=markup)
                return
        except Exception:
            pass
        await asyncio.sleep(6)
        
    if phone in active_orders and not active_orders[phone]['paid']:
        order = active_orders.pop(phone)
        try:
            await order['client'].disconnect()
        except:
            pass
        
        async with get_user_lock(uid):
            cur.execute("UPDATE users SET balance = balance + ? WHERE user_id=?", (order['price'], uid))
            cur.execute("UPDATE stock SET available=1 WHERE phone=?", (phone,))
            db.commit()
            
        try:
            bot.edit_message_text(
                f"{P_TIME} <b>Order Expired!</b>\nThe 10-minute limit for <code>{phone}</code> ran out. Your money ({P_INR}{order['price']}) has been automatically refunded.",
                chat_id,
                msg_id
            )
        except:
            pass

def init_session_purchase(call, country, year, price_str):
    uid = call.from_user.id
    price = int(price_str)
    stock_row = cur.execute("SELECT COUNT(*) FROM stock WHERE country_name LIKE ? AND account_year=? AND price=? AND available=1", (f"{country}%", int(year), price)).fetchone()
    stock = stock_row[0] if stock_row else 0
    if stock == 0:
        return bot.answer_callback_query(call.id, "❌ Out of stock!", show_alert=True)
    
    session_buy_state[uid] = {'country': country, 'year': year, 'price': price, 'stock': stock}
    disc_row = cur.execute("SELECT discount FROM users WHERE user_id=?", (uid,)).fetchone()
    discount = disc_row[0] if disc_row else 0
    p_disp = price if discount == 0 else int(price * (100 - discount) / 100)
    
    msg = (f"{P_STORE} <b>Buy {country} ({year}) Sessions</b>\n\n"
           f"{P_MONEY} <b>Price per session:</b> {P_INR}{p_disp}\n"
           f"{P_PKG} <b>Available Stock:</b> {stock}\n\n"
           f"👇 <b>Reply to this message</b> with the <b>Number of Sessions</b> you want to buy.")
    
    markup = InlineKeyboardMarkup()
    markup.add(InlineKeyboardButton("Cancel", callback_data="cancel_action"))
    bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)

def process_bulk_sessions(message, uid, qty, state, final_cost):
    country, year, price = state['country'], int(state['year']), int(state['price'])
    bot.send_message(message.chat.id, f"{P_WAIT} <b>Processing your sessions...</b>")

    rows = cur.execute("SELECT phone, session_file, twofa, account_year FROM stock WHERE country_name LIKE ? AND account_year=? AND price=? AND available=1 LIMIT ?", (f"{country}%", year, price, qty)).fetchall()
    if len(rows) < qty:
        return bot.send_message(message.chat.id, f"{P_NO} Stock changed during processing. Purchase Cancelled.")
    
    cur.execute("UPDATE users SET balance = balance - ? WHERE user_id=? AND balance >= ?", (final_cost, uid, final_cost))
    if cur.rowcount == 0:
        return bot.send_message(message.chat.id, f"{P_NO} Insufficient Balance! Purchase Cancelled.")

    phones = [r[0] for r in rows]
    placeholders = ",".join("?" for _ in phones)
    cur.execute(f"UPDATE stock SET available=0 WHERE phone IN ({placeholders})", phones)
    
    price_per_acc = final_cost // qty
    for p in phones:
        cur.execute("INSERT INTO orders (user_id, country, price, phone, otp) VALUES (?,?,?,?,?)", (uid, country, price_per_acc, p, "SESSION_FILES"))
    db.commit()

    zip_name = f"sessions_{uid}_{int(time.time())}.zip"
    numbers_txt = ""

    try:
        with zipfile.ZipFile(zip_name, 'w') as zf:
            for phone, sess_file, twofa_pass, y in rows:
                base_s = sess_file if not sess_file.endswith(".session") else sess_file[:-8]
                for ext in ['.session', '.session-wal', '.session-shm', '.session-journal']:
                    src = base_s + ext
                    if os.path.exists(src):
                        zf.write(src, os.path.basename(src))
                
                pass_text = twofa_pass if twofa_pass != "None" else "No_Password"
                numbers_txt += f"+{phone} | pass:{pass_text}\n"
            
            numbers_txt += "\n\nPurchased from @styotpbot\n"
            zf.writestr("numbers.txt", numbers_txt)
            
        caption = f"{P_YES} <b>Bulk Purchase Successful!</b>\n\n{P_FLAG} Country: {country}\n{P_PKG} Quantity: {qty}\n{P_CARD} Total Paid: {P_INR}{final_cost}\n\n<i>(Note: Sessions are safely provided, the bot does not keep them active)</i>"
        with open(zip_name, 'rb') as f:
            bot.send_document(uid, f, caption=caption)
        asyncio.run_coroutine_threadsafe(
            log_primary_purchase(uid, country, price, final_cost, year, qty),
            telethon_loop
        )
    except Exception as e:
        bot.send_message(message.chat.id, f"{P_WARN} Error creating zip: {e}")
    finally:
        if os.path.exists(zip_name):
            os.remove(zip_name)

# ================= STATS & PROFILE FUNCTIONS =================
def profile_handler(message):
    uid = message.from_user.id
    row = cur.execute("SELECT balance, total_deposited, joined_date, discount FROM users WHERE user_id=?", (uid,)).fetchone()
    if not row:
        return bot.send_message(message.chat.id, "⚠️ Error: Please type /start to initialize your account.")
    
    bal, dep, date, discount = row
    me = bot.get_me()
    ref_link = f"https://t.me/{me.username}?start=ref_{uid}"
    disc_msg = f"\n{P_GIFT} Active Discount: <b>{discount}% OFF</b>" if discount > 0 else ""
    
    # Get USDT rate
    usdt_rate = get_usdt_rate()
    bal_usdt = round(bal / usdt_rate, 2)
    dep_usdt = round(dep / usdt_rate, 2)
    
    msg = (f"{P_ACC} <b>USER PROFILE</b>\n\n"
           f"{P_ID} User ID: <code>{uid}</code>\n"
           f"{P_MONEY} Balance:\n"
           f"   {P_INR} INR: <code>{bal}</code>\n"
           f"   {P_USDT} USDT: <code>${bal_usdt}</code>\n"
           f"{P_CARD} Deposited:\n"
           f"   {P_INR} INR: <code>{dep}</code>\n"
           f"   {P_USDT} USDT: <code>${dep_usdt}</code>\n"
           f"   💱 Rate: 1 USDT = {P_INR}{usdt_rate}{disc_msg}\n"
           f"{P_CAL} Joined: {date[:10]}\n\n"
           f"{P_USERS} <b>Your Referral Link:</b>\n<code>{ref_link}</code>\n\n"
           f"<i>(Share this link with your friends to earn bonuses!)</i>")
    bot.send_message(message.chat.id, msg)

def stats_handler(message):
    uid = message.from_user.id
    row = cur.execute("SELECT total_deposited FROM users WHERE user_id=?", (uid,)).fetchone()
    if not row:
        return
    dep = row[0]
    o_row = cur.execute("SELECT COUNT(*), SUM(price) FROM orders WHERE user_id=?", (uid,)).fetchone()
    total_orders = o_row[0] if o_row else 0
    spent = o_row[1] if o_row and o_row[1] else 0
    
    msg = (f"{P_STATS} <b>My Statistics</b>\n\n"
           f"{P_CART} <b>Accounts Bought:</b> {total_orders}\n"
           f"{P_MONEY} <b>Total Spent:</b>\n${to_usd(spent):.2f}\n"
           f"{P_CARD} <b>Total Deposited:</b>\n${to_usd(dep):.2f}")
    
    markup = InlineKeyboardMarkup(row_width=1)
    markup.add(
        InlineKeyboardButton(
            "View Purchase Logs",
            callback_data="page_purchases_1",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        ),
        InlineKeyboardButton(
            "Referral Logs",
            callback_data="view_referrals",
            style="primary",
            icon_custom_emoji_id="5364125616801073577"
        )
    )

    bot.send_message(message.chat.id, msg, reply_markup=markup)

def send_purchase_page(call, uid, page):
    limit = 5
    offset = (page - 1) * limit
    t_row = cur.execute("SELECT COUNT(*) FROM orders WHERE user_id=?", (uid,)).fetchone()
    total = t_row[0] if t_row else 0
    rows = cur.execute("SELECT phone, date FROM orders WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", (uid, limit, offset)).fetchall()
    
    msg = f"{P_DOC} <b>Purchase History (Page {page})</b>\n\n"
    if not rows:
        msg += "No purchases found."
    else:
        for ph, d in rows:
            try:
                dt = datetime.strptime(d, "%Y-%m-%d %H:%M:%S")
                d_str = dt.strftime("%a %b %d %H:%M:%S %Y")
            except:
                d_str = d
            msg += f"{P_PHONE} {ph}\n{P_CAL} {d_str}\n────────────────\n"
    
    markup = InlineKeyboardMarkup(row_width=3)
    nav_buttons = []
    if page > 1:
        nav_buttons.append(InlineKeyboardButton("Prev", callback_data=f"page_purchases_{page-1}"))
    nav_buttons.append(InlineKeyboardButton("Back", callback_data="back_to_stats"))
    if offset + limit < total:
        nav_buttons.append(InlineKeyboardButton("Next", callback_data=f"page_purchases_{page+1}"))
    markup.row(*nav_buttons)
    bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)

def view_referrals(call):
    refs = cur.execute("SELECT user_id FROM users WHERE referred_by=?", (call.from_user.id,)).fetchall()
    bot.answer_callback_query(call.id, f"👥 You have referred {len(refs)} user(s).", show_alert=True)

# ================= ADMIN ACTIONS =================
def admin_panel_handler(message_or_call):
    """Handle admin panel - works for both message and callback with colored buttons"""
    # Check if it's a callback query (has 'data' attribute) or a message
    if hasattr(message_or_call, 'data'):  # It's a callback query
        uid = message_or_call.from_user.id
        chat_id = message_or_call.message.chat.id
        message_id = message_or_call.message.message_id
        is_callback = True
    else:  # It's a message
        uid = message_or_call.from_user.id
        chat_id = message_or_call.chat.id
        message_id = None
        is_callback = False
    
    if not is_admin(uid):
        return
    
    status_text = "🟢 Bot is ON" if is_bot_online() else "🔴 Bot is OFF"
    markup = InlineKeyboardMarkup(row_width=2)
    
    # Status toggle - Primary color (Blue)
    if is_super_admin(uid) or has_perm(uid, 'p_settings'):
        markup.add(InlineKeyboardButton(
            f"Status: {status_text}",
            callback_data="adm_togglebot",
            style="primary"
        ))
    
    # Add Stock - Success color (Green)
    if is_super_admin(uid) or has_perm(uid, 'p_add_stock'):
        markup.add(
            InlineKeyboardButton(
                "➕ Add Single Acc",
                callback_data="adm_addstock",
                style="success"
            ),
            InlineKeyboardButton(
                "📦 Add ZIP",
                callback_data="adm_addzip",
                style="success"
            )
        )
    
    # Manage Stock - Primary color (Blue)
    if is_super_admin(uid) or has_perm(uid, 'p_manage_stock'):
        markup.add(
            InlineKeyboardButton(
                "📊 Manage Stock",
                callback_data="adm_managestock",
                style="primary"
            ),
            InlineKeyboardButton(
                "💰 Auto Price",
                callback_data="adm_autoprice",
                style="primary"
            )
        )
    
    # Stats - Primary color (Blue)
    if is_super_admin(uid) or has_perm(uid, 'p_stats'):
        markup.add(
            InlineKeyboardButton(
                "📈 Statistics",
                callback_data="adm_stats",
                style="primary"
            ),
            InlineKeyboardButton(
                "📢 Broadcast",
                callback_data="adm_bcast",
                style="danger"
            ),
            InlineKeyboardButton(
                "👤 User Info",
                callback_data="adm_userinfo",
                style="primary"
            )
        )
    
    # Balance & Ban - Success/Danger colors
    if is_super_admin(uid) or has_perm(uid, 'p_bal'):
        markup.add(
            InlineKeyboardButton(
                "💰 Change Balance",
                callback_data="adm_bal",
                style="success"
            ),
            InlineKeyboardButton(
                "🚫 Ban User",
                callback_data="adm_ban",
                style="danger"
            )
        )
    
    # Settings - Mixed colors
    if is_super_admin(uid) or has_perm(uid, 'p_settings'):
        markup.add(
            InlineKeyboardButton(
                "🎁 Discount",
                callback_data="adm_discount",
                style="primary"
            ),
            InlineKeyboardButton(
                "👥 Ref %",
                callback_data="adm_refpct",
                style="primary"
            )
        )
        markup.add(
            InlineKeyboardButton(
                "🔗 Support URL",
                callback_data="adm_supporturl",
                style="primary"
            ),
            InlineKeyboardButton(
                "💳 Payments",
                callback_data="adm_payments",
                style="success"
            )
        )
        markup.add(
            InlineKeyboardButton(
                "💲 Set USDT Rate",
                callback_data="adm_usdtrate",
                style="primary"
            )
        )
        markup.add(
            InlineKeyboardButton(
                "💾 Backup Users",
                callback_data="adm_backupusr",
                style="success"
            ),
            InlineKeyboardButton(
                "🔄 Restore Users",
                callback_data="adm_restoreusr",
                style="danger"
            )
        )
        markup.add(
            InlineKeyboardButton(
                "📦 Backup Sessions",
                callback_data="adm_backupsessions",
                style="success"
            )
        )
        # ============ NEW FEATURES ============
        markup.add(
            InlineKeyboardButton(
                "📝 Set Bio",
                callback_data="adm_setbio",
                style="success"
            ),
            InlineKeyboardButton(
                "📢 Mass Join",
                callback_data="adm_massjoin",
                style="danger"
            )
        )
    
    # Manage Admins - Danger color (Red) - Only for main admin
    if is_super_admin(uid):
        markup.add(
            InlineKeyboardButton(
                "👑 Manage Admins",
                callback_data="adm_manageadmins",
                style="danger"
            )
        )
    
    # Back button - Danger color (Red)
    markup.add(
        InlineKeyboardButton(
            "🔙 Back to Menu",
            callback_data="back_to_menu",
            style="danger"
        )
    )
    
    msg = f"{P_PC} <b>⚡ ADVANCED ADMIN DASHBOARD</b>\n\n<i>Click any button below to manage your bot</i>"
    
    if is_callback:
        # It's a callback query - edit the existing message
        bot.edit_message_text(msg, chat_id, message_id, reply_markup=markup)
        bot.answer_callback_query(message_or_call.id)
    else:
        # It's a message - send a new message
        bot.send_message(chat_id, msg, reply_markup=markup)
# ================= TELEPHON ADMIN OPERATIONS =================

async def telethon_add_zip_stock(chat_id, uid, zip_path):
    """Add stock from ZIP using Telethon with ONE conversation"""
    try:
        bot.send_message(chat_id, f"{P_WAIT} <b>Processing ZIP file...</b>\n"
                                f"This may take a few moments...")
        
        if not os.path.exists(zip_path):
            bot.send_message(chat_id, f"{P_NO} ZIP file not found!")
            return False
        
        extracted_dir = f"temp_extracted_{int(time.time())}"
        os.makedirs(extracted_dir, exist_ok=True)
        
        try:
            with zipfile.ZipFile(zip_path, 'r') as zip_ref:
                zip_ref.extractall(extracted_dir)
        except Exception as e:
            bot.send_message(chat_id, f"{P_NO} Failed to extract ZIP: {e}")
            shutil.rmtree(extracted_dir, ignore_errors=True)
            os.remove(zip_path)
            return False
        
        session_files = []
        for file in os.listdir(extracted_dir):
            full_path = os.path.join(extracted_dir, file)
            if os.path.isfile(full_path) and file.endswith(".session"):
                session_files.append(file)
        
        if not session_files:
            bot.send_message(chat_id, f"{P_NO} No session files found in ZIP.")
            shutil.rmtree(extracted_dir, ignore_errors=True)
            os.remove(zip_path)
            return False
        
        bot.send_message(chat_id, f"{P_YES} Found {len(session_files)} session files.\n"
                                 f"{P_WAIT} Processing each account one by one...")
        
        added_count = 0
        failed_count = 0
        skipped_count = 0
        
        for idx, file in enumerate(session_files, 1):
            try:
                file_path = os.path.join(extracted_dir, file)
                base_name = file[:-8] if file.endswith('.session') else file
                clean_path = os.path.join(extracted_dir, base_name)
                
                bot.send_message(chat_id, f"\n{'═'*50}\n"
                                         f"📱 <b>Processing {idx}/{len(session_files)}: {file}</b>\n"
                                         f"{'═'*50}")
                
                client = TelegramClient(clean_path, API_ID, API_HASH)
                await client.connect()
                
                if not await client.is_user_authorized():
                    bot.send_message(chat_id, f"{P_NO} {file} not authorized. Skipping...")
                    failed_count += 1
                    await client.disconnect()
                    continue
                
                me = await client.get_me()
                phone = getattr(me, 'phone', None)
                
                if not phone:
                    bot.send_message(chat_id, f"{P_NO} No phone found in {file}")
                    failed_count += 1
                    await client.disconnect()
                    continue
                
                existing = cur.execute("SELECT phone FROM stock WHERE phone=?", (phone,)).fetchone()
                if existing:
                    bot.send_message(chat_id, f"{P_WARN} +{phone} already exists. Skipping...")
                    skipped_count += 1
                    await client.disconnect()
                    continue
                
                c_name, c_icon = get_country_info(phone)
                bot.send_message(chat_id, f"{P_PHONE} <b>Phone:</b> +{phone}\n"
                                         f"{P_FLAG} <b>Country:</b> {c_icon} {c_name}\n"
                                         f"👤 <b>Name:</b> {me.first_name or 'No Name'}")
                
                # ========== CREATE ONE CONVERSATION ==========
                conv = AddStockConversation(bot, chat_id, uid)
                
                temp_data[uid] = {
                    'admin_action': 'adm_zip_conversation',
                    'conv': conv,
                    'current_account': phone
                }
                
                # Check for 2FA
                has_2fa = False
                try:
                    pwd = await client(GetPasswordRequest())
                    has_2fa = pwd.has_password
                except:
                    pass
                
                twofa_pass = "None"
                if has_2fa:
                    bot.send_message(chat_id, f"{P_2FA} <b>This account has 2FA enabled.</b>")
                    
                    twofa_response = await conv.get_response(
                        f"{P_2FA} <b>Enter 2FA Password for +{phone}:</b>\n"
                        f"<i>Type 'skip' if not needed or 'cancel' to skip this account</i>"
                    )
                    twofa_text = twofa_response.text.strip()
                    
                    if twofa_text.lower() == 'cancel':
                        bot.send_message(chat_id, f"{P_NO} Skipped +{phone}")
                        skipped_count += 1
                        await client.disconnect()
                        temp_data.pop(uid, None)
                        continue
                    
                    if twofa_text.lower() != 'skip':
                        try:
                            await client.sign_in(password=twofa_text)
                            twofa_pass = twofa_text
                            bot.send_message(chat_id, f"{P_YES} 2FA verified!")
                        except Exception as e:
                            bot.send_message(chat_id, f"{P_NO} 2FA failed: {e}")
                            failed_count += 1
                            await client.disconnect()
                            temp_data.pop(uid, None)
                            continue
                    else:
                        bot.send_message(chat_id, f"{P_WARN} No 2FA provided, but continuing...")
                else:
                    bot.send_message(chat_id, f"{P_YES} No 2FA required.")
                
                # Detect year
                year = await detect_account_year_fixed(client)
                
                year_response = await conv.get_response(
                    f"{P_CAL} <b>Detected Year:</b> {year}\n"
                    f"Reply <code>yes</code> to confirm or enter the correct year:"
                )
                year_text = year_response.text.strip()
                
                if year_text.lower() != 'yes':
                    try:
                        year = int(year_text)
                        bot.send_message(chat_id, f"{P_YES} Year set to: {year}")
                    except:
                        bot.send_message(chat_id, f"{P_NO} Invalid year. Using {year}")
                
                # Get price
                auto_row = cur.execute("SELECT price FROM auto_prices WHERE country=? AND year=?", (c_name, str(year))).fetchone()
                if not auto_row:
                    auto_row = cur.execute("SELECT price FROM auto_prices WHERE country=? AND year='Common'", (c_name,)).fetchone()
                
                if auto_row:
                    default_price = auto_row[0]
                    price_response = await conv.get_response(
                        f"{P_MONEY} <b>Auto-Price:</b> ₹{default_price}\n"
                        f"Reply <code>yes</code> to use auto-price or enter custom price:"
                    )
                    price_text = price_response.text.strip()
                    
                    if price_text.lower() == 'yes':
                        price = default_price
                    else:
                        try:
                            price = int(price_text)
                            bot.send_message(chat_id, f"{P_YES} Price set to: ₹{price}")
                        except:
                            price = default_price
                            bot.send_message(chat_id, f"{P_NO} Invalid price. Using ₹{price}")
                else:
                    price_response = await conv.get_response(f"{P_MONEY} <b>Enter Price (₹):</b>")
                    try:
                        price = int(price_response.text.strip())
                    except:
                        price = 100
                        bot.send_message(chat_id, f"{P_NO} Invalid price. Using ₹{price}")
                
                # Confirm
                confirm_response = await conv.get_response(
                    f"{P_YES} <b>Account Details:</b>\n"
                    f"{P_PHONE} +{phone}\n"
                    f"{P_FLAG} {c_icon} {c_name}\n"
                    f"{P_CAL} Year: {year}\n"
                    f"{P_MONEY} Price: ₹{price}\n"
                    f"{P_2FA} 2FA: {twofa_pass}\n\n"
                    f"❓ Add this account? Reply <code>yes</code> to add, <code>no</code> to skip:"
                )
                confirm_text = confirm_response.text.strip()
                
                if confirm_text.lower() != 'yes':
                    bot.send_message(chat_id, f"{P_NO} Skipped +{phone}")
                    skipped_count += 1
                    await client.disconnect()
                    temp_data.pop(uid, None)
                    continue
                
                # Move session files
                perm_base = f"sessions/{phone}"
                for ext in ['.session', '.session-wal', '.session-shm', '.session-journal']:
                    src = clean_path + ext
                    if os.path.exists(src):
                        try:
                            shutil.move(src, perm_base + ext)
                        except:
                            pass
                
                # Save to database
                cur.execute("INSERT OR REPLACE INTO stock (phone, session_file, country_name, country_icon, account_year, category, price, available, twofa) VALUES (?,?,?,?,?,?,?,?,?)",
                            (phone, perm_base + ".session", c_name, c_icon, year, "Good", price, 1, twofa_pass))
                db.commit()
                added_count += 1
                bot.send_message(chat_id, f"{P_YES} +{phone} added ({c_icon} {c_name}, {year}) ✓")
                
                temp_data.pop(uid, None)
                await client.disconnect()
                
            except Exception as e:
                logger.error(f"Error processing {file}: {e}")
                failed_count += 1
                temp_data.pop(uid, None)
                try:
                    await client.disconnect()
                except:
                    pass
                continue
        
        shutil.rmtree(extracted_dir, ignore_errors=True)
        try:
            os.remove(zip_path)
        except:
            pass
        if uid in temp_data:
            temp_data.pop(uid, None)
            
        # Clear conversation state
        deposit_input.pop(uid, None)
        session_buy_state.pop(uid, None)
        bot.send_message(
            chat_id,
            f"\n{'═'*50}\n"
            f"{P_YES} <b>📦 BULK UPLOAD COMPLETE!</b>\n"
            f"{'═'*50}\n"
            f"✅ Added: <b>{added_count}</b>\n"
            f"❌ Failed: <b>{failed_count}</b>\n"
            f"⏭️ Skipped: <b>{skipped_count}</b>\n"
            f"📁 Total Files: <b>{len(session_files)}</b>\n"
            f"{'═'*50}"
        )
        return True
        
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Error: {e}")
        logger.error(f"Add ZIP error: {traceback.format_exc()}")
        temp_data.pop(uid, None)
        return False
    
async def ask_for_input(chat_id, prompt):
    """Ask for user input with prompt"""
    # Create a conversation for getting input
    conv = AddStockConversation(bot, chat_id, None)
    response = await conv.get_response(prompt)
    return response.text
    # ================= ADD STOCK FULL FUNCTION WITH CONVERSATION =================

class AddStockConversation:
    """Handles the add stock conversation flow with faster response"""
    
    def __init__(self, bot_instance, chat_id, uid, client=None):
        self.bot = bot_instance
        self.chat_id = chat_id
        self.uid = uid
        self.client = client
        self.step = 0
        self.responses = []
        self.waiting_for_response = False
        self.response_event = asyncio.Event()
    
    async def send_message(self, text):
        """Send message to user"""
        self.bot.send_message(self.chat_id, text, parse_mode='HTML')
    
    async def get_response(self, prompt):
        self.waiting_for_response = True
        self.response_event.clear()

        print("ASK:", prompt)

        self.bot.send_message(self.chat_id, prompt, parse_mode="HTML")

        await self.response_event.wait()

        print("EVENT RECEIVED")

        if self.responses:
            print("RESPONSE:", self.responses)
            return type("obj",(object,),{"text":self.responses.pop(0)})

        print("NO RESPONSE")
        return type("obj",(object,),{"text":""})
    def set_response(self, text):
        # print("SET_RESPONSE:", text)
        self.responses.append(text)
        self.waiting_for_response = False
        self.response_event.set()
async def send_otp_request(chat_id, uid, phone, temp_data):
    """Send OTP request to Telegram immediately"""
    try:
        sp = f"sessions/{phone}"
        client = TelegramClient(sp, API_ID, API_HASH)
        await client.connect()
        
        # Send code request to Telegram
        sreq = await client.send_code_request(phone)
        
        # Store the phone code hash for later use
        temp_data[uid]['phone_code_hash'] = sreq.phone_code_hash
        temp_data[uid]['client'] = client
        
        bot.send_message(chat_id, f"{P_OTP} <b>Enter the code sent to your Telegram:</b>\n"
                                 f"<i>Check your Telegram app for the login code</i>\n\n"
                                 f"<i>Type /cancel to cancel</i>")
        
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Failed to send OTP: {e}")
        temp_data.pop(uid, None)
        try:
            await client.disconnect()
        except:
            pass
async def check_account_status(client):
    """Check if account is spam or frozen"""
    is_spam = False
    is_frozen = False
    spam_details = "None"
    category = "Good"
    
    try:
        # Check 1: Try to get dialogs
        try:
            dialogs = await client.get_dialogs(limit=5)
        except Exception as e:
            error_msg = str(e).lower()
            if "flood" in error_msg or "spam" in error_msg:
                is_spam = True
                spam_details = "Spam Detected - Flood/Send limit"
            elif "deactivated" in error_msg or "invalid" in error_msg:
                is_frozen = True
                spam_details = "Account Deactivated/Frozen"
        
        # Check 2: Try to get entity
        try:
            test_entity = await client.get_entity('username')
        except Exception as e:
            error_msg = str(e).lower()
            if "flood" in error_msg or "spam" in error_msg:
                is_spam = True
                spam_details = "Spam Detected - Cannot send messages"
            elif "deactivated" in error_msg or "invalid" in error_msg:
                is_frozen = True
                spam_details = "Account Deactivated"
        
        # Check 3: Check if account is restricted
        try:
            me_full = await client.get_me()
            if hasattr(me_full, 'restricted') and me_full.restricted:
                is_spam = True
                spam_details = "Restricted Account"
            if hasattr(me_full, 'limited') and me_full.limited:
                is_spam = True
                spam_details = "Limited Account"
        except:
            pass
        
        # Check 4: Try to send test message
        try:
            await client.send_message('me', 'Test message to check account status')
        except Exception as e:
            error_msg = str(e).lower()
            if "spam" in error_msg:
                is_spam = True
                spam_details = "Account restricted from sending messages"
            elif "deactivated" in error_msg or "invalid" in error_msg:
                is_frozen = True
                spam_details = "Account Deactivated"
            elif "flood" in error_msg:
                is_spam = True
                spam_details = "Flood Wait - Account limited"
        
        # Determine category
        if is_spam:
            category = "SPAM"
        elif is_frozen:
            category = "FROZEN"
        else:
            category = "Good"
            
    except Exception as e:
        print(f"Status check error: {e}")
    
    return is_spam, is_frozen, spam_details, category


async def telethon_add_stock_full(chat_id, uid, phone, sess_path, conv):
    """Complete add stock with simplified flow - NO confirmation, NO 2FA re-ask"""
    client = None
    try:
        client = TelegramClient(sess_path, API_ID, API_HASH)
        await client.connect()
        
        # ============================================================
        # SECTION 1: GET ACCOUNT INFO (NO CONFIRMATION)
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                               f"📱 <b>PROCESSING ACCOUNT</b>\n"
                               f"{'═'*50}")
        
        # Get account info directly - NO confirmation asked
        try:
            me = await client.get_me()
            phone = getattr(me, 'phone', None)
            if not phone:
                await conv.send_message(f"{P_NO} Failed to get phone number from account.")
                await client.disconnect()
                return False
        except Exception as e:
            await conv.send_message(f"{P_NO} Failed to get account info: {e}")
            await client.disconnect()
            return False
        
        await conv.send_message(f"{P_PHONE} <b>Phone:</b> <code>+{phone}</code>\n"
                               f"👤 <b>Name:</b> {me.first_name or 'No Name'}")
        
        # ============================================================
        # SECTION 2: ACCOUNT YEAR DETECTION
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                               f"📅 <b>ACCOUNT YEAR DETECTION</b>\n"
                               f"{'═'*50}")
        
        await conv.send_message(f"{P_WAIT} Checking account age...")
        
        year = await detect_account_year_fixed(client)
        
        if year == datetime.now().year:
            detected_year, age_years, creation_date = await get_account_age(client)
            if detected_year and detected_year < datetime.now().year:
                year = detected_year
                await conv.send_message(f"{P_CAL} <b>Account Age:</b> {age_years} years")
                if creation_date:
                    await conv.send_message(f"{P_CAL} <b>Created:</b> {creation_date.strftime('%Y-%m-%d')}")
        
        await conv.send_message(f"{P_CAL} <b>Detected Year:</b> <code>{year}</code>")
        
        # Ask for year confirmation (keep this as it's important)
        year_confirm = await conv.get_response(f"❓ Is this correct? Reply <code>yes</code> to confirm or enter the correct year:")
        year_confirm_text = year_confirm.text.strip().lower()
        
        if year_confirm_text != "yes":
            try:
                year = int(year_confirm_text)
                await conv.send_message(f"{P_YES} Year set to: <b>{year}</b>")
            except ValueError:
                year_resp = await conv.get_response(f"{P_CAL} <b>Enter Account Year:</b>\n<i>Example: 2024</i>")
                year = int(year_resp.text)
                await conv.send_message(f"{P_YES} Year set to: <b>{year}</b>")
        
        # ============================================================
        # SECTION 3: ACCOUNT STATUS CHECK (SPAM/FROZEN)
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                               f"🔍 <b>ACCOUNT STATUS CHECK</b>\n"
                               f"{'═'*50}")
        
        await conv.send_message(f"{P_WAIT} Checking account status...")
        
        is_spam, is_frozen, spam_details, category = await check_account_status(client)
        
        # Show status
        status_emoji = "🔓" if not is_spam and not is_frozen else ("⚠️" if is_spam else "🔒")
        status_text = "Good" if not is_spam and not is_frozen else ("SPAM" if is_spam else "FROZEN")
        
        await conv.send_message(f"{status_emoji} <b>Status:</b> {status_text}")
        
        if is_spam:
            await conv.send_message(f"⚠️ <b>SPAM ACCOUNT DETECTED</b>\n📋 Details: {spam_details}")
        elif is_frozen:
            await conv.send_message(f"🔒 <b>FROZEN ACCOUNT DETECTED</b>\n📋 Details: {spam_details}")
        else:
            await conv.send_message(f"✅ <b>Healthy Account</b>")
        
        # Ask if still want to add spam/frozen accounts
        if is_spam or is_frozen:
            await conv.send_message(f"\n{'═'*50}\n"
                                   f"⚠️ <b>SPAM/FROZEN HANDLING</b>\n"
                                   f"{'═'*50}")
            
            skip_resp = await conv.get_response(f"❓ Account is {'SPAM' if is_spam else 'FROZEN'}. Still add? Reply <code>yes</code> or <code>no</code>:")
            skip = skip_resp.text.strip().lower()
            if skip != "yes":
                await conv.send_message(f"{P_NO} Skipped this account.")
                await client.disconnect()
                return False
            
            category_resp = await conv.get_response(f"{P_DOC} <b>Enter Category:</b>\n<i>Type 'default' to use {'SPAM' if is_spam else 'FROZEN'}</i>")
            category = category_resp.text.strip()
            if category.lower() == 'default':
                category = 'SPAM' if is_spam else 'FROZEN'
            await conv.send_message(f"{P_YES} Category: <b>{category}</b>")
        
        # ============================================================
        # SECTION 4: COUNTRY DETECTION (SIMPLIFIED)
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                               f"🌍 <b>COUNTRY DETECTION</b>\n"
                               f"{'═'*50}")
        
        detected_country, detected_icon = get_country_info(phone)
        
        if detected_country == "Unknown":
            await conv.send_message(f"{P_WARN} <b>Country not detected!</b>")
            c_icon_resp = await conv.get_response(f"{P_FLAG} <b>Enter Flag Emoji:</b>\n<i>Example: 🇮🇳</i>")
            c_icon = html.escape(c_icon_resp.text)
            c_name_resp = await conv.get_response(f"{P_GLOBE} <b>Enter Country Name:</b>\n<i>Example: India</i>")
            c_name = html.escape(c_name_resp.text)
        else:
            await conv.send_message(f"{P_GLOBE} <b>Country:</b> {detected_icon} {detected_country}")
            confirm_resp = await conv.get_response(f"❓ Correct? Reply <code>yes</code> or enter country name:")
            confirm = confirm_resp.text.strip().lower()
            
            if confirm == "yes":
                c_name = detected_country
                c_icon = detected_icon
            else:
                c_icon_resp = await conv.get_response(f"{P_FLAG} <b>Enter Flag Emoji:</b>")
                c_icon = html.escape(c_icon_resp.text)
                c_name = html.escape(confirm)
                await conv.send_message(f"{P_YES} Country: {c_icon} {c_name}")
        
        # ============================================================
        # SECTION 5: PRICE SETUP (INR with USDT Auto-Conversion)
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                            f"💰 <b>PRICE SETUP</b>\n"
                            f"{'═'*50}")

        # Get current USDT rate
        usdt_rate = get_usdt_rate()

        # Check auto-price
        # Get price with INR and USDT display
        usdt_rate = get_usdt_rate()
        auto_row = cur.execute("SELECT price FROM auto_prices WHERE country=? AND year=?", (c_name, str(year))).fetchone()
        if not auto_row:
            auto_row = cur.execute("SELECT price FROM auto_prices WHERE country=? AND year='Common'", (c_name,)).fetchone()

        if auto_row:
            default_price = auto_row[0]
            default_usdt = round(default_price / usdt_rate, 2)
            bot.send_message(chat_id, f"⚡ <b>Auto-Price:</b>\n"
                                    f"   {P_INR} INR: ₹{default_price}\n"
                                    f"   {P_USDT} USDT: ${default_usdt}\n"
                                    f"   💱 Rate: 1 USDT = {P_INR}{usdt_rate}")
            
            price_response = await conv.get_response(
                f"{P_MONEY} Reply <code>yes</code> to use auto-price or enter custom INR price:"
            )
            price_text = price_response.text.strip()
            
            if price_text.lower() == 'yes':
                price = default_price
                price_usdt = default_usdt
                bot.send_message(chat_id, f"{P_YES} Price set:\n"
                                        f"   {P_INR} INR: ₹{price}\n"
                                        f"   {P_USDT} USDT: ${price_usdt}")
            else:
                try:
                    price = int(price_text)
                    price_usdt = round(price / usdt_rate, 2)
                    bot.send_message(chat_id, f"{P_YES} Price set:\n"
                                            f"   {P_INR} INR: ₹{price}\n"
                                            f"   {P_USDT} USDT: ${price_usdt}")
                except:
                    price = default_price
                    price_usdt = default_usdt
                    bot.send_message(chat_id, f"{P_NO} Invalid price. Using auto-price:\n"
                                            f"   {P_INR} INR: ₹{price}\n"
                                            f"   {P_USDT} USDT: ${price_usdt}")
        else:
            bot.send_message(chat_id, f"💱 <b>Current USDT Rate:</b> 1 USDT = {P_INR}{usdt_rate}")
            price_response = await conv.get_response(f"{P_MONEY} <b>Enter Price (INR ₹):</b>")
            try:
                price = int(price_response.text.strip())
                price_usdt = round(price / usdt_rate, 2)
                bot.send_message(chat_id, f"{P_YES} Price set:\n"
                                        f"   {P_INR} INR: ₹{price}\n"
                                        f"   {P_USDT} USDT: ${price_usdt}")
            except:
                price = 100
                price_usdt = round(price / usdt_rate, 2)
                bot.send_message(chat_id, f"{P_NO} Invalid price. Using default:\n"
                                        f"   {P_INR} INR: ₹{price}\n"
                                        f"   {P_USDT} USDT: ${price_usdt}")
        # ============================================================
        # SECTION 6: USE 2FA FROM LOGIN (NO RE-ASK)
        # ============================================================
        # Get 2FA from temp_data (entered during login)
        twofa_pass = "None"
        if uid in temp_data and 'twofa' in temp_data[uid]:
            twofa_pass = temp_data[uid].get('twofa', 'None')
            if twofa_pass and twofa_pass != 'None':
                await conv.send_message(f"{P_2FA} <b>2FA:</b> Using password from login ✅")
            else:
                await conv.send_message(f"{P_2FA} <b>2FA:</b> None (No password)")
        else:
            # Try to check if account has 2FA and use it
            try:
                pwd = await client(GetPasswordRequest())
                if pwd.has_password:
                    # If 2FA exists but we don't have it, ask once
                    twofa_resp = await conv.get_response(f"{P_2FA} <b>2FA required.</b> Enter 2FA password (or 'skip'):")
                    twofa_text = twofa_resp.text.strip()
                    if twofa_text.lower() != 'skip':
                        twofa_pass = html.escape(twofa_text)
                        await conv.send_message(f"{P_YES} 2FA saved.")
                    else:
                        await conv.send_message(f"{P_WARN} No 2FA provided.")
                else:
                    await conv.send_message(f"{P_YES} No 2FA required.")
            except:
                await conv.send_message(f"{P_WARN} Could not check 2FA status.")
        
        # ============================================================
        # SECTION 7: SAVE TO DATABASE (AUTO-ADD - NO FINAL CONFIRMATION)
        # ============================================================
        await conv.send_message(f"\n{'═'*50}\n"
                               f"💾 <b>SAVING TO DATABASE...</b>\n"
                               f"{'═'*50}")
        
        final_category = category
        if is_spam and "SPAM" not in category.upper():
            final_category = f"SPAM-{category}"
        elif is_frozen and "FROZEN" not in category.upper():
            final_category = f"FROZEN-{category}"
        
        # Move session files to permanent location
        for ext in ['.session', '.session-wal', '.session-shm', '.session-journal']:
            src = sess_path + ext
            perm_path = f"sessions/{phone}{ext}"
            if os.path.exists(src):
                try:
                    shutil.move(src, perm_path)
                except:
                    pass
        
        # Save to database
        cur.execute("INSERT OR REPLACE INTO stock (phone, session_file, country_name, country_icon, account_year, category, price, available, twofa) VALUES (?,?,?,?,?,?,?,?,?)", 
                    (phone, f"sessions/{phone}.session", c_name, c_icon, year, final_category, price, 1, twofa_pass))
        db.commit()
        
        await client.disconnect()
        
        # ============================================================
        # SECTION 8: SUCCESS MESSAGE (AUTO-ADDED)
        # ============================================================
        status_emoji = "🔓" if not is_spam and not is_frozen else ("⚠️" if is_spam else "🔒")
        await conv.send_message(f"\n{'═'*50}\n"
                               f"{P_YES} <b>ACCOUNT ADDED!</b>\n"
                               f"{'═'*50}\n"
                               f"{P_PHONE} +{phone}\n"
                               f"{P_FLAG} {c_icon} {c_name}\n"
                               f"{P_CAL} Year: {year}\n"
                               f"{P_MONEY} Price: {P_MONEY}{price}\n"
                               f"{P_DOC} Category: {final_category}\n"
                               f"{P_2FA} 2FA: {twofa_pass}\n"
                               f"{status_emoji} Status: {'⚠️ SPAM/FROZEN' if (is_spam or is_frozen) else '✅ HEALTHY'}\n"
                               f"{'═'*50}")
        
        # Clear temp_data
        if uid in temp_data:
            temp_data.pop(uid, None)
        deposit_input.pop(uid, None)
        session_buy_state.pop(uid, None)
        
        return True
        
    except Exception as e:
        # Handle errors
        try:
            await conv.send_message(f"{P_NO} Error: {e}")
        except:
            pass
            
        if uid in temp_data:
            temp_data.pop(uid, None)
        deposit_input.pop(uid, None)
        session_buy_state.pop(uid, None)
        
        import traceback
        traceback.print_exc()
        if client:
            try:
                await client.disconnect()
            except:
                pass
        return False
    
# ================= ADD STOCK LOGIN PROCESS =================
async def process_send_code(chat_id, uid, phone):
    try:
        sp = f"sessions/{phone}"
        client = TelegramClient(sp, API_ID, API_HASH)
        await client.connect()

        sreq = await client.send_code_request(phone)

        temp_data[uid] = {
            "admin_action": "adm_addstock_otp",
            "phone": phone,
            "phone_code_hash": sreq.phone_code_hash,
            "client": client
        }

        bot.send_message(
            chat_id,
            f"{P_OTP} <b>OTP sent successfully.</b>\n\nEnter the OTP:"
        )

    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Failed to send OTP:\n{e}")
async def process_add_stock_login(chat_id, uid, phone, otp, temp_data):
    """Process the login with OTP"""
    try:
        sp = f"sessions/{phone}"
        client = temp_data[uid].get('client')
        if not client:
            client = TelegramClient(sp, API_ID, API_HASH)
            await client.connect()
        
        # Get phone_code_hash from temp_data
        phone_code_hash = temp_data[uid].get('phone_code_hash')
        if not phone_code_hash:
            bot.send_message(chat_id, f"{P_NO} Session expired. Please try again.")
            temp_data.pop(uid, None)
            return
        
        # Try to sign in with OTP
        try:
            await client.sign_in(phone, otp, phone_code_hash=phone_code_hash)
            
            # ✅ Store 2FA as None (no 2FA required)
            temp_data[uid]['twofa'] = 'None'
            
            # If successful, proceed with add stock
            await proceed_add_stock_after_login(chat_id, uid, phone, sp, client, temp_data)
            
        except SessionPasswordNeededError:
            # 2FA required
            temp_data[uid]['needs_2fa'] = True
            bot.send_message(chat_id, f"{P_2FA} <b>2FA Password required.</b>\n"
                                     f"Enter your 2FA password:\n\n"
                                     f"<i>Type /cancel to cancel</i>")
            temp_data[uid]['admin_action'] = 'adm_addstock_2fa'
            return
            
        except Exception as e:
            error_msg = str(e)
            if "phone code invalid" in error_msg.lower():
                bot.send_message(chat_id, f"{P_NO} Invalid OTP. Please try again.\n"
                                         f"{P_OTP} <b>Enter the code sent to your Telegram:</b>")
                temp_data[uid]['admin_action'] = 'adm_addstock_otp'
            else:
                bot.send_message(chat_id, f"{P_NO} Login failed: {e}")
                temp_data.pop(uid, None)
            
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Error: {e}")
        temp_data.pop(uid, None)

async def process_add_stock_2fa(chat_id, uid, phone, twofa_pass, temp_data):
    """Process 2FA login"""
    try:
        sp = f"sessions/{phone}"
        client = TelegramClient(sp, API_ID, API_HASH)
        await client.connect()
        
        try:
            await client.sign_in(password=twofa_pass)
            
            # ✅ Store the 2FA password in temp_data
            temp_data[uid]['twofa'] = twofa_pass
            
            # If successful, proceed with add stock
            await proceed_add_stock_after_login(chat_id, uid, phone, sp, client, temp_data)
            
        except Exception as e:
            bot.send_message(chat_id, f"{P_NO} 2FA authentication failed: {e}")
            temp_data.pop(uid, None)
            await client.disconnect()
            
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Error: {e}")
        temp_data.pop(uid, None)



async def proceed_add_stock_after_login(chat_id, uid, phone, sp, client, temp_data):
    
    """Proceed with add stock after successful login"""
    try:
        # Get account info
        me = await client.get_me()
        phone = getattr(me, 'phone', None)
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Failed to get phone number from account.")
            await client.disconnect()
            temp_data.pop(uid, None)
            return
        
        # Save session file
        await client.disconnect()
        
        # Now start the full add stock conversation
        sess_path = sp
        bot.send_message(chat_id, f"{P_YES} <b>Login Successful!</b>\n"
                                 f"{P_WAIT} Starting account setup...\n"
                                 f"{'═'*50}")
        
        # Create conversation handler
        conv = AddStockConversation(bot, chat_id, uid)
        temp_data[uid] = {
            "admin_action": "adm_addstock_conversation",
            "conv": conv
        }
        
        # STORE CONVERSATION FOR RESPONSE HANDLING
        
        
        # Start the add stock process with full flow
        asyncio.run_coroutine_threadsafe(
            telethon_add_stock_full(chat_id, uid, phone, sess_path, conv),
            telethon_loop
        )
        
        # DON'T REMOVE temp_data HERE - Keep it for the conversation
        
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Error: {e}")
        temp_data.pop(uid, None)
        try:
            await client.disconnect()
        except:
            pass
async def telethon_backup_sessions(chat_id):
    """Backup all sessions using Telethon"""
    try:
        sessions = cur.execute("SELECT phone, session_file FROM stock WHERE available=1").fetchall()
        
        if not sessions:
            bot.send_message(chat_id, f"{P_NO} No sessions found.")
            return
        
        backup_name = f"sessions_backup_{datetime.now().strftime('%Y%m%d_%H%M%S')}.zip"
        success_count = 0
        
        with zipfile.ZipFile(backup_name, 'w', zipfile.ZIP_DEFLATED) as zf:
            for phone, session_file in sessions:
                try:
                    base_path = session_file if not session_file.endswith('.session') else session_file[:-8]
                    
                    for ext in ['.session', '.session-wal', '.session-shm', '.session-journal']:
                        file_path = base_path + ext
                        if os.path.exists(file_path):
                            arcname = f"{phone}{ext}"
                            zf.write(file_path, arcname)
                            success_count += 1
                except:
                    continue
        
        # Create info file
        info_content = f"""SESSIONS BACKUP INFORMATION
Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
Total Sessions: {len(sessions)}
Successfully Backed Up: {success_count}

Phone numbers included:
"""
        for phone, _ in sessions:
            info_content += f"+{phone}\n"
        
        with zipfile.ZipFile(backup_name, 'a') as zf:
            zf.writestr("backup_info.txt", info_content)
        
        with open(backup_name, 'rb') as f:
            bot.send_document(chat_id, f, caption=f"{P_YES} <b>Sessions Backup Complete!</b>")
        os.remove(backup_name)
        
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Backup failed: {e}")

# ================= BOT COMMAND HANDLERS =================
@bot.message_handler(commands=['start'])
def handle_start(message):
    try:
        uid = message.from_user.id
        if not uid:
            return
        
        ensure_user(uid)
        if is_user_banned(uid):
            return

        if not is_bot_online() and not is_admin(uid):
            return bot.send_message(message.chat.id, f"{P_OFF} <b>Bot is currently under maintenance.</b> Please try again later.")
        
        session_buy_state.pop(uid, None)
        deposit_input.pop(uid, None)

        text = message.text or ''
        if len(text.split()) > 1:
            start_param = text.split()[1]
            if start_param.startswith("ref_"):
                ref = start_param.replace("ref_", "")
                if ref.isdigit() and int(ref) != uid:
                    cur.execute("UPDATE users SET referred_by=? WHERE user_id=? AND referred_by IS NULL", (int(ref), uid))
                    db.commit()

        # Check terms first
        row = cur.execute("SELECT terms_accepted FROM users WHERE user_id=?", (uid,)).fetchone()
        terms_acc = row[0] if row else 0
        if not terms_acc:
            markup = InlineKeyboardMarkup(row_width=1)
            markup.add(
                types.InlineKeyboardButton("📜 Read Terms & Conditions", web_app=types.WebAppInfo(TERMS_URL)),
                InlineKeyboardButton("✅ Accept", callback_data="tc_accept"),
                InlineKeyboardButton("❌ Reject", callback_data="tc_reject")
            )
            return bot.send_message(message.chat.id, f"{P_DOC} <b>TERMS & CONDITIONS</b>\nPlease read and accept our Terms & Conditions before using the bot.", reply_markup=markup)

        # Skip channel check for admins
        if is_admin(uid):
            send_main_menu(message.chat.id, uid)
            return

        # Store chat_id for callback
        temp_data[uid] = {'start_chat_id': message.chat.id}
        
        # Run async check for channels
        asyncio.run_coroutine_threadsafe(
            check_and_force_join(uid, message.chat.id),
            telethon_loop
        )
        
    except Exception as e:
        traceback.print_exc()
        logger.error(f"Start error: {e}")
@bot.message_handler(commands=['cancel'])
def handle_cancel(message):
    uid = message.from_user.id
    deposit_input.pop(uid, None)
    waiting_proof.pop(uid, None)
    session_buy_state.pop(uid, None)
    temp_data.pop(uid, None)
    bot.send_message(message.chat.id, f"{P_NO} <b>Cancelled.</b>")

@bot.message_handler(func=lambda message: True, content_types=['text', 'photo', 'document', 'audio', 'video', 'voice', 'sticker'])
def handle_all_messages(message):
    try:
        uid = message.from_user.id
        text = message.text or ""
        
        
        if not uid:
            return
        if message.text and message.text.startswith('/'):
            return
        if not is_bot_online() and not is_admin(uid):
            return bot.send_message(message.chat.id, f"{P_OFF} <b>Bot is currently under maintenance.</b> Please try again later.")
        
        ensure_user(uid)
        if is_user_banned(uid):
            return

        # ============ HANDLE ADMIN PAYMENT FLOW - PHOTO ============
        # Check if admin is in QR state for payment method
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') == 'adm_addpay_qr':
            
            
            # Check if it's a photo or document
            if message.photo or message.document:
                
                handle_admin_conversation_sync(message, uid, 'adm_addpay_qr')
                return
            elif text and text.lower() == 'skip':
                handle_admin_conversation_sync(message, uid, 'adm_addpay_qr')
                return
            else:
                bot.send_message(message.chat.id, f"{P_NO} Please send an image, document, or type 'skip'.")
                return

        # ============ HANDLE ADMIN PAYMENT FLOW - NAME ============
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') == 'adm_addpay':
            print(f"📸 DEBUG: Admin {uid} in adm_addpay state")
            handle_admin_conversation_sync(message, uid, 'adm_addpay')
            return

        # ============ HANDLE ADMIN PAYMENT FLOW - CAPTION ============
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') == 'adm_addpay_cap':
            print(f"📸 DEBUG: Admin {uid} in adm_addpay_cap state")
            handle_admin_conversation_sync(message, uid, 'adm_addpay_cap')
            return

        # ============ REST OF YOUR EXISTING CODE ============
        # ... continue with the rest of your handle_all_messages code ...

        # Handle admin conversation inputs
        # ==================== SET BIO CONVERSATION ====================
        # ... rest of your code ...
        # ==================== SET BIO CONVERSATION ====================
        # Check for Set Bio conversation FIRST
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') in ['adm_setbio_phone', 'adm_setbio_text']:
            process_admin_setbio(message)
            return

        # ==================== MASS JOIN CONVERSATION ====================
        # Check for Mass Join conversation SECOND
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') in ['adm_massjoin_channel', 'adm_massjoin_phone']:
            process_admin_massjoin(message)
            return
        # ==================== HANDLE ZIP CONVERSATION ====================
# Handle ZIP conversation responses (same conversation object)
        if uid in temp_data and temp_data.get(uid, {}).get('admin_action') == 'adm_zip_conversation':
            conv = temp_data.get(uid, {}).get('conv')
            if conv and conv.waiting_for_response:
                conv.set_response(message.text)
                return
        # Handle admin conversation inputs (OTP, Add Stock, etc.)
        if uid in temp_data and 'admin_action' in temp_data[uid]:
            admin_action = temp_data[uid]['admin_action']
            
            if message.text and (message.text == "/cancel" or message.text.lower() == "cancel"):
                    # Clear ALL states
                deposit_input.pop(uid, None)
                waiting_proof.pop(uid, None)
                session_buy_state.pop(uid, None)
                temp_data.pop(uid, None)
                admin_dep_state.pop(uid, None)
                custom_dep_amt.pop(uid, None)
                session_buy_state.pop(uid, None)
                
                bot.send_message(message.chat.id, f"{P_NO} <b>Cancelled.</b>")
                return
            
            # Check if there's a conversation waiting
            conv = temp_data.get(uid, {}).get('conv')
            if conv:
                conv.set_response(message.text)
                return
            
            # For OTP related actions, call the async function via run_coroutine_threadsafe
            if admin_action in ["adm_addstock_phone", "adm_addstock_otp", "adm_addstock_2fa", "adm_addstock"]:
                asyncio.run_coroutine_threadsafe(
                    handle_admin_conversation_async(message, uid, admin_action),
                    telethon_loop
                )
                return
            
            # For other admin actions, call normally
            handle_admin_conversation_sync(message, uid, admin_action)
            return

        # Handle deposit proof
        # Handle deposit proof
        # Handle deposit proof
        # ==================== HANDLE DEPOSIT PROOF ====================
        # ==================== HANDLE DEPOSIT PROOF ====================
        if uid in waiting_proof and (
            message.photo
            or message.document
            or (message.text and "http" in message.text)
        ):
            info = waiting_proof.pop(uid)
            final_amt = info['amount']
            
            # Check if it's a USDT deposit (Cwallet or Binance)
            usdt_display = ""
            if info['method'] in ("Cwallet", "Binance") and 'usdt_amount' in info:
                usdt_display = f"\n{P_USDT} USDT Amount: <b>${info['usdt_amount']}</b>"
            
            # Save deposit
            cur.execute(
                "INSERT INTO deposits (user_id, amount, method_name, status) VALUES (?,?,?,?)",
                (uid, final_amt, info['method'], "pending")
            )
            db.commit()
            dep_id = cur.lastrowid

            bot.reply_to(
                message,
                f"{P_YES} <b>Deposit Request Submitted!</b>\n"
                f"Please wait for admin approval."
            )

            cap = (
                f"🔔 <b>NEW DEPOSIT REQUEST</b>\n\n"
                f"{P_ACC} User: <code>{uid}</code>\n"
                f"{P_MONEY} INR Amount: <b>{P_INR}{info['amount']}</b>\n"
                f"{usdt_display}"
                f"{P_CARD} Method: {info['method']}\n"
                f"{P_ID} Ref ID: <code>{dep_id}</code>"
            )

            markup = InlineKeyboardMarkup(row_width=2)
            markup.add(
                InlineKeyboardButton(
                    f"✅ Accept (${info['usdt_amount'] if 'usdt_amount' in info else round(info['amount']/get_usdt_rate(), 2)} USDT)",
                    callback_data=f"dep_acc|{dep_id}|{uid}|{info['method']}|exact|{final_amt}"
                ),
                InlineKeyboardButton(
                    "❌ Reject",
                    callback_data=f"dep_rej|{dep_id}|{uid}"
                )
            )
            markup.add(
                InlineKeyboardButton(
                    "📝 Custom Amount",
                    callback_data=f"dep_acc|{dep_id}|{uid}|{info['method']}|custom|0"
                )
            )

            try:
                if message.photo:
                    bot.send_photo(
                        chat_id=LOG_CHANNEL_ID,
                        photo=message.photo[-1].file_id,
                        caption=cap,
                        reply_markup=markup,
                        parse_mode="HTML"
                    )
                elif message.document:
                    bot.send_document(
                        chat_id=LOG_CHANNEL_ID,
                        document=message.document.file_id,
                        caption=cap,
                        reply_markup=markup,
                        parse_mode="HTML"
                    )
                else:
                    bot.send_message(
                        chat_id=LOG_CHANNEL_ID,
                        text=cap + f"\n\n🔗 Hash:\n<code>{html.escape(message.text)}</code>",
                        reply_markup=markup,
                        parse_mode="HTML"
                    )
            except Exception as e:
                try:
                    bot.send_message(
                        LOG_CHANNEL_ID,
                        cap,
                        reply_markup=markup,
                        parse_mode="HTML"
                    )
                except:
                    pass

            return

        # Clear states when menu buttons are pressed
        if "Buy Account" in text or "Buy Sessions" in text or "Deposit" in text or "My Profile" in text or "My Stats" in text or "Support" in text or "Admin Panel" in text:
            session_buy_state.pop(uid, None)
            deposit_input.pop(uid, None)
            admin_dep_state.pop(uid, None)

        # Handle admin deposit rejection reason
        # Handle admin deposit rejection reason
        if is_admin(uid) and uid in admin_dep_state:
            st = admin_dep_state[uid]
            if st['step'] == 'wait_reason':
                t_uid, dep_id = st['target_uid'], st['dep_id']
                cur.execute("UPDATE deposits SET status='rejected' WHERE id=?", (dep_id,))
                db.commit()
                
                # Edit the original deposit request message to show REJECTED status
                try:
                    msg_id = st.get('msg_id')
                    if msg_id:
                        new_text = (f"{P_NO} <b>DEPOSIT REJECTED ❌</b>\n\n"
                                    f"{P_ACC} User: <code>{t_uid}</code>\n"
                                    f"{P_DOC} Reason: {html.escape(text)}\n"
                                    f"{P_TIME} Rejected: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
                                    f"<i>❌ Deposit has been rejected.</i>")
                        
                        bot.edit_message_text(
                            new_text,
                            LOG_CHANNEL_ID,
                            msg_id,
                            parse_mode='HTML'
                        )
                        print(f"✅ Updated deposit {dep_id} message to REJECTED status")
                except Exception as e:
                    print(f"⚠️ Could not edit message: {e}")
                    # Fallback - send a new message
                    try:
                        bot.send_message(LOG_CHANNEL_ID, f"{P_NO} <b>REJECTED USER {t_uid}</b>\nReason: {html.escape(text)}")
                    except:
                        pass
                
                bot.send_message(int(t_uid), f"{P_NO} <b>Deposit Rejected!</b>\n📋 Reason: {html.escape(text)}")
                bot.reply_to(message, f"{P_YES} Rejection reason sent.")
                admin_dep_state.pop(uid)
                return

        # Handle session bulk purchase quantity
        if uid in session_buy_state:
            state = session_buy_state[uid]
            try:
                qty = int(re.sub(r'[^\d]', '', text))
                if qty < 1:
                    raise ValueError
                if qty > state['stock']:
                    return bot.reply_to(message, f"{P_WARN} <b>Not enough stock!</b> Max is {state['stock']}.")
                
                disc_row = cur.execute("SELECT discount FROM users WHERE user_id=?", (uid,)).fetchone()
                discount = disc_row[0] if disc_row else 0
                total_cost = qty * state['price']
                if discount > 0:
                    total_cost = int(total_cost * (100 - discount) / 100)
                    
                bal_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (uid,)).fetchone()
                user_bal = bal_row[0] if bal_row else 0
                if user_bal < total_cost:
                    return bot.reply_to(message, f"{P_NO} <b>Insufficient Balance!</b>\nYou need {P_INR}{total_cost} to buy {qty} sessions.")

                session_buy_state.pop(uid)
                process_bulk_sessions(message, uid, qty, state, total_cost)
                return
            except ValueError:
                return bot.reply_to(message, f"{P_NO} Please enter a valid number.")

        # Handle deposit amount input
        # Handle deposit amount input
        # Handle deposit amount input
        # Handle deposit amount input
        # Handle deposit amount input
        if uid in deposit_input and deposit_input[uid]['step'] == 'wait_amt':
            try:
                method = deposit_input[uid]['method']
                rate = get_usdt_rate()
                
                # Check if method is USDT-based (Cwallet, Binance, Tron, Polygon)
                if method in ("Cwallet", "Binance", "Tron", "Polygon"):
                    try:
                        usdt_amt = float(text)
                        if usdt_amt <= 0:
                            return bot.reply_to(message, f"{P_WARN} Amount must be greater than 0.")
                        
                        # Calculate INR equivalent
                        inr_amt = int(usdt_amt * rate)
                        
                        waiting_proof[uid] = {'amount': inr_amt, 'method': method, 'usdt_amount': usdt_amt}
                        deposit_input.pop(uid)
                        
                        # Get QR and Address based on method
                        if method == "Cwallet":
                            qr_url = CWALLET_QR
                            address = CWALLET_ID
                            method_name = "Cwallet (USDT)"
                        elif method == "Binance":
                            qr_url = BINANCE_QR
                            address = BINANCE_ID
                            method_name = "Binance (USDT)"
                        elif method == "Tron":
                            qr_url = TRON_QR
                            address = TRON_ID
                            method_name = "TRON (TRC20)"
                        elif method == "Polygon":
                            qr_url = POLYGON_QR
                            address = POLYGON_ID
                            method_name = "POLYGON (POS)"
                        
                        # Show USDT confirmation
                        msg = (f"{P_CARD} <b>Method:</b> {method_name}\n\n"
                            f"🚀 <b>Address / ID:</b>\n<code>{address}</code>\n\n"
                            f"{P_USDT} <b>USDT Deposit Request</b>\n\n"
                            f"💰 <b>Amount:</b> <code>${usdt_amt} USDT</code>\n"
                            f"   💱 <b>INR Equivalent:</b> <code>{P_INR}{inr_amt}</code>\n"
                            f"   💱 <b>Rate:</b> 1 USDT = {P_INR}{rate}\n\n"
                            f"👉 <b>Send Proof:</b>\nPlease send the Transaction Hash (Link) or a Screenshot of the payment now.")
                        
                        markup = InlineKeyboardMarkup()
                        markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
                        
                        try:
                            bot.send_photo(uid, qr_url, caption=msg, reply_markup=markup)
                        except Exception:
                            bot.send_message(uid, msg + f"\n\n🔗 QR Link: {qr_url}", reply_markup=markup)
                            
                    except ValueError:
                        bot.reply_to(message, f"{P_NO} Please enter a valid USDT amount.\n\n"
                                            f"<b>Example:</b> <code>10</code> for $10 USDT")
                        return
                else:
                    # Handle INR deposits (UPI, custom methods)
                    amt = int(re.sub(r'[^\d]', '', text))
                    if amt < 10:
                        return bot.reply_to(message, f"{P_WARN} Minimum Deposit is ₹10.")
                    
                    waiting_proof[uid] = {'amount': amt, 'method': method}
                    deposit_input.pop(uid)
                    
                    usdt_amt = round(amt / rate, 2)
                    rate_text = (f"\n\n{P_MONEY} <b>Amount to Pay:</b>\n"
                                f"   <code>{P_INR}{amt}</code>  |  <code>${usdt_amt} USDT</code>\n"
                                f"   💱 <b>Rate:</b> 1 USDT = {P_INR}{rate}")
                    
                    row = cur.execute("SELECT caption, qr_file_id FROM custom_payments WHERE name=?", (method,)).fetchone()
                    if row:
                        cap = row[0] + f"{rate_text}\n\n👇 <b>After paying, send a clear Screenshot here:</b>"
                        markup = InlineKeyboardMarkup()
                        markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
                        if row[1] and os.path.exists(row[1]):
                            try:
                                bot.send_photo(message.chat.id, open(row[1], 'rb'), caption=cap, reply_markup=markup)
                            except:
                                bot.reply_to(message, cap, reply_markup=markup)
                        else:
                            bot.reply_to(message, cap, reply_markup=markup)
                    else:
                        markup = InlineKeyboardMarkup()
                        markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
                        bot.reply_to(message, f"{P_CARD} <b>{method} Deposit</b>{rate_text}\n\n👇 Send Screenshot here:", reply_markup=markup)
                        
            except ValueError:
                bot.reply_to(message, f"{P_NO} Please enter a valid amount.\n"
                                    f"<i>Enter USDT amount for Cwallet/Binance/TRON/Polygon deposits.</i>")
            return

        # Handle UTR input for FamPay
        if uid in temp_data and temp_data.get(uid, {}).get('step') == 'wait_utr':
            utr = text.strip()
            
            if utr.lower() == '/cancel':
                temp_data.pop(uid, None)
                bot.reply_to(message, f"{P_NO} Cancelled.")
                return
            
            order_id = temp_data[uid].get('order_id')
            row = cur.execute("SELECT amount, status FROM upi_orders WHERE order_id=?", (order_id,)).fetchone()
            
            if not row:
                temp_data.pop(uid, None)
                return bot.reply_to(message, f"{P_NO} Order not found. Please try again.")
            
            if row[1] == 'success':
                temp_data.pop(uid, None)
                return bot.reply_to(message, f"{P_YES} Payment already credited!")
            
            requested_amount = row[0]
            
            bot.reply_to(message, f"🔍 Checking payment with UTR: `{utr}`...")
            
            verify_url = f"{FAMPAY_API_URL}/verify?amount={requested_amount}&utr={utr}&txn_id={utr}&tg_chat_id={uid}&tg_bot_token={BOT_TOKEN}"
            
            asyncio.run_coroutine_threadsafe(
                handle_utr_verification(uid, order_id, utr, requested_amount, message),
                telethon_loop
            )
            return
        # Handle menu buttons
        if "Buy Account" in text:
            show_countries(message, 'single', 1)
        elif "Buy Sessions" in text:
            show_countries(message, 'bulk', 1)
        elif "Deposit" in text:
            deposit_menu(message.chat.id)
        elif "My Profile" in text:
            profile_handler(message)
        elif "My Stats" in text:
            stats_handler(message)
        elif "Support" in text:
            markup = InlineKeyboardMarkup(row_width=1)
            markup.add(
                InlineKeyboardButton(
                    "📩 Support",
                    url=get_support_url(),
                    style="success",
                    icon_custom_emoji_id="5364125616801073577"
                ),
                InlineKeyboardButton(
                    "📢 Channel",
                    url=JOIN_URLS[1],
                    style="primary",
                    icon_custom_emoji_id="5364125616801073577"
                )
            )
            bot.send_message(message.chat.id, f"{P_ON} <b>STY SHOP Support & Relevant Information</b>\n\n{P_WARN} For Support Contact Admin ..", reply_markup=markup)
        elif "Admin Panel" in text:
            if is_admin(uid):
                admin_panel_handler(message)

    except Exception as ex:
        print(f"Message Error: {ex}")
        traceback.print_exc()

@bot.message_handler(content_types=['document'])
def handle_documents(message):
    uid = message.from_user.id

    if uid not in temp_data:
        return

    if temp_data[uid].get("admin_action") != "adm_addzip":
        return

    if not message.document.file_name.lower().endswith(".zip"):
        bot.reply_to(message, "Send a ZIP file.")
        return

    print("ZIP RECEIVED")

    file_info = bot.get_file(message.document.file_id)
    downloaded = bot.download_file(file_info.file_path)

    zip_path = f"temp_{int(time.time())}.zip"

    with open(zip_path, "wb") as f:
        f.write(downloaded)

    print("ZIP SAVED")

    future = asyncio.run_coroutine_threadsafe(
        telethon_add_zip_stock(message.chat.id, uid, zip_path),
        telethon_loop
    )

    future.add_done_callback(
        lambda f: print("TASK ERROR:", f.exception()) if f.exception() else print("TASK OK")
    )

    temp_data.pop(uid, None)

async def handle_admin_conversation_async(message, uid, admin_action):
    text = message.text
    chat_id = message.chat.id
    if admin_action == "adm_addstock" and has_perm(uid, 'p_add_stock'):
        bot.send_message(chat_id, f"{P_PHONE} <b>Enter Phone Number:</b>\n"
                                 f"<i>Example: +919241009204</i>\n\n"
                                 f"<i>Type /cancel to cancel</i>")
        temp_data[uid] = {'admin_action': 'adm_addstock_phone'}
        return
    
    elif admin_action == "adm_addstock_phone":
        phone = text.replace(" ", "").replace("+", "")
        if not phone.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid phone number. Please enter digits only.")
            temp_data.pop(uid, None)
            return
        
        temp_data[uid] = {'admin_action': 'adm_addstock_otp', 'phone': phone}
        bot.send_message(chat_id, f"{P_WAIT} <b>Sending OTP to your Telegram...</b>")
        await send_otp_request(chat_id, uid, phone, temp_data)
        return
    
    elif admin_action == "adm_addstock_otp":
        otp = text.strip()
        if not otp.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid OTP. Please enter digits only.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        await process_add_stock_login(chat_id, uid, phone, otp, temp_data)
        return
    
    elif admin_action == "adm_addstock_2fa":
        twofa_pass = text.strip()
        if twofa_pass.lower() == '/cancel':
            temp_data.pop(uid, None)
            bot.send_message(chat_id, f"{P_NO} Cancelled.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        await process_add_stock_2fa(chat_id, uid, phone, twofa_pass, temp_data)
        return
    
    # ==================== ADD STOCK FROM SESSION FILE ====================
    elif admin_action == "adm_addstock_file":
        if message.document and message.document.file_name.endswith('.session'):
            try:
                file_info = bot.get_file(message.document.file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                phone = temp_data.get(uid, {}).get('phone', message.document.file_name.replace('.session', ''))
                sess_path = f"sessions/{phone}"
                with open(sess_path + ".session", "wb") as f:
                    f.write(downloaded_file)
                
                bot.send_message(chat_id, f"{P_WAIT} <b>Processing account...</b>\n"
                                        f"Please follow the conversation below.\n"
                                        f"{'═'*50}")
                
                conv = AddStockConversation(bot, chat_id, uid)
                temp_data[uid] = {"admin_action": "adm_addstock_conversation", "conv": conv}
                
                await telethon_add_stock_full(chat_id, uid, phone, sess_path, conv)
                # Don't pop temp_data here - let the conversation handle it
            except Exception as e:
                bot.send_message(chat_id, f"{P_NO} Error: {e}")
                temp_data.pop(uid, None)
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a valid .session file.")
        return
    
    # ==================== BACKUP SESSIONS ====================
    elif admin_action == "adm_backupsessions" and has_perm(uid, 'p_settings'):
        await telethon_backup_sessions(chat_id)
        temp_data.pop(uid, None)
        return
    
    # ==================== ADD ZIP STOCK ====================

    
    # ==================== SYNC ADMIN ACTIONS (HANDLED IN SYNC WRAPPER) ====================
    else:
        # For other admin actions, use sync handler
        handle_admin_conversation_sync(message, uid, admin_action)
        return
    
    # ==================== ADD SINGLE STOCK (WITH OTP FLOW) ====================
    if admin_action == "adm_addstock" and has_perm(uid, 'p_add_stock'):
        bot.send_message(chat_id, f"{P_PHONE} <b>Enter Phone Number:</b>\n"
                                 f"<i>Example: +919241009204</i>\n\n"
                                 f"<i>Type /cancel to cancel</i>")
        temp_data[uid] = {'admin_action': 'adm_addstock_phone'}
        return
    
    elif admin_action == "adm_addstock_phone":
        phone = text.replace(" ", "").replace("+", "")
        if not phone.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid phone number. Please enter digits only.")
            temp_data.pop(uid, None)
            return
        
        temp_data[uid] = {'admin_action': 'adm_addstock_otp', 'phone': phone}
        bot.send_message(chat_id, f"{P_WAIT} <b>Sending OTP to your Telegram...</b>")
        asyncio.run_coroutine_threadsafe(
            send_otp_request(chat_id, uid, phone, temp_data),
            telethon_loop
        )
        return
    
    elif admin_action == "adm_addstock_otp":
        otp = text.strip()
        if not otp.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid OTP. Please enter digits only.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        asyncio.run_coroutine_threadsafe(
            process_add_stock_login(chat_id, uid, phone, otp, temp_data),
            telethon_loop
        )
        return
    
    elif admin_action == "adm_addstock_2fa":
        twofa_pass = text.strip()
        if twofa_pass.lower() == '/cancel':
            temp_data.pop(uid, None)
            bot.send_message(chat_id, f"{P_NO} Cancelled.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        asyncio.run_coroutine_threadsafe(
            process_add_stock_2fa(chat_id, uid, phone, twofa_pass, temp_data),
            telethon_loop
        )
        return
    
    # ==================== ADD STOCK FROM SESSION FILE ====================
    elif admin_action == "adm_addstock_file":
        if message.document and message.document.file_name.endswith('.session'):
            try:
                file_info = bot.get_file(message.document.file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                phone = temp_data.get(uid, {}).get('phone', message.document.file_name.replace('.session', ''))
                sess_path = f"sessions/{phone}"
                with open(sess_path + ".session", "wb") as f:
                    f.write(downloaded_file)
                
                bot.send_message(chat_id, f"{P_WAIT} <b>Processing account...</b>\n"
                                        f"Please follow the conversation below.\n"
                                        f"{'═'*50}")
                
                conv = AddStockConversation(bot, chat_id, uid)
                temp_data[uid] = {"admin_action": "adm_addstock_conversation", "conv": conv}
                
                asyncio.run_coroutine_threadsafe(
                    telethon_add_stock_full(chat_id, uid, phone, sess_path, conv),
                    telethon_loop
                )
                temp_data.pop(uid, None)
            except Exception as e:
                bot.send_message(chat_id, f"{P_NO} Error: {e}")
                temp_data.pop(uid, None)
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a valid .session file.")
        return
    
    # ==================== BACKUP SESSIONS ====================
    elif admin_action == "adm_backupsessions" and has_perm(uid, 'p_settings'):
        asyncio.run_coroutine_threadsafe(
            telethon_backup_sessions(chat_id),
            telethon_loop
        )
        temp_data.pop(uid, None)
        return
    
    # ==================== ADD ZIP STOCK ====================
    
    # ==================== SYNC ADMIN ACTIONS (HANDLED IN SYNC WRAPPER) ====================
    # These are handled in handle_admin_conversation_sync
    else:
        handle_admin_conversation_sync(message, uid, admin_action)
        return
async def handle_utr_verification(uid, order_id, utr, requested_amount, message):
    verify_url = f"{FAMPAY_API_URL}/verify?amount={requested_amount}&utr={utr}&txn_id={utr}&tg_chat_id={uid}&tg_bot_token={BOT_TOKEN}"
    
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(verify_url, timeout=aiohttp.ClientTimeout(total=15)) as resp:
                data = await resp.json()
                
                if data.get("success"):
                    amount_paid = data.get("amount_paid", requested_amount)
                    amount_credited = data.get("amount_credited", requested_amount)
                    is_partial = data.get("is_partial", False)
                    
                    async with get_user_lock(uid):
                        row2 = cur.execute("SELECT status FROM upi_orders WHERE order_id=?", (order_id,)).fetchone()
                        if row2 and row2[0] == 'pending':
                            prev_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (uid,)).fetchone()
                            prev_bal = prev_row[0] if prev_row else 0
                            
                            update_balance(uid, amount_credited)
                            cur.execute("UPDATE upi_orders SET status='success' WHERE order_id=?", (order_id,))
                            cur.execute("UPDATE users SET total_deposited = total_deposited + ? WHERE user_id=?", (amount_credited, uid))
                            db.commit()
                            
                            await process_referral_bonus(uid, amount_credited)
                            await log_primary_deposit(uid, amount_credited, "FamPay Automatic")
                            
                            if is_partial:
                                bot.reply_to(message, f"{P_YES} <b>Partial Payment Verified!</b>\n\n"
                                             f"💰 Amount Paid: ₹{amount_paid}\n"
                                             f"💳 Amount Credited: ₹{amount_credited}\n"
                                             f"🔢 UTR: `{utr}`\n\n"
                                             f"⚠️ You paid less than the requested amount.\n"
                                             f"Only ₹{amount_credited} has been added to your balance.\n\n"
                                             f"📉 Previous Balance: ₹{prev_bal}\n"
                                             f"📈 New Balance: ₹{prev_bal + amount_credited}")
                            else:
                                bot.reply_to(message, f"{P_YES} <b>Payment Verified!</b>\n\n"
                                             f"💰 Amount Paid: ₹{amount_paid}\n"
                                             f"💳 Amount Credited: ₹{amount_credited}\n"
                                             f"🔢 UTR: `{utr}`\n\n"
                                             f"📉 Previous Balance: ₹{prev_bal}\n"
                                             f"📈 New Balance: ₹{prev_bal + amount_credited}")
                    temp_data.pop(uid, None)
                    return
                else:
                    if data.get("already_used"):
                        used_amount = data.get("used_amount", "unknown")
                        used_time = data.get("used_time", "unknown")
                        bot.reply_to(message, f"{P_NO} <b>UTR Already Used!</b>\n\n"
                                     f"This UTR `{utr}` was already used on {used_time}\n"
                                     f"for ₹{used_amount}.\n\n"
                                     f"Cannot use the same UTR twice.")
                    else:
                        bot.reply_to(message, f"{P_NO} Payment not found with UTR `{utr}`.\n\n"
                                     f"Please check the UTR number and try again.\n"
                                     f"Make sure you sent the payment to `{FAMPAY_UPI_ID}`\n\n"
                                     f"💡 Tip: UTR is the transaction ID from your bank/UPI app receipt.")
                    temp_data.pop(uid, None)
                    return
    except Exception as err:
        print(f"UTR verification error: {err}")
        bot.reply_to(message, f"{P_WARN} Verification server error. Please try again later.")
        temp_data.pop(uid, None)

def handle_admin_conversation(message, uid, admin_action):
    text = message.text
    chat_id = message.chat.id
    
    # ==================== ADD SINGLE STOCK (WITH OTP FLOW) ====================
    if admin_action == "adm_addstock" and has_perm(uid, 'p_add_stock'):
        # Step 1: Ask for phone number like original
        bot.send_message(chat_id, f"{P_PHONE} <b>Enter Phone Number:</b>\n"
                                 f"<i>Example: +919241009204</i>\n\n"
                                 f"<i>Type /cancel to cancel</i>")
        temp_data[uid] = {'admin_action': 'adm_addstock_phone'}
        return
    
    elif admin_action == "adm_addstock_phone":
        phone = text.replace(" ", "").replace("+", "")
    
        if not phone.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid phone number. Please enter digits only.")
            temp_data.pop(uid, None)
            return
        
        # Store phone and send OTP immediately
        temp_data[uid] = {'admin_action': 'adm_addstock_otp', 'phone': phone}
        
        bot.send_message(chat_id, f"{P_WAIT} <b>Sending OTP to your Telegram...</b>")
        
        asyncio.run_coroutine_threadsafe(
            send_otp_request(chat_id, uid, phone, temp_data),
            telethon_loop
        )
        return
    
    elif admin_action == "adm_addstock_otp":
        otp = text.strip()
        
        if not otp.isdigit():
            bot.send_message(chat_id, f"{P_NO} Invalid OTP. Please enter digits only.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        # Store OTP and ask for 2FA if needed
        temp_data[uid]['otp'] = otp
        temp_data[uid]['admin_action'] = 'adm_addstock_2fa'
        
        # Try to connect with Telethon
        bot.send_message(chat_id, f"{P_WAIT} <b>Connecting to Telegram...</b>")
        
        # Start the connection process
        asyncio.run_coroutine_threadsafe(
            process_add_stock_login(chat_id, uid, phone, otp, temp_data),
            telethon_loop
        )
        return
    
    elif admin_action == "adm_addstock_2fa":
        twofa_pass = text.strip()
        
        if twofa_pass.lower() == '/cancel':
            temp_data.pop(uid, None)
            bot.send_message(chat_id, f"{P_NO} Cancelled.")
            return
        
        phone = temp_data[uid].get('phone')
        if not phone:
            bot.send_message(chat_id, f"{P_NO} Error: Phone number not found.")
            temp_data.pop(uid, None)
            return
        
        # Store 2FA and proceed
        temp_data[uid]['twofa'] = twofa_pass
        temp_data[uid]['admin_action'] = 'adm_addstock_proceed'
        
        # Continue with the login
        asyncio.run_coroutine_threadsafe(
            process_add_stock_2fa(chat_id, uid, phone, twofa_pass, temp_data),
            telethon_loop
        )
        return
    
    # ==================== ADD STOCK FROM SESSION FILE ====================
    elif admin_action == "adm_addstock_file":
        if message.document and message.document.file_name.endswith('.session'):
            try:
                # Download session file
                file_info = bot.get_file(message.document.file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                
                # Get phone from filename or from temp_data
                phone = temp_data.get(uid, {}).get('phone', message.document.file_name.replace('.session', ''))
                sess_path = f"sessions/{phone}"
                
                # Save session file
                with open(sess_path + ".session", "wb") as f:
                    f.write(downloaded_file)
                
                bot.send_message(chat_id, f"{P_WAIT} <b>Processing account...</b>\n"
                                        f"Please follow the conversation below.\n"
                                        f"{'═'*50}")
                
                # Create conversation handler
                conv = AddStockConversation(bot, chat_id, uid)
                temp_data[uid] = {
                    "admin_action": "adm_addstock_conversation",
                    "conv": conv
                }
                
                # Start the add stock process with full flow
                asyncio.run_coroutine_threadsafe(
                    telethon_add_stock_full(chat_id, uid, phone, sess_path, conv),
                    telethon_loop
                )
                
                temp_data.pop(uid, None)
                
            except Exception as e:
                bot.send_message(chat_id, f"{P_NO} Error: {e}")
                temp_data.pop(uid, None)
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a valid .session file.")
        return
    
    # ==================== ADD ZIP STOCK ====================
    # ==================== ADD ZIP STOCK ====================
    # ==================== ADD ZIP STOCK ====================
    elif admin_action == "adm_addzip" and has_perm(uid, 'p_add_stock'):
        if message.document and message.document.file_name.endswith('.zip'):
            try:
                # Download the file
                file_info = bot.get_file(message.document.file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                
                zip_path = f"temp_{int(time.time())}.zip"
                with open(zip_path, "wb") as f:
                    f.write(downloaded_file)
                
                # Check if file was downloaded
                if os.path.exists(zip_path) and os.path.getsize(zip_path) > 0:
                    bot.send_message(chat_id, f"{P_WAIT} <b>Processing ZIP file...</b>\n"
                                            f"This may take a few moments.")
                    
                    # Clear temp_data before starting
                    temp_data.pop(uid, None)
                    
                    # Run the async function
                    asyncio.run_coroutine_threadsafe(
                        telethon_add_zip_stock(chat_id, uid, zip_path),
                        telethon_loop
                    )
                else:
                    bot.send_message(chat_id, f"{P_NO} Failed to download ZIP file.")
                    temp_data.pop(uid, None)
                
            except Exception as e:
                bot.send_message(chat_id, f"{P_NO} Error processing ZIP: {e}")
                temp_data.pop(uid, None)
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a valid .zip file containing session files.")
        return
    # ==================== BACKUP SESSIONS ====================
    elif admin_action == "adm_backupsessions" and has_perm(uid, 'p_settings'):
        asyncio.run_coroutine_threadsafe(
            telethon_backup_sessions(chat_id),
            telethon_loop
        )
        temp_data.pop(uid, None)
        return
    
    # ==================== SUPPORT URL ====================
    elif admin_action == "adm_supporturl" and has_perm(uid, 'p_settings'):
        url = text
        if not url.startswith("http"):
            url = "https://" + url.replace("@", "t.me/")
        cur.execute("UPDATE settings SET value=? WHERE key='support_url'", (url,))
        db.commit()
        bot.send_message(chat_id, f"{P_YES} Support URL updated.")
        temp_data.pop(uid, None)
        return
    
    # ==================== BROADCAST ====================
    elif admin_action == "adm_bcast" and has_perm(uid, 'p_stats'):
        users = cur.execute("SELECT user_id FROM users").fetchall()
        s, f = 0, 0
        for (u_id,) in users:
            try:
                bot.send_message(int(u_id), text, parse_mode='HTML')
                s += 1
            except:
                f += 1
            time.sleep(0.1)
        bot.send_message(chat_id, f"{P_YES} Done! Sent: {s} | Failed: {f}")
        temp_data.pop(uid, None)
        return
    
    # ==================== CHANGE BALANCE ====================
    elif admin_action == "adm_bal" and has_perm(uid, 'p_bal'):
        try:
            parts = text.split()
            if len(parts) >= 2:
                t_uid = int(parts[0])
                amt = int(parts[1])
                update_balance(t_uid, amt)
                bot.send_message(chat_id, f"{P_YES} Added {P_INR}{amt} to {t_uid}.")
            else:
                bot.send_message(chat_id, f"{P_NO} Use: user_id amount")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid format.")
        temp_data.pop(uid, None)
        return
    
    # ==================== DISCOUNT ====================
    elif admin_action == "adm_discount" and has_perm(uid, 'p_settings'):
        try:
            parts = text.split()
            if len(parts) >= 2:
                t_uid = int(parts[0])
                pct = int(parts[1])
                cur.execute("UPDATE users SET discount=? WHERE user_id=?", (pct, t_uid))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} User {t_uid} has {pct}% discount.")
            else:
                bot.send_message(chat_id, f"{P_NO} Use: user_id percentage")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid format.")
        temp_data.pop(uid, None)
        return
    
    # ==================== REFERRAL PERCENTAGE ====================
    elif admin_action == "adm_refpct" and has_perm(uid, 'p_settings'):
        try:
            pct = int(text)
            cur.execute("UPDATE settings SET value=? WHERE key='ref_percent'", (str(pct),))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Ref revenue set to {pct}%.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid number.")
        temp_data.pop(uid, None)
        return
    
    # ==================== USDT RATE ====================
    elif admin_action == "adm_usdtrate" and has_perm(uid, 'p_settings'):
        try:
            r = float(text)
            cur.execute("UPDATE settings SET value=? WHERE key='usdt_rate'", (str(r),))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Rate set to {r}.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid number.")
        temp_data.pop(uid, None)
        return
    
    # ==================== RESTORE USERS ====================
    elif admin_action == "adm_restoreusr" and has_perm(uid, 'p_settings'):
        if message.document and message.document.file_name.endswith('.csv'):
            file_info = bot.get_file(message.document.file_id)
            downloaded_file = bot.download_file(file_info.file_path)
            with open("temp_restore.csv", "wb") as f:
                f.write(downloaded_file)
            with open("temp_restore.csv", "r", encoding="utf-8") as f:
                reader = csv.reader(f)
                next(reader)
                count = 0
                for row in reader:
                    try:
                        cur.execute("INSERT OR REPLACE INTO users (user_id, balance, referred_by, total_deposited, joined_date, banned, discount, terms_accepted) VALUES (?,?,?,?,?,?,?,?)",
                                    (int(row[0]), int(row[1]), row[2] if row[2] else None, int(row[3]), row[4], int(row[5]), int(row[6]), int(row[7])))
                        count += 1
                    except:
                        pass
            db.commit()
            os.remove("temp_restore.csv")
            bot.send_message(chat_id, f"{P_YES} Restored {count} users.")
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a CSV file.")
        temp_data.pop(uid, None)
        return
    
    # ==================== BAN USER ====================
    elif admin_action == "adm_ban" and has_perm(uid, 'p_bal'):
        try:
            t_uid = int(text)
            is_ban = cur.execute("SELECT banned FROM users WHERE user_id=?", (t_uid,)).fetchone()
            if not is_ban:
                return bot.send_message(chat_id, f"{P_NO} User not found.")
            ns = 0 if is_ban[0] == 1 else 1
            cur.execute("UPDATE users SET banned=? WHERE user_id=?", (ns, t_uid))
            db.commit()
            bot.send_message(chat_id, f"User {t_uid} is {'Banned 🚫' if ns == 1 else 'Unbanned ✅'}.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ==================== ADD ADMIN ====================
    elif admin_action == "adm_addadmin" and is_super_admin(uid):
        try:
            new_ad = int(text)
            cur.execute("INSERT OR IGNORE INTO admins (user_id) VALUES (?)", (new_ad,))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Admin added!")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ==================== EDIT ADMIN ====================
    elif admin_action == "adm_editadminreq" and is_super_admin(uid):
        try:
            t_id = int(text)
            class FakeCall:
                from_user = type('obj', (object,), {'id': uid})
                message = type('obj', (object,), {'chat': {'id': chat_id}, 'message_id': 0})
            fake_call = FakeCall()
            edit_admin_menu(fake_call, t_id)
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ==================== USER INFO ====================
    elif admin_action == "adm_userinfo" and has_perm(uid, 'p_stats'):
        try:
            t_uid = int(text)
            u_row = cur.execute("SELECT balance, total_deposited, joined_date, banned, discount FROM users WHERE user_id=?", (t_uid,)).fetchone()
            if not u_row:
                return bot.send_message(chat_id, f"{P_NO} User not found.")
            
            o_row = cur.execute("SELECT COUNT(*), SUM(price) FROM orders WHERE user_id=?", (t_uid,)).fetchone()
            up_row = cur.execute("SELECT SUM(amount) FROM upi_orders WHERE user_id=? AND status='success'", (t_uid,)).fetchone()
            
            bal, dep, joined, is_banned, disc = u_row
            o_count = o_row[0] if o_row else 0
            o_spent = o_row[1] if o_row and o_row[1] else 0
            u_upi = up_row[0] if up_row and up_row[0] else 0
            
            msg = (f"{P_ACC} <b>USER INFO:</b> <code>{t_uid}</code>\n\n"
                   f"{P_MONEY} Balance: {P_INR}{bal}\n"
                   f"{P_CARD} Total Deposited: {P_INR}{dep}\n"
                   f"{P_UPI} UPI Deposited: {P_INR}{u_upi}\n"
                   f"{P_CART} Total Orders: {o_count}\n"
                   f"{P_USDT} Total Spent: {P_INR}{o_spent}\n"
                   f"{P_GIFT} Discount: {disc}%\n"
                   f"{P_CAL} Joined: {joined}\n"
                   f"{P_OFF} Banned: {'Yes' if is_banned else 'No'}")
            bot.send_message(chat_id, msg)
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ==================== ADD CUSTOM COUNTRY ====================
    elif admin_action == "adm_ap_add_country" and has_perm(uid, 'p_manage_stock'):
        try:
            parts = text.split('|')
            if len(parts) >= 3:
                code, flag, name = parts[0].strip(), parts[1].strip(), parts[2].strip()
                cur.execute("INSERT OR REPLACE INTO custom_countries (code, name, flag) VALUES (?,?,?)", (code, name, flag))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} <b>Custom Country Added Successfully!</b>\n{flag} {name} (+{code})")
            else:
                bot.send_message(chat_id, f"{P_NO} Invalid format. Use: code|flag|name")
        except:
            bot.send_message(chat_id, f"{P_NO} Error adding country.")
        temp_data.pop(uid, None)
        return
    
    # ==================== ADD PAYMENT METHOD ====================
    elif admin_action == "adm_addpay" and has_perm(uid, 'p_settings'):
        try:
            name = text
            temp_data[uid] = {'admin_action': 'adm_addpay_qr', 'name': name}
            bot.send_message(chat_id, f"{P_DOC} <b>Send the QR code image</b>\nType 'skip' to skip QR.")
        except:
            bot.send_message(chat_id, f"{P_NO} Error.")
            temp_data.pop(uid, None)
        return
    
    # ==================== DELETE PAYMENT METHOD ====================
    elif admin_action == "adm_delpay" and has_perm(uid, 'p_settings'):
        try:
            del_id = int(text)
            file_path = cur.execute("SELECT qr_file_id FROM custom_payments WHERE id=?", (del_id,)).fetchone()
            if file_path and file_path[0] and os.path.exists(file_path[0]):
                os.remove(file_path[0])
            cur.execute("DELETE FROM custom_payments WHERE id=?", (del_id,))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Deleted!")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid ID.")
        temp_data.pop(uid, None)
        return
    
    # ==================== MANAGE STOCK EDIT ACTIONS ====================
    elif admin_action.startswith("adm_msedit|") and has_perm(uid, 'p_manage_stock'):
        parts = admin_action.split("|")
        action, c_name = parts[1], parts[2]
        
        if action == "name":
            new_name = html.escape(text)
            cur.execute("UPDATE stock SET country_name=? WHERE country_name=?", (new_name, c_name))
            cur.execute("UPDATE auto_prices SET country=? WHERE country=?", (new_name, c_name))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Country '{c_name}' successfully renamed to '{new_name}'!")
        elif action == "flag":
            new_flag = html.escape(text)
            cur.execute("UPDATE stock SET country_icon=? WHERE country_name=?", (new_flag, c_name))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Flag updated to {new_flag} for '{c_name}'!")
        elif action == "cprice":
            try:
                new_p = int(text)
                cur.execute("UPDATE stock SET price=? WHERE country_name=?", (new_p, c_name))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} All existing '{c_name}' accounts updated to {P_INR}{new_p}!")
            except:
                bot.send_message(chat_id, f"{P_NO} Invalid price.")
        elif action == "yprice":
            try:
                year = parts[3]
                new_p = int(text)
                cur.execute("UPDATE stock SET price=? WHERE country_name=? AND account_year=?", (new_p, c_name, year))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} All existing '{c_name}' ({year}) accounts updated to {P_INR}{new_p}!")
            except:
                bot.send_message(chat_id, f"{P_NO} Invalid price.")
        temp_data.pop(uid, None)
        return
    
    # ==================== AUTO PRICE SET ACTIONS ====================
    elif admin_action.startswith("adm_apset|") and has_perm(uid, 'p_manage_stock'):
        parts = admin_action.split("|")
        c_name, year = parts[1], parts[2]
        try:
            new_p = int(text)
            if new_p == 0:
                cur.execute("DELETE FROM auto_prices WHERE country=? AND year=?", (c_name, year))
                bot.send_message(chat_id, f"{P_YES} Auto-Price for {c_name} ({year}) removed!")
            else:
                cur.execute("INSERT OR REPLACE INTO auto_prices (country, year, price) VALUES (?,?,?)", (c_name, year, new_p))
                bot.send_message(chat_id, f"{P_YES} Auto-Price for {c_name} ({year}) set to {P_INR}{new_p}!")
            db.commit()
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid price.")
        temp_data.pop(uid, None)
        return

    # ==================== ADD PAYMENT QR STEP ====================
    # ==================== ADD PAYMENT QR STEP ====================
    elif admin_action == "adm_addpay_qr":
        name = temp_data[uid].get('name', '')
        print(f"📸 DEBUG: Add payment QR step for {name}")
        print(f"📸 DEBUG: Message has photo: {message.photo is not None}")
        print(f"📸 DEBUG: Message text: {text}")
        
        if text and text.lower() == 'skip':
            qr_path = ""
            print(f"📸 DEBUG: Skipping QR")
        elif message.photo:
            qr_path = f"qr_{int(time.time())}.jpg"
            print(f"📸 DEBUG: Saving photo to {qr_path}")
            try:
                file_info = bot.get_file(message.photo[-1].file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                with open(qr_path, "wb") as f:
                    f.write(downloaded_file)
                print(f"✅ DEBUG: Photo saved successfully")
            except Exception as e:
                print(f"❌ DEBUG: Failed to save photo: {e}")
                bot.send_message(chat_id, f"{P_NO} Failed to save image. Please try again or type 'skip'.")
                return
        elif message.document and message.document.file_name and message.document.file_name.lower().endswith(('.jpg', '.jpeg', '.png', '.gif')):
            # Handle document that is an image
            qr_path = f"qr_{int(time.time())}.jpg"
            print(f"📸 DEBUG: Saving document as image to {qr_path}")
            try:
                file_info = bot.get_file(message.document.file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                with open(qr_path, "wb") as f:
                    f.write(downloaded_file)
                print(f"✅ DEBUG: Document saved successfully")
            except Exception as e:
                print(f"❌ DEBUG: Failed to save document: {e}")
                bot.send_message(chat_id, f"{P_NO} Failed to save image. Please try again or type 'skip'.")
                return
        else:
            bot.send_message(chat_id, f"{P_NO} Please send an image, document, or type 'skip'.")
            return
        
        temp_data[uid] = {'admin_action': 'adm_addpay_cap', 'name': name, 'qr_path': qr_path}
        bot.send_message(chat_id, f"{P_DOC} <b>Enter the payment caption:</b>\nUse <code>text</code> for copyable wallet IDs.")
        print(f"✅ DEBUG: Moving to caption step for {name}")
        return

def handle_admin_conversation_sync(message, uid, admin_action):
    """Synchronous handler for admin actions"""
    text = message.text or ""
    chat_id = message.chat.id
    
    print(f"🔍 DEBUG: admin_action: {admin_action}")
    print(f"🔍 DEBUG: Message content_type: {message.content_type}")
    print(f"🔍 DEBUG: Message has photo: {message.photo is not None}")
    print(f"🔍 DEBUG: Message has document: {message.document is not None}")
    print(f"🔍 DEBUG: Message text: {text}")
    
    # ==================== ADD PAYMENT QR STEP ====================
    if admin_action == "adm_addpay_qr":
        name = temp_data[uid].get('name', '')
        print(f"📸 DEBUG: Add payment QR step for {name}")
        
        # Check if user sent a photo
        if message.photo:
            qr_path = f"qr_{int(time.time())}.jpg"
            print(f"📸 DEBUG: Saving photo to {qr_path}")
            try:
                file_info = bot.get_file(message.photo[-1].file_id)
                downloaded_file = bot.download_file(file_info.file_path)
                with open(qr_path, "wb") as f:
                    f.write(downloaded_file)
                print(f"✅ DEBUG: Photo saved successfully")
                
                temp_data[uid] = {'admin_action': 'adm_addpay_cap', 'name': name, 'qr_path': qr_path}
                bot.send_message(chat_id, f"{P_DOC} <b>Enter the payment caption:</b>\nUse <code>text</code> for copyable wallet IDs.")
                return
            except Exception as e:
                print(f"❌ DEBUG: Failed to save photo: {e}")
                bot.send_message(chat_id, f"{P_NO} Failed to save image. Please try again or type 'skip'.")
                return
        
        # Check if user sent a document (image file)
        elif message.document:
            file_name = message.document.file_name or ""
            print(f"📸 DEBUG: Document received: {file_name}")
            if file_name.lower().endswith(('.jpg', '.jpeg', '.png', '.gif', '.webp')):
                qr_path = f"qr_{int(time.time())}.jpg"
                print(f"📸 DEBUG: Saving document as image to {qr_path}")
                try:
                    file_info = bot.get_file(message.document.file_id)
                    downloaded_file = bot.download_file(file_info.file_path)
                    with open(qr_path, "wb") as f:
                        f.write(downloaded_file)
                    print(f"✅ DEBUG: Document saved successfully")
                    
                    temp_data[uid] = {'admin_action': 'adm_addpay_cap', 'name': name, 'qr_path': qr_path}
                    bot.send_message(chat_id, f"{P_DOC} <b>Enter the payment caption:</b>\nUse <code>text</code> for copyable wallet IDs.")
                    return
                except Exception as e:
                    print(f"❌ DEBUG: Failed to save document: {e}")
                    bot.send_message(chat_id, f"{P_NO} Failed to save image. Please try again or type 'skip'.")
                    return
            else:
                bot.send_message(chat_id, f"{P_NO} Please send an image file (jpg, png, gif) or type 'skip'.")
                return
        
        # Check if user typed 'skip'
        elif text and text.lower() == 'skip':
            qr_path = ""
            print(f"📸 DEBUG: Skipping QR")
            temp_data[uid] = {'admin_action': 'adm_addpay_cap', 'name': name, 'qr_path': qr_path}
            bot.send_message(chat_id, f"{P_DOC} <b>Enter the payment caption:</b>\nUse <code>text</code> for copyable wallet IDs.")
            return
        
        else:
            bot.send_message(chat_id, f"{P_NO} Please send an image, document, or type 'skip'.")
            return
    
    # ==================== ADD PAYMENT CAPTION STEP ====================
    elif admin_action == "adm_addpay_cap":
        name = temp_data[uid].get('name', '')
        qr_path = temp_data[uid].get('qr_path', '')
        cap_msg = html.escape(text).replace("&lt;code&gt;", "<code>").replace("&lt;/code&gt;", "</code>")
        cur.execute("INSERT INTO custom_payments (name, caption, qr_file_id) VALUES (?,?,?)", (name, cap_msg, qr_path))
        db.commit()
        bot.send_message(chat_id, f"{P_YES} Payment Method '{name}' added successfully!")
        temp_data.pop(uid, None)
        return

    # ==================== ADD PAYMENT METHOD - NAME STEP ====================
    elif admin_action == "adm_addpay":
        try:
            name = text.strip()
            if not name:
                bot.send_message(chat_id, f"{P_NO} Please enter a valid name.")
                return
            temp_data[uid] = {'admin_action': 'adm_addpay_qr', 'name': name}
            bot.send_message(chat_id, f"{P_DOC} <b>Send the QR code image</b>\nType 'skip' to skip QR.")
            print(f"✅ DEBUG: Moved to adm_addpay_qr for {name}")
        except Exception as e:
            print(f"❌ DEBUG: Error in adm_addpay: {e}")
            temp_data.pop(uid, None)
            bot.send_message(chat_id, f"{P_NO} Error: {e}")
        return
    # ... rest of your existing code for other admin actions ...
    
    # SUPPORT URL
    if admin_action == "adm_supporturl" and has_perm(uid, 'p_settings'):
        url = text
        if not url.startswith("http"):
            url = "https://" + url.replace("@", "t.me/")
        cur.execute("UPDATE settings SET value=? WHERE key='support_url'", (url,))
        db.commit()
        bot.send_message(chat_id, f"{P_YES} Support URL updated.")
        temp_data.pop(uid, None)
        return
    
    # BROADCAST
    if admin_action == "adm_bcast" and has_perm(uid, 'p_stats'):
        users = cur.execute("SELECT user_id FROM users").fetchall()
        s, f = 0, 0
        for (u_id,) in users:
            try:
                bot.send_message(int(u_id), text, parse_mode='HTML')
                s += 1
            except:
                f += 1
            time.sleep(0.1)
        bot.send_message(chat_id, f"{P_YES} Done! Sent: {s} | Failed: {f}")
        temp_data.pop(uid, None)
        return
    
    # CHANGE BALANCE
    if admin_action == "adm_bal" and has_perm(uid, 'p_bal'):
        try:
            parts = text.split()
            if len(parts) >= 2:
                t_uid = int(parts[0])
                amt = int(parts[1])
                update_balance(t_uid, amt)
                bot.send_message(chat_id, f"{P_YES} Added {P_INR}{amt} to {t_uid}.")
            else:
                bot.send_message(chat_id, f"{P_NO} Use: user_id amount")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid format.")
        temp_data.pop(uid, None)
        return
    
    # DISCOUNT
    if admin_action == "adm_discount" and has_perm(uid, 'p_settings'):
        try:
            parts = text.split()
            if len(parts) >= 2:
                t_uid = int(parts[0])
                pct = int(parts[1])
                cur.execute("UPDATE users SET discount=? WHERE user_id=?", (pct, t_uid))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} User {t_uid} has {pct}% discount.")
            else:
                bot.send_message(chat_id, f"{P_NO} Use: user_id percentage")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid format.")
        temp_data.pop(uid, None)
        return
    
    # REFERRAL PERCENTAGE
    if admin_action == "adm_refpct" and has_perm(uid, 'p_settings'):
        try:
            pct = int(text)
            cur.execute("UPDATE settings SET value=? WHERE key='ref_percent'", (str(pct),))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Ref revenue set to {pct}%.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid number.")
        temp_data.pop(uid, None)
        return
    
    # USDT RATE
    if admin_action == "adm_usdtrate" and has_perm(uid, 'p_settings'):
        try:
            r = float(text)
            cur.execute("UPDATE settings SET value=? WHERE key='usdt_rate'", (str(r),))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Rate set to {r}.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid number.")
        temp_data.pop(uid, None)
        return
    
    # RESTORE USERS
    if admin_action == "adm_restoreusr" and has_perm(uid, 'p_settings'):
        if message.document and message.document.file_name.endswith('.csv'):
            file_info = bot.get_file(message.document.file_id)
            downloaded_file = bot.download_file(file_info.file_path)
            with open("temp_restore.csv", "wb") as f:
                f.write(downloaded_file)
            with open("temp_restore.csv", "r", encoding="utf-8") as f:
                reader = csv.reader(f)
                next(reader)
                count = 0
                for row in reader:
                    try:
                        cur.execute("INSERT OR REPLACE INTO users (user_id, balance, referred_by, total_deposited, joined_date, banned, discount, terms_accepted) VALUES (?,?,?,?,?,?,?,?)",
                                    (int(row[0]), int(row[1]), row[2] if row[2] else None, int(row[3]), row[4], int(row[5]), int(row[6]), int(row[7])))
                        count += 1
                    except:
                        pass
            db.commit()
            os.remove("temp_restore.csv")
            bot.send_message(chat_id, f"{P_YES} Restored {count} users.")
        else:
            bot.send_message(chat_id, f"{P_NO} Please send a CSV file.")
        temp_data.pop(uid, None)
        return
    
    # BAN USER
    if admin_action == "adm_ban" and has_perm(uid, 'p_bal'):
        try:
            t_uid = int(text)
            is_ban = cur.execute("SELECT banned FROM users WHERE user_id=?", (t_uid,)).fetchone()
            if not is_ban:
                return bot.send_message(chat_id, f"{P_NO} User not found.")
            ns = 0 if is_ban[0] == 1 else 1
            cur.execute("UPDATE users SET banned=? WHERE user_id=?", (ns, t_uid))
            db.commit()
            bot.send_message(chat_id, f"User {t_uid} is {'Banned 🚫' if ns == 1 else 'Unbanned ✅'}.")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ADD ADMIN
    if admin_action == "adm_addadmin" and is_super_admin(uid):
        try:
            new_ad = int(text)
            cur.execute("INSERT OR IGNORE INTO admins (user_id) VALUES (?)", (new_ad,))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Admin added!")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # EDIT ADMIN
    if admin_action == "adm_editadminreq" and is_super_admin(uid):
        try:
            t_id = int(text)
            class FakeCall:
                from_user = type('obj', (object,), {'id': uid})
                message = type('obj', (object,), {'chat': {'id': chat_id}, 'message_id': 0})
            fake_call = FakeCall()
            edit_admin_menu(fake_call, t_id)
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # USER INFO
    if admin_action == "adm_userinfo" and has_perm(uid, 'p_stats'):
        try:
            t_uid = int(text)
            u_row = cur.execute("SELECT balance, total_deposited, joined_date, banned, discount FROM users WHERE user_id=?", (t_uid,)).fetchone()
            if not u_row:
                return bot.send_message(chat_id, f"{P_NO} User not found.")
            
            o_row = cur.execute("SELECT COUNT(*), SUM(price) FROM orders WHERE user_id=?", (t_uid,)).fetchone()
            up_row = cur.execute("SELECT SUM(amount) FROM upi_orders WHERE user_id=? AND status='success'", (t_uid,)).fetchone()
            
            bal, dep, joined, is_banned, disc = u_row
            o_count = o_row[0] if o_row else 0
            o_spent = o_row[1] if o_row and o_row[1] else 0
            u_upi = up_row[0] if up_row and up_row[0] else 0
            
            msg = (f"{P_ACC} <b>USER INFO:</b> <code>{t_uid}</code>\n\n"
                   f"{P_MONEY} Balance: {P_INR}{bal}\n"
                   f"{P_CARD} Total Deposited: {P_INR}{dep}\n"
                   f"{P_UPI} UPI Deposited: {P_INR}{u_upi}\n"
                   f"{P_CART} Total Orders: {o_count}\n"
                   f"{P_USDT} Total Spent: {P_INR}{o_spent}\n"
                   f"{P_GIFT} Discount: {disc}%\n"
                   f"{P_CAL} Joined: {joined}\n"
                   f"{P_OFF} Banned: {'Yes' if is_banned else 'No'}")
            bot.send_message(chat_id, msg)
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid user ID.")
        temp_data.pop(uid, None)
        return
    
    # ADD CUSTOM COUNTRY
    if admin_action == "adm_ap_add_country" and has_perm(uid, 'p_manage_stock'):
        try:
            parts = text.split('|')
            if len(parts) >= 3:
                code, flag, name = parts[0].strip(), parts[1].strip(), parts[2].strip()
                cur.execute("INSERT OR REPLACE INTO custom_countries (code, name, flag) VALUES (?,?,?)", (code, name, flag))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} <b>Custom Country Added Successfully!</b>\n{flag} {name} (+{code})")
            else:
                bot.send_message(chat_id, f"{P_NO} Invalid format. Use: code|flag|name")
        except:
            bot.send_message(chat_id, f"{P_NO} Error adding country.")
        temp_data.pop(uid, None)
        return
    
    # ADD PAYMENT METHOD
    if admin_action == "adm_addpay" and has_perm(uid, 'p_settings'):
        try:
            name = text
            temp_data[uid] = {'admin_action': 'adm_addpay_qr', 'name': name}
            bot.send_message(chat_id, f"{P_DOC} <b>Send the QR code image</b>\nType 'skip' to skip QR.")
        except:
            bot.send_message(chat_id, f"{P_NO} Error.")
            temp_data.pop(uid, None)
        return
    
    # DELETE PAYMENT METHOD
    if admin_action == "adm_delpay" and has_perm(uid, 'p_settings'):
        try:
            del_id = int(text)
            file_path = cur.execute("SELECT qr_file_id FROM custom_payments WHERE id=?", (del_id,)).fetchone()
            if file_path and file_path[0] and os.path.exists(file_path[0]):
                os.remove(file_path[0])
            cur.execute("DELETE FROM custom_payments WHERE id=?", (del_id,))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Deleted!")
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid ID.")
        temp_data.pop(uid, None)
        return
    
    # MSEDIT ACTIONS
    if admin_action.startswith("adm_msedit|") and has_perm(uid, 'p_manage_stock'):
        parts = admin_action.split("|")
        action, c_name = parts[1], parts[2]
        
        if action == "name":
            new_name = html.escape(text)
            cur.execute("UPDATE stock SET country_name=? WHERE country_name=?", (new_name, c_name))
            cur.execute("UPDATE auto_prices SET country=? WHERE country=?", (new_name, c_name))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Country '{c_name}' successfully renamed to '{new_name}'!")
        elif action == "flag":
            new_flag = html.escape(text)
            cur.execute("UPDATE stock SET country_icon=? WHERE country_name=?", (new_flag, c_name))
            db.commit()
            bot.send_message(chat_id, f"{P_YES} Flag updated to {new_flag} for '{c_name}'!")
        elif action == "cprice":
            try:
                new_p = int(text)
                cur.execute("UPDATE stock SET price=? WHERE country_name=?", (new_p, c_name))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} All existing '{c_name}' accounts updated to {P_INR}{new_p}!")
            except:
                bot.send_message(chat_id, f"{P_NO} Invalid price.")
        elif action == "yprice":
            try:
                year = parts[3]
                new_p = int(text)
                cur.execute("UPDATE stock SET price=? WHERE country_name=? AND account_year=?", (new_p, c_name, year))
                db.commit()
                bot.send_message(chat_id, f"{P_YES} All existing '{c_name}' ({year}) accounts updated to {P_INR}{new_p}!")
            except:
                bot.send_message(chat_id, f"{P_NO} Invalid price.")
        temp_data.pop(uid, None)
        return
    
    # APSET ACTIONS
    if admin_action.startswith("adm_apset|") and has_perm(uid, 'p_manage_stock'):
        parts = admin_action.split("|")
        c_name, year = parts[1], parts[2]
        try:
            new_p = int(text)
            if new_p == 0:
                cur.execute("DELETE FROM auto_prices WHERE country=? AND year=?", (c_name, year))
                bot.send_message(chat_id, f"{P_YES} Auto-Price for {c_name} ({year}) removed!")
            else:
                cur.execute("INSERT OR REPLACE INTO auto_prices (country, year, price) VALUES (?,?,?)", (c_name, year, new_p))
                bot.send_message(chat_id, f"{P_YES} Auto-Price for {c_name} ({year}) set to {P_INR}{new_p}!")
            db.commit()
        except:
            bot.send_message(chat_id, f"{P_NO} Invalid price.")
        temp_data.pop(uid, None)
        return
    
    # ADD PAYMENT QR STEP
 
# ================= CALLBACK QUERY HANDLER =================
@bot.callback_query_handler(func=lambda call: True)
def handle_callbacks(call):
    try:
        uid = call.from_user.id
        if not is_bot_online() and not is_admin(uid):
            return bot.answer_callback_query(call.id, "⚙️ Bot is under maintenance.", show_alert=True)
            
        ensure_user(uid)
        now = time.time()
        if uid in user_spam_cooldown and now - user_spam_cooldown[uid] < 0.5:
            return bot.answer_callback_query(call.id, "⚠️ Please slow down! Don't spam buttons.", show_alert=True)
        user_spam_cooldown[uid] = now

        if is_user_banned(uid):
            return bot.answer_callback_query(call.id, "🚫 BANNED", show_alert=True)
        
        data = call.data

        # ============ NEW: FORCE JOIN VERIFY BUTTON ============
        if data == "force_join_check":
            uid = call.from_user.id
            chat_id = call.message.chat.id
            message_id = call.message.message_id
            
            bot.edit_message_text(
                f"{P_WAIT} <b>Checking channel membership...</b>",
                chat_id,
                message_id
            )
            
            asyncio.run_coroutine_threadsafe(
                check_and_force_join_after_click(uid, chat_id, message_id),
                telethon_loop
            )
            return

        # ============ TERMS AND CONDITIONS ============
        if data == "tc_accept":
            cur.execute("UPDATE users SET terms_accepted=1 WHERE user_id=?", (uid,))
            db.commit()
            bot.answer_callback_query(call.id, "✅ Terms Accepted!", show_alert=True)
            
            if is_admin(uid):
                send_main_menu(call.message.chat.id, uid)
            else:
                asyncio.run_coroutine_threadsafe(
                    check_and_force_join(uid, call.message.chat.id),
                    telethon_loop
                )
            return
        
        if data == "tc_reject":
            bot.edit_message_text(f"{P_NO} You cannot use the bot without accepting the terms.", call.message.chat.id, call.message.message_id)
            return
        
        # ============ CANCEL ACTION ============
        if data == "cancel_action":
            deposit_input.pop(uid, None)
            waiting_proof.pop(uid, None)
            session_buy_state.pop(uid, None)
            temp_data.pop(uid, None)
            admin_dep_state.pop(uid, None)
            custom_dep_amt.pop(uid, None)
            
            try:
                bot.edit_message_text(
                    f"{P_NO} <b>Action Cancelled.</b>",
                    call.message.chat.id,
                    call.message.message_id
                )
            except Exception:
                try:
                    bot.send_message(
                        call.message.chat.id,
                        f"{P_NO} <b>Action Cancelled.</b>"
                    )
                except:
                    pass
            
            try:
                bot.edit_message_reply_markup(call.message.chat.id, call.message.message_id, reply_markup=None)
            except:
                pass
            return

        # ============ BACK TO MENU ============
        if data == "back_to_menu":
            send_main_menu(call.message.chat.id, uid)
            return

        # ============ COUNTRY PAGES ============
        if data.startswith("pg_c|"):
            parts = data.split("|")
            show_countries(call, parts[1], int(parts[2]))
            return

        # ============ BUY COUNTRY ============
        if data.startswith("bc|"):
            parts = data.split("|")
            show_years(call, parts[1], parts[2])
            return

        # ============ BUY YEAR ============
        if data.startswith("by|"):
            parts = data.split("|")
            if parts[1] == 'single':
                confirm_purchase(call, parts[2], parts[3], parts[4])
            else:
                init_session_purchase(call, parts[2], parts[3], parts[4])
            return

        # ============ CONFIRM PURCHASE ============
        if data.startswith("buy_cf|"):
            parts = data.split("|")
            process_purchase(call, parts[1], parts[2], parts[3])
            return

        # ============ GET OTP AGAIN ============
        if data.startswith("get_otp_again|"):
            phone = data.split("|")[1]
            if phone not in active_orders:
                return bot.answer_callback_query(call.id, "⚠️ Session already logged out or expired.", show_alert=True)
            
            order = active_orders[phone]
            client = order['client']
            start_time = order['start_time']
            
            bot.answer_callback_query(call.id, "🔄 Fetching latest OTP...")
            
            asyncio.run_coroutine_threadsafe(
                get_otp_again_telethon(call, phone, client, start_time),
                telethon_loop
            )
            return

        # ============ MANAGE DEVICES ============
        if data.startswith("manage_devices|"):
            phone = data.split("|")[1]
            if phone not in active_orders:
                return bot.answer_callback_query(call.id, "⚠️ Session expired. Please buy again.", show_alert=True)
            
            order = active_orders[phone]
            client = order['client']
            
            bot.answer_callback_query(call.id, "📱 Fetching active devices...")
            
            asyncio.run_coroutine_threadsafe(
                manage_devices_telethon(call, phone, client),
                telethon_loop
            )
            return

        # ============ LOGOUT DEVICE ============
        if data.startswith("logout_device|"):
            parts = data.split("|")
            if len(parts) < 3:
                return bot.answer_callback_query(call.id, "❌ Invalid request.", show_alert=True)
            
            phone = parts[1]
            session_id = parts[2]
            
            if phone not in active_orders:
                return bot.answer_callback_query(call.id, "⚠️ Session expired. Please buy again.", show_alert=True)
            
            order = active_orders[phone]
            client = order['client']
            
            bot.answer_callback_query(call.id, "🔒 Logging out device...")
            
            asyncio.run_coroutine_threadsafe(
                logout_device_telethon(call, phone, client, session_id),
                telethon_loop
            )
            return

        # ============ BACK TO OTP ============
        if data.startswith("back_to_otp|"):
            phone = data.split("|")[1]
            if phone not in active_orders:
                return bot.answer_callback_query(call.id, "⚠️ Session expired. Please buy again.", show_alert=True)
            
            order = active_orders[phone]
            client = order['client']
            start_time = order['start_time']
            
            asyncio.run_coroutine_threadsafe(
                back_to_otp_telethon(call, phone, client, start_time),
                telethon_loop
            )
            return

        # ============ LOGOUT BOT ============
        if data.startswith("logout_bot|"):
            phone = data.split("|")[1]
            if phone in active_orders:
                order = active_orders.pop(phone)
                asyncio.run_coroutine_threadsafe(
                    logout_bot_telethon(call, phone, order),
                    telethon_loop
                )
            else:
                bot.answer_callback_query(call.id, "⚠️ No active order found or already logged out.", show_alert=True)
            return

        # ============ PURCHASE PAGES ============
        if data.startswith("page_purchases_"):
            page = int(data.split("_")[2])
            send_purchase_page(call, uid, page)
            return

        # ============ BACK TO STATS ============
        if data == "back_to_stats":
            stats_msg = (f"{P_STATS} <b>My Statistics</b>\n\n"
                        f"{P_CART} <b>Accounts Bought:</b> ...\n"
                        f"{P_MONEY} <b>Total Spent:</b> ...\n"
                        f"{P_CARD} <b>Total Deposited:</b> ...")
            markup = InlineKeyboardMarkup(row_width=1)
            markup.add(
                InlineKeyboardButton("View Purchase Logs", callback_data="page_purchases_1"),
                InlineKeyboardButton("Referral Logs", callback_data="view_referrals")
            )
            bot.edit_message_text(stats_msg, call.message.chat.id, call.message.message_id, reply_markup=markup)
            return

        # ============ VIEW REFERRALS ============
        if data == "view_referrals":
            view_referrals(call)
            return

        # ============ DEPOSIT METHODS ============
        if data.startswith("depm_"):
            manual_deposit_init(call, data.replace("depm_", ""))
            return

        # ============ UPI DEPOSIT ============
        if data == "dep_upi":
            init_upi_keypad(call)
            return

        # ============ KEYPAD LOGIC ============
        if data.startswith("kp_"):
            keypad_logic(call)
            return

        # ============ CHECK FAMPAY ============
        if data.startswith("check_fampay_"):
            order_id = data.replace("check_fampay_", "")
            
            temp_data[uid] = {'order_id': order_id, 'step': 'wait_utr'}
            
            markup = InlineKeyboardMarkup()
            markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
            
            bot.send_message(
                call.message.chat.id,
                f"{P_OTP} <b>Enter UTR Number</b>\n\n"
                f"Please enter the UTR (Transaction ID) from your payment receipt.\n"
                f"UTR is usually a 12-16 digit number or alphanumeric code.\n\n"
                f"Type /cancel to cancel.",
                reply_markup=markup
            )
            bot.answer_callback_query(call.id)
            return

        # ============ ADMIN ACTIONS ============
        if data.startswith("adm_"):
            handle_admin_actions(call)
            return

        # ============ DEPOSIT CUSTOM AMOUNT KEYPAD ============
        # ============ DEPOSIT CUSTOM AMOUNT KEYPAD ============
        if data.startswith("dkp|") and has_perm(uid, 'p_bal'):
            _, dep_id, action = data.split("|")
            dep_id = int(dep_id)
            row = cur.execute("SELECT user_id, method_name, status, amount FROM deposits WHERE id=?", (dep_id,)).fetchone()
            if not row or row[2] != 'pending':
                return bot.answer_callback_query(call.id, f"{P_WARN} Already processed.", show_alert=True)
            t_uid, method, orig_amt = row[0], row[1], row[3]
            
            # Get USDT amount if available
            usdt_orig = None
            if method in ("Cwallet", "Binance"):
                usdt_orig = round(orig_amt / get_usdt_rate(), 2)
            
            curr = custom_dep_amt.get(dep_id, "0")
            
            if action.isdigit():
                if curr == "0":
                    curr = action
                else:
                    curr += action
                if len(curr) > 7:
                    curr = curr[:7]
            elif action == "del":
                curr = curr[:-1] or "0"
            elif action == "cancel":
                markup = InlineKeyboardMarkup(row_width=2)
                markup.add(
                    InlineKeyboardButton(f"✅ Accept (₹{orig_amt})", callback_data=f"dep_acc|{dep_id}|{t_uid}|{method}|exact|{orig_amt}"),
                    InlineKeyboardButton("❌ Reject", callback_data=f"dep_rej|{dep_id}|{t_uid}")
                )
                markup.add(InlineKeyboardButton("📝 Custom Amount", callback_data=f"dep_acc|{dep_id}|{t_uid}|{method}|custom|0"))
                
                display_text = f"🔔 <b>NEW DEPOSIT REQUEST</b>\n{P_ACC} User: <code>{t_uid}</code>\n{P_MONEY} Request: <b>{P_INR}{orig_amt}</b>"
                if usdt_orig:
                    display_text += f"\n{P_USDT} USDT: <b>${usdt_orig}</b>"
                display_text += f"\n{P_CARD} Method: {method}\n{P_ID} Ref: <code>{dep_id}</code>"
                
                try:
                    bot.edit_message_text(
                        display_text,
                        call.message.chat.id,
                        call.message.message_id,
                        reply_markup=markup
                    )
                except Exception as e:
                    # If editing fails, send a new message
                    print(f"⚠️ Could not edit message: {e}")
                    bot.send_message(
                        call.message.chat.id,
                        display_text,
                        reply_markup=markup
                    )
                return
            elif action == "conf":
                amt = int(curr)
                if amt <= 0:
                    return bot.answer_callback_query(call.id, "Amount must be > 0", show_alert=True)
                
                asyncio.run_coroutine_threadsafe(
                    process_custom_deposit(call, dep_id, t_uid, amt),
                    telethon_loop
                )
                return

            custom_dep_amt[dep_id] = curr
            
            display_text = f"{P_KEY} <b>Enter Custom Amount for User {t_uid}:</b>\n\n{P_MONEY} {curr}"
            if method in ("Cwallet", "Binance"):
                display_text += f"\n\n<i>Amount is in INR. USDT will be calculated automatically.</i>"
            
            try:
                bot.edit_message_text(
                    display_text,
                    call.message.chat.id,
                    call.message.message_id,
                    reply_markup=get_admin_custom_keypad(dep_id)
                )
            except Exception as e:
                # If editing fails, send a new message
                print(f"⚠️ Could not edit message: {e}")
                bot.send_message(
                    call.message.chat.id,
                    display_text,
                    reply_markup=get_admin_custom_keypad(dep_id)
                )
            return

        # ============ DEPOSIT ACCEPT ============
        if data.startswith("dep_acc|") and has_perm(uid, 'p_bal'):
            parts = data.split("|")
            dep_id, t_uid, method, a_type = parts[1], int(parts[2]), parts[3], parts[4]
            row = cur.execute("SELECT status FROM deposits WHERE id=?", (dep_id,)).fetchone()
            if not row or row[0] != 'pending':
                return bot.answer_callback_query(call.id, f"{P_WARN} Already processed.", show_alert=True)
            
            if a_type == "exact":
                amt = int(parts[5])
                asyncio.run_coroutine_threadsafe(
                    process_exact_deposit(call, dep_id, t_uid, amt),
                    telethon_loop
                )
                
            elif a_type == "custom":
                # Get the original deposit amount for display
                dep_row = cur.execute("SELECT amount, method_name FROM deposits WHERE id=?", (dep_id,)).fetchone()
                if dep_row:
                    orig_amt, orig_method = dep_row[0], dep_row[1]
                    usdt_orig = None
                    if orig_method in ("Cwallet", "Binance"):
                        usdt_orig = round(orig_amt / get_usdt_rate(), 2)
                    
                    custom_dep_amt[int(dep_id)] = "0"
                    
                    # Get the original message or send a new one
                    try:
                        # Try to edit the existing message
                        display_text = f"{P_KEY} <b>Enter Custom Amount for User {t_uid}:</b>\n\n{P_MONEY} 0"
                        if orig_method in ("Cwallet", "Binance"):
                            display_text += f"\n\n<i>Original Amount: {P_INR}{orig_amt} (${usdt_orig} USDT)</i>\n<i>Enter new amount in INR</i>"
                        
                        bot.edit_message_text(
                            display_text,
                            call.message.chat.id,
                            call.message.message_id,
                            reply_markup=get_admin_custom_keypad(int(dep_id))
                        )
                    except Exception as e:
                        # If editing fails (photo message), send a new message
                        print(f"⚠️ Could not edit message: {e}")
                        display_text = f"{P_KEY} <b>Enter Custom Amount for User {t_uid}:</b>\n\n{P_MONEY} 0"
                        if orig_method in ("Cwallet", "Binance"):
                            display_text += f"\n\n<i>Original Amount: {P_INR}{orig_amt} (${usdt_orig} USDT)</i>\n<i>Enter new amount in INR</i>"
                        
                        bot.send_message(
                            call.message.chat.id,
                            display_text,
                            reply_markup=get_admin_custom_keypad(int(dep_id))
                        )
            return

        # ============ DEPOSIT REJECT ============
        if data.startswith("dep_rej|") and has_perm(uid, 'p_bal'):
            parts = data.split("|")
            dep_id, t_uid = parts[1], int(parts[2])
            row = cur.execute("SELECT status FROM deposits WHERE id=?", (dep_id,)).fetchone()
            if not row or row[0] != 'pending':
                return bot.answer_callback_query(call.id, f"{P_WARN} Already processed.", show_alert=True)
            
            admin_dep_state[uid] = {
                'target_uid': t_uid, 
                'dep_id': dep_id, 
                'step': 'wait_reason',
                'msg_id': call.message.message_id
            }
            bot.send_message(uid, f"{P_WARN} Reply to this message with the REASON for rejecting user <code>{t_uid}</code>:")
            bot.answer_callback_query(call.id, "Check your bot PMs to enter the reason.", show_alert=True)
            return

    except Exception as ex:
        print(f"Callback Error: {ex}")
        traceback.print_exc()
async def get_zip_conversation_response(conv, prompt, chat_id, uid):
    """Get response using a single conversation object"""
    # Send prompt and wait for response
    response = await conv.get_response(prompt)
    return response.text
# ================= TELEPHON CALLBACK HELPERS =================
async def get_otp_again_telethon(call, phone, client, start_time):
    try:
        msgs = await client.get_messages(777000, limit=5)
        latest_code = None
        for m in msgs:
            if m.date.timestamp() > start_time - 10:
                if m.message and re.search(OTP_REGEX, m.message) and "Login detected" not in m.message:
                    latest_code = re.search(OTP_REGEX, m.message).group()
                    break
        
        if latest_code:
            order = active_orders[phone]
            twofa_text = f"{P_2FA} <b>2FA:</b> <code>{order['twofa']}</code>" if order['twofa'] != "None" else f"🔓 <b>2FA:</b> <code>Disabled (No Password)</code>"
            msg = (f"{P_YES} <b>Latest OTP Fetched!</b>\n\n"
                f"{P_PHONE} <b>Phone:</b> <code>{phone}</code>\n"
                f"{P_FLAG} <b>Country:</b> {order['c_icon']} {order['country']}\n"
                f"{P_OTP} <b>OTP:</b> <code>{latest_code}</code>\n"
                f"{twofa_text}\n\n"
                f"{P_WARN} <b>⚠️ SECURITY ALERT!</b>\n"
                f"Please click <b>📱 Manage Devices</b> below and logout any device that does NOT belong to you!\n"
                f"<i>This ensures your account is secure.</i>")
            
            markup = InlineKeyboardMarkup(row_width=1)
            markup.add(
                InlineKeyboardButton("🔄 Get OTP Again", callback_data=f"get_otp_again|{phone}"),
                InlineKeyboardButton("📱 Manage Devices", callback_data=f"manage_devices|{phone}"),
                InlineKeyboardButton("🚪 Finish & Logout", callback_data=f"logout_bot|{phone}")
            )
            
            try:
                bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)
            except:
                pass
        else:
            bot.answer_callback_query(call.id, "⏳ No new OTP found yet. Try again in a few seconds.", show_alert=True)
    except Exception as ex:
        bot.answer_callback_query(call.id, "❌ Error fetching OTP.", show_alert=True)

async def manage_devices_telethon(call, phone, client):
    try:
        devices = await get_active_devices(client)
        
        if not devices:
            bot.edit_message_text(
                "📱 <b>Active Devices</b>\n\nNo devices found or unable to fetch device list.",
                call.message.chat.id,
                call.message.message_id,
                reply_markup=InlineKeyboardMarkup().add(InlineKeyboardButton("🔙 Back to OTP", callback_data=f"back_to_otp|{phone}"))
            )
            return
        
        msg = f"{P_WARN} <b>⚠️ SECURITY CHECK REQUIRED!</b>\n"
        msg += f"<i>Review all active devices below. Logout any device you don't recognize!</i>\n\n"
        msg += f"📱 <b>Active Devices for +{phone}</b>\n\n"
        msg += f"<i>Total: {len(devices)} active sessions</i>\n\n"
        
        markup = InlineKeyboardMarkup(row_width=1)
        for idx, device in enumerate(devices, 1):
            is_current = "⭐ CURRENT" if device.get('is_current', False) else ""
            device_icon = "📱" if "phone" in device.get('device_model', '').lower() else "💻"
            
            msg += f"{device_icon} <b>Device {idx}</b> {is_current}\n"
            msg += f"└ Model: {device.get('device_model', 'Unknown')}\n"
            msg += f"└ Platform: {device.get('device_platform', 'Unknown')}\n"
            msg += f"└ App: {device.get('app_name', 'Unknown')} v{device.get('app_version', 'Unknown')}\n"
            msg += f"└ IP: {device.get('ip_address', 'Unknown')}\n"
            msg += f"└ Location: {device.get('location', 'Unknown')}\n"
            
            if device.get('date_active'):
                active_time = device['date_active'].strftime('%Y-%m-%d %H:%M')
                msg += f"└ Last Active: {active_time}\n"
            
            if device.get('date_created'):
                created_time = device['date_created'].strftime('%Y-%m-%d %H:%M')
                msg += f"└ Created: {created_time}\n"
            
            msg += f"└ Session ID: <code>{str(device.get('session_id', 'N/A'))[:8]}...</code>\n"
            msg += "────────────────\n"
            
            if not device.get('is_current', False) and device.get('session_id'):
                markup.add(InlineKeyboardButton(f"❌ Logout Device {idx}", callback_data=f"logout_device|{phone}|{device.get('session_id', '')}"))
        
        markup.add(InlineKeyboardButton("🔄 Refresh", callback_data=f"manage_devices|{phone}"))
        markup.add(InlineKeyboardButton("🔙 Back to OTP", callback_data=f"back_to_otp|{phone}"))
        
        bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)
        
    except Exception as ex:
        logger.error(f"Device management error: {ex}")
        bot.answer_callback_query(call.id, "❌ Failed to fetch devices. Try again later.", show_alert=True)

async def logout_device_telethon(call, phone, client, session_id):
    try:
        success = await logout_device(client, session_id)
        
        if success:
            bot.answer_callback_query(call.id, "✅ Device logged out successfully!", show_alert=True)
            await manage_devices_telethon(call, phone, client)
        else:
            bot.answer_callback_query(call.id, "❌ Failed to logout device. Try again.", show_alert=True)
    except Exception as ex:
        logger.error(f"Logout device error: {ex}")
        bot.answer_callback_query(call.id, "❌ Failed to logout device. Try again later.", show_alert=True)

async def back_to_otp_telethon(call, phone, client, start_time):
    try:
        msgs = await client.get_messages(777000, limit=5)
        latest_code = None
        for m in msgs:
            if m.date.timestamp() > start_time - 10:
                if m.message and re.search(OTP_REGEX, m.message) and "Login detected" not in m.message:
                    latest_code = re.search(OTP_REGEX, m.message).group()
                    break
        
        order = active_orders[phone]
        if latest_code:
            twofa_text = f"{P_2FA} <b>2FA:</b> <code>{order['twofa']}</code>" if order['twofa'] != "None" else f"🔓 <b>2FA:</b> <code>Disabled (No Password)</code>"
            msg_text = (f"{P_YES} <b>Latest OTP Fetched!</b>\n\n"
                    f"{P_PHONE} <b>Phone:</b> <code>{phone}</code>\n"
                    f"{P_FLAG} <b>Country:</b> {order['c_icon']} {order['country']}\n"
                    f"{P_OTP} <b>OTP:</b> <code>{latest_code}</code>\n"
                    f"{twofa_text}\n\n"
                    f"{P_WARN} <b>⚠️ SECURITY ALERT!</b>\n"
                    f"Please click <b>📱 Manage Devices</b> below and logout any device that does NOT belong to you!\n"
                    f"<i>This ensures your account is secure.</i>")
            
            markup = InlineKeyboardMarkup(row_width=1)
            markup.add(
                InlineKeyboardButton("🔄 Get OTP Again", callback_data=f"get_otp_again|{phone}"),
                InlineKeyboardButton("📱 Manage Devices", callback_data=f"manage_devices|{phone}"),
                InlineKeyboardButton("🚪 Finish & Logout", callback_data=f"logout_bot|{phone}")
            )
            
            bot.edit_message_text(msg_text, call.message.chat.id, call.message.message_id, reply_markup=markup)
        else:
            bot.answer_callback_query(call.id, "⏳ No OTP found. Try getting OTP again.", show_alert=True)
    except Exception as ex:
        logger.error(f"Back to OTP error: {ex}")
        bot.answer_callback_query(call.id, "❌ Failed to return to OTP.", show_alert=True)

async def logout_bot_telethon(call, phone, order):
    try:
        await order['client'].log_out()
        await order['client'].disconnect()
        delete_session_files(order['sess'])
        bot.edit_message_text(f"{P_YES} <b>Session Finished & Logged out successfully.</b>", call.message.chat.id, call.message.message_id)
    except:
        bot.edit_message_text(f"{P_YES} <b>Session Finished.</b>", call.message.chat.id, call.message.message_id)

async def process_custom_deposit(call, dep_id, t_uid, amt):
    async with get_user_lock(t_uid):
        prev_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (t_uid,)).fetchone()
        prev_bal = prev_row[0] if prev_row else 0
        update_balance(t_uid, amt)
        cur.execute("UPDATE deposits SET status='approved', amount=? WHERE id=?", (amt, dep_id))
        cur.execute("UPDATE users SET total_deposited = total_deposited + ? WHERE user_id=?", (amt, t_uid))
        db.commit()
    
    await process_referral_bonus(t_uid, amt)
    
    # Calculate USDT amounts
    usdt_amt = round(amt / get_usdt_rate(), 2)
    usdt_prev = round(prev_bal / get_usdt_rate(), 2)
    usdt_new = round((prev_bal + amt) / get_usdt_rate(), 2)
    
    # User message with both INR and USDT
    user_msg = (f"{P_YES} <b>Deposit Approved!</b>\n\n"
                f"{P_MONEY} <b>Amount Added:</b>\n"
                f"   {P_INR} INR: <b>{P_INR}{amt}</b>\n"
                f"   {P_USDT} USDT: <b>${usdt_amt}</b>\n"
                f"   💱 Rate: 1 USDT = {P_INR}{get_usdt_rate()}\n\n"
                f"📉 <b>Previous Balance:</b>\n"
                f"   {P_INR} INR: {P_INR}{prev_bal}\n"
                f"   {P_USDT} USDT: ${usdt_prev}\n"
                f"📈 <b>New Balance:</b>\n"
                f"   {P_INR} INR: {P_INR}{prev_bal + amt}\n"
                f"   {P_USDT} USDT: ${usdt_new}")
    bot.send_message(int(t_uid), user_msg)
    
    # Edit the original message to show APPROVED status instead of buttons
    try:
        new_text = (f"{P_YES} <b>DEPOSIT APPROVED ✅ (Custom Amount)</b>\n\n"
                    f"{P_ACC} User: <code>{t_uid}</code>\n"
                    f"{P_MONEY} Amount:\n"
                    f"   {P_INR} INR: <b>{P_INR}{amt}</b>\n"
                    f"   {P_USDT} USDT: <b>${usdt_amt}</b>\n"
                    f"   💱 Rate: 1 USDT = {P_INR}{get_usdt_rate()}\n"
                    f"{P_TIME} Approved: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
                    f"<i>✅ Deposit has been approved and credited to user's balance.</i>")
        
        bot.edit_message_text(
            new_text,
            call.message.chat.id,
            call.message.message_id,
            parse_mode='HTML'
        )
    except Exception as e:
        try:
            bot.send_message(
                call.message.chat.id,
                f"{P_YES} <b>DEPOSIT APPROVED ✅ (Custom)</b>\nUser: <code>{t_uid}</code>\nINR: {P_INR}{amt}\nUSDT: ${usdt_amt}",
                parse_mode='HTML'
            )
        except:
            pass

async def process_exact_deposit(call, dep_id, t_uid, amt):
    async with get_user_lock(t_uid):
        prev_row = cur.execute("SELECT balance FROM users WHERE user_id=?", (t_uid,)).fetchone()
        prev_bal = prev_row[0] if prev_row else 0
        update_balance(t_uid, amt)
        cur.execute("UPDATE deposits SET status='approved', amount=? WHERE id=?", (amt, dep_id))
        cur.execute("UPDATE users SET total_deposited = total_deposited + ? WHERE user_id=?", (amt, t_uid))
        db.commit()
    
    await process_referral_bonus(t_uid, amt)
    
    # Calculate USDT amounts
    usdt_amt = round(amt / get_usdt_rate(), 2)
    usdt_prev = round(prev_bal / get_usdt_rate(), 2)
    usdt_new = round((prev_bal + amt) / get_usdt_rate(), 2)
    
    # User message with both INR and USDT
    user_msg = (f"{P_YES} <b>Deposit Approved!</b>\n\n"
                f"{P_MONEY} <b>Amount Added:</b>\n"
                f"   {P_INR} INR: <b>{P_INR}{amt}</b>\n"
                f"   {P_USDT} USDT: <b>${usdt_amt}</b>\n"
                f"   💱 Rate: 1 USDT = {P_INR}{get_usdt_rate()}\n\n"
                f"📉 <b>Previous Balance:</b>\n"
                f"   {P_INR} INR: {P_INR}{prev_bal}\n"
                f"   {P_USDT} USDT: ${usdt_prev}\n"
                f"📈 <b>New Balance:</b>\n"
                f"   {P_INR} INR: {P_INR}{prev_bal + amt}\n"
                f"   {P_USDT} USDT: ${usdt_new}")
    bot.send_message(int(t_uid), user_msg)
    
    # Edit the original message to show APPROVED status instead of buttons
    try:
        new_text = (f"{P_YES} <b>DEPOSIT APPROVED ✅</b>\n\n"
                    f"{P_ACC} User: <code>{t_uid}</code>\n"
                    f"{P_MONEY} Amount:\n"
                    f"   {P_INR} INR: <b>{P_INR}{amt}</b>\n"
                    f"   {P_USDT} USDT: <b>${usdt_amt}</b>\n"
                    f"   💱 Rate: 1 USDT = {P_INR}{get_usdt_rate()}\n"
                    f"{P_TIME} Approved: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
                    f"<i>✅ Deposit has been approved and credited to user's balance.</i>")
        
        bot.edit_message_text(
            new_text,
            call.message.chat.id,
            call.message.message_id,
            parse_mode='HTML'
        )
    except Exception as e:
        try:
            bot.send_message(
                call.message.chat.id,
                f"{P_YES} <b>DEPOSIT APPROVED ✅</b>\nUser: <code>{t_uid}</code>\nINR: {P_INR}{amt}\nUSDT: ${usdt_amt}",
                parse_mode='HTML'
            )
        except:
            pass

def handle_admin_actions(call):
    data = call.data
    uid = call.from_user.id
    chat_id = call.message.chat.id
    message_id = call.message.message_id
    
    # ==================== BACK BUTTON ====================
    if data == "adm_adminmain":
        temp_data.pop(uid, None)
        admin_panel_handler(call)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== ADD SINGLE STOCK ====================
    if data == "adm_addstock" and has_perm(uid, 'p_add_stock'):
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_PHONE} <b>Enter Phone Number:</b>\n"
            f"<i>Example: +919241009204</i>\n\n"
            f"<i>Type /cancel to cancel</i>"
        )
        temp_data[uid] = {'admin_action': 'adm_addstock_phone'}
        bot.answer_callback_query(call.id)
        return
    
    # ==================== TOGGLE BOT ====================
    if data == "adm_togglebot" and has_perm(uid, 'p_settings'):
        new_status = 'off' if is_bot_online() else 'on'
        cur.execute("UPDATE settings SET value=? WHERE key='bot_status'", (new_status,))
        db.commit()
        bot.answer_callback_query(call.id, f"Bot turned {new_status.upper()}", show_alert=True)
        admin_panel_handler(call)
        return
    
    # ==================== SET BIO ====================
    if data == "adm_setbio" and has_perm(uid, 'p_settings'):
        handle_admin_setbio(call)
        return

    # ==================== MASS JOIN ====================
    if data == "adm_massjoin" and has_perm(uid, 'p_settings'):
        handle_admin_massjoin(call)
        return
    
    # ==================== STATISTICS ====================
    if data == "adm_stats" and has_perm(uid, 'p_stats'):
        u_row = cur.execute("SELECT COUNT(*) FROM users").fetchone()
        u = u_row[0] if u_row else 0
        s_row = cur.execute("SELECT COUNT(*) FROM stock WHERE available=1").fetchone()
        s = s_row[0] if s_row else 0
        r_row = cur.execute("SELECT value FROM settings WHERE key='upi_revenue'").fetchone()
        r = r_row[0] if r_row else "0"
        bal_row = cur.execute("SELECT SUM(balance) FROM users").fetchone()
        total_bal = bal_row[0] if bal_row and bal_row[0] else 0
        o_row = cur.execute("SELECT COUNT(*), SUM(price) FROM orders").fetchone()
        total_orders = o_row[0] if o_row else 0
        total_spent = o_row[1] if o_row and o_row[1] else 0
        
        msg = (f"{P_STATS} <b>ADVANCED STATS</b>\n\n"
               f"{P_USERS} <b>Total Users:</b> {u}\n"
               f"{P_PKG} <b>Accounts in Stock:</b> {s}\n"
               f"{P_MONEY} <b>Total UPI Revenue:</b> {P_INR}{r}\n\n"
               f"{P_CARD} <b>Overall Users Balance:</b> {P_INR}{total_bal}\n"
               f"{P_CART} <b>Total Accounts Sold:</b> {total_orders}\n"
               f"{P_USDT} <b>Overall Sales Amount:</b> {P_INR}{total_spent}")
        markup = InlineKeyboardMarkup()
        markup.add(InlineKeyboardButton("🔙 Back", callback_data="adm_adminmain"))
        
        # Send new message instead of editing
        bot.send_message(chat_id, msg, reply_markup=markup)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== PAYMENTS ====================
    if data == "adm_payments" and has_perm(uid, 'p_settings'):
        markup = InlineKeyboardMarkup(row_width=1)
        markup.add(
            InlineKeyboardButton("➕ Add Payment Method", callback_data="adm_addpay"),
            InlineKeyboardButton("➖ Remove Payment Method", callback_data="adm_delpay"),
            InlineKeyboardButton("🔙 Back to Admin", callback_data="adm_adminmain")
        )
        # Send new message instead of editing
        bot.send_message(chat_id, f"{P_CARD} <b>Manage Payment Methods</b>", reply_markup=markup)
        bot.answer_callback_query(call.id)
        return

    # ==================== MANAGE ADMINS ====================
    if data == "adm_manageadmins" and is_super_admin(uid):
        manage_admins_menu(call)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_tglperm|") and is_super_admin(uid):
        _, t_id, p_name = data.split("|")
        cur.execute(f"UPDATE admins SET {p_name} = CASE WHEN {p_name}=1 THEN 0 ELSE 1 END WHERE user_id=?", (t_id,))
        db.commit()
        edit_admin_menu(call, t_id)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_deladmin|") and is_super_admin(uid):
        t_id = data.split("|")[1]
        cur.execute("DELETE FROM admins WHERE user_id=?", (t_id,))
        db.commit()
        bot.answer_callback_query(call.id, "✅ Admin Removed", show_alert=True)
        manage_admins_menu(call)
        return
    
    # ==================== MANAGE STOCK ====================
    if data == "adm_managestock" and has_perm(uid, 'p_manage_stock'):
        send_manage_stock_page(call, 1)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_mspg|") and has_perm(uid, 'p_manage_stock'):
        page = int(data.split("|")[1])
        send_manage_stock_page(call, page)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_msc|") and has_perm(uid, 'p_manage_stock'):
        c_name = data.split("|")[1]
        send_manage_stock_country(call, c_name)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== AUTO PRICE ====================
    if data == "adm_autoprice" and has_perm(uid, 'p_manage_stock'):
        send_autoprice_page(call, 1)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_appg|") and has_perm(uid, 'p_manage_stock'):
        page = int(data.split("|")[1])
        send_autoprice_page(call, page)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_apc|") and has_perm(uid, 'p_manage_stock'):
        c_name = data.split("|")[1]
        send_autoprice_country(call, c_name)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== BACKUP USERS ====================
    if data == "adm_backupusr" and has_perm(uid, 'p_settings'):
        cur.execute("SELECT * FROM users")
        with open("users_backup.csv", "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow([i[0] for i in cur.description])
            w.writerows(cur.fetchall())
        with open("users_backup.csv", "rb") as f:
            bot.send_document(chat_id, f, caption=f"{P_USERS} <b>Users Backup CSV</b>")
        os.remove("users_backup.csv")
        bot.answer_callback_query(call.id, "✅ Backup Generated!", show_alert=True)
        return
    
    # ==================== BACKUP SESSIONS ====================
    if data == "adm_backupsessions" and has_perm(uid, 'p_settings'):
        asyncio.run_coroutine_threadsafe(
            telethon_backup_sessions(chat_id),
            telethon_loop
        )
        # Send new message instead of editing
        bot.send_message(chat_id, f"{P_WAIT} <b>Creating backup of all sessions...</b>")
        bot.answer_callback_query(call.id)
        return
    
    # ==================== ADD ZIP ====================
    if data == "adm_addzip" and has_perm(uid, 'p_add_stock'):
        temp_data[uid] = {'admin_action': 'adm_addzip'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_PKG} <b>Send the ZIP file containing session files.</b>\n"
            f"Type /cancel to cancel."
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== CHANGE BALANCE ====================
    if data == "adm_bal" and has_perm(uid, 'p_bal'):
        temp_data[uid] = {'admin_action': 'adm_bal'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_ACC} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== DISCOUNT ====================
    if data == "adm_discount" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_discount'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_GIFT} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== REFERRAL PERCENTAGE ====================
    if data == "adm_refpct" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_refpct'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_USERS} <b>New Referral %:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== USDT RATE ====================
    if data == "adm_usdtrate" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_usdtrate'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_USDT} <b>New USDT Rate (INR):</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== SUPPORT URL ====================
    if data == "adm_supporturl" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_supporturl'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_TG} <b>Enter new Support URL (must start with http:// or https://):</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== BROADCAST ====================
    if data == "adm_bcast" and has_perm(uid, 'p_stats'):
        temp_data[uid] = {'admin_action': 'adm_bcast'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_DOC} <b>Message (Supports HTML & tg-emoji tags):</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== BAN USER ====================
    if data == "adm_ban" and has_perm(uid, 'p_bal'):
        temp_data[uid] = {'admin_action': 'adm_ban'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_ACC} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD ADMIN ====================
    if data == "adm_addadmin" and is_super_admin(uid):
        temp_data[uid] = {'admin_action': 'adm_addadmin'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_ACC} <b>Enter User ID for new Admin:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== EDIT ADMIN ====================
    if data == "adm_editadminreq" and is_super_admin(uid):
        temp_data[uid] = {'admin_action': 'adm_editadminreq'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_ACC} <b>Enter User ID to edit:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== USER INFO ====================
    if data == "adm_userinfo" and has_perm(uid, 'p_stats'):
        temp_data[uid] = {'admin_action': 'adm_userinfo'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_ACC} <b>Enter User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD CUSTOM COUNTRY ====================
    if data == "adm_ap_add_country" and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': 'adm_ap_add_country'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_FLAG} <b>Enter Country Code, Flag, Name (format: code|flag|name):</b>\n"
            f"<i>Example: 91|🇮🇳|India</i>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD PAYMENT METHOD ====================
    if data == "adm_addpay" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_addpay'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_CARD} <b>Enter Payment Method Name:</b>\n"
            f"<i>e.g., Binance Pay, TRX</i>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== DELETE PAYMENT METHOD ====================
    if data == "adm_delpay" and has_perm(uid, 'p_settings'):
        rows = cur.execute("SELECT id, name FROM custom_payments").fetchall()
        if not rows:
            bot.answer_callback_query(call.id, "No custom payment methods.", show_alert=True)
            return
        msg = f"{P_CARD} <b>Reply with the ID of the method to delete:</b>\n\n"
        for r in rows:
            msg += f"ID: {r[0]} - {r[1]}\n"
        msg += f"\n<i>Type /cancel to abort</i>"
        temp_data[uid] = {'admin_action': 'adm_delpay'}
        # Send new message instead of editing
        bot.send_message(chat_id, msg)
        bot.answer_callback_query(call.id)
        return

    # ==================== RESTORE USERS ====================
    if data == "adm_restoreusr" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_restoreusr'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_DOC} <b>Send the users_backup.csv file:</b>\n\n"
            f"<i>Type /cancel to abort</i>"
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== MSEDIT ACTIONS ====================
    if data.startswith("adm_msedit|") and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': data, 'action_type': 'msedit'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_DOC} <b>Please reply to this message with the new value.</b>\n"
            f"Type /cancel to cancel the operation."
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== APSET ACTIONS ====================
    if data.startswith("adm_apset|") and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': data, 'action_type': 'apset'}
        # Send new message instead of editing
        bot.send_message(
            chat_id,
            f"{P_DOC} <b>Please reply to this message with the price (0 to remove).</b>\n"
            f"Type /cancel to cancel the operation."
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== DEFAULT ====================
    bot.answer_callback_query(call.id)
    
    # ==================== MANAGE STOCK ====================
    if data == "adm_managestock" and has_perm(uid, 'p_manage_stock'):
        send_manage_stock_page(call, 1)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_mspg|") and has_perm(uid, 'p_manage_stock'):
        page = int(data.split("|")[1])
        send_manage_stock_page(call, page)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_msc|") and has_perm(uid, 'p_manage_stock'):
        c_name = data.split("|")[1]
        send_manage_stock_country(call, c_name)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== AUTO PRICE ====================
    if data == "adm_autoprice" and has_perm(uid, 'p_manage_stock'):
        send_autoprice_page(call, 1)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_appg|") and has_perm(uid, 'p_manage_stock'):
        page = int(data.split("|")[1])
        send_autoprice_page(call, page)
        bot.answer_callback_query(call.id)
        return
    
    if data.startswith("adm_apc|") and has_perm(uid, 'p_manage_stock'):
        c_name = data.split("|")[1]
        send_autoprice_country(call, c_name)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== BACKUP USERS ====================
    if data == "adm_backupusr" and has_perm(uid, 'p_settings'):
        cur.execute("SELECT * FROM users")
        with open("users_backup.csv", "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow([i[0] for i in cur.description])
            w.writerows(cur.fetchall())
        with open("users_backup.csv", "rb") as f:
            bot.send_document(chat_id, f, caption=f"{P_USERS} <b>Users Backup CSV</b>")
        os.remove("users_backup.csv")
        bot.answer_callback_query(call.id, "✅ Backup Generated!", show_alert=True)
        return
    
    # ==================== BACKUP SESSIONS ====================
    if data == "adm_backupsessions" and has_perm(uid, 'p_settings'):
        asyncio.run_coroutine_threadsafe(
            telethon_backup_sessions(chat_id),
            telethon_loop
        )
        bot.edit_message_text(f"{P_WAIT} <b>Creating backup of all sessions...</b>", chat_id, message_id)
        bot.answer_callback_query(call.id)
        return
    
    # ==================== ADD ZIP ====================
    # ==================== ADD ZIP ====================
    # ==================== ADD ZIP ====================
    if data == "adm_addzip" and has_perm(uid, 'p_add_stock'):
        temp_data[uid] = {'admin_action': 'adm_addzip'}
        bot.edit_message_text(
            f"{P_PKG} <b>Send the ZIP file containing session files.</b>\n"
            f"Send a .zip file with .session files inside\n"
            f"Type /cancel to cancel.",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== CHANGE BALANCE ====================
    if data == "adm_bal" and has_perm(uid, 'p_bal'):
        temp_data[uid] = {'admin_action': 'adm_bal'}
        bot.edit_message_text(
            f"{P_ACC} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== DISCOUNT ====================
    if data == "adm_discount" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_discount'}
        bot.edit_message_text(
            f"{P_GIFT} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== REFERRAL PERCENTAGE ====================
    if data == "adm_refpct" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_refpct'}
        bot.edit_message_text(
            f"{P_USERS} <b>New Referral %:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== USDT RATE ====================
    if data == "adm_usdtrate" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_usdtrate'}
        bot.edit_message_text(
            f"{P_USDT} <b>New USDT Rate (INR):</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== SUPPORT URL ====================
    if data == "adm_supporturl" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_supporturl'}
        bot.edit_message_text(
            f"{P_TG} <b>Enter new Support URL (must start with http:// or https://):</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== BROADCAST ====================
    if data == "adm_bcast" and has_perm(uid, 'p_stats'):
        temp_data[uid] = {'admin_action': 'adm_bcast'}
        bot.edit_message_text(
            f"{P_DOC} <b>Message (Supports HTML & tg-emoji tags):</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== BAN USER ====================
    if data == "adm_ban" and has_perm(uid, 'p_bal'):
        temp_data[uid] = {'admin_action': 'adm_ban'}
        bot.edit_message_text(
            f"{P_ACC} <b>User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD ADMIN ====================
    if data == "adm_addadmin" and is_super_admin(uid):
        temp_data[uid] = {'admin_action': 'adm_addadmin'}
        bot.edit_message_text(
            f"{P_ACC} <b>Enter User ID for new Admin:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== EDIT ADMIN ====================
    if data == "adm_editadminreq" and is_super_admin(uid):
        temp_data[uid] = {'admin_action': 'adm_editadminreq'}
        bot.edit_message_text(
            f"{P_ACC} <b>Enter User ID to edit:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== USER INFO ====================
    if data == "adm_userinfo" and has_perm(uid, 'p_stats'):
        temp_data[uid] = {'admin_action': 'adm_userinfo'}
        bot.edit_message_text(
            f"{P_ACC} <b>Enter User ID:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD CUSTOM COUNTRY ====================
    if data == "adm_ap_add_country" and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': 'adm_ap_add_country'}
        bot.edit_message_text(
            f"{P_FLAG} <b>Enter Country Code, Flag, Name (format: code|flag|name):</b>\n"
            f"<i>Example: 91|🇮🇳|India</i>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== ADD PAYMENT METHOD ====================
    if data == "adm_addpay" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_addpay'}
        bot.edit_message_text(
            f"{P_CARD} <b>Enter Payment Method Name:</b>\n"
            f"<i>e.g., Binance Pay, TRX</i>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return

    # ==================== DELETE PAYMENT METHOD ====================
    if data == "adm_delpay" and has_perm(uid, 'p_settings'):
        rows = cur.execute("SELECT id, name FROM custom_payments").fetchall()
        if not rows:
            bot.answer_callback_query(call.id, "No custom payment methods.", show_alert=True)
            return
        msg = f"{P_CARD} <b>Reply with the ID of the method to delete:</b>\n\n"
        for r in rows:
            msg += f"ID: {r[0]} - {r[1]}\n"
        msg += f"\n<i>Type /cancel to abort</i>"
        temp_data[uid] = {'admin_action': 'adm_delpay'}
        bot.edit_message_text(msg, chat_id, message_id)
        bot.answer_callback_query(call.id)
        return

    # ==================== RESTORE USERS ====================
    if data == "adm_restoreusr" and has_perm(uid, 'p_settings'):
        temp_data[uid] = {'admin_action': 'adm_restoreusr'}
        bot.edit_message_text(
            f"{P_DOC} <b>Send the users_backup.csv file:</b>\n\n"
            f"<i>Type /cancel to abort</i>",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== MSEDIT ACTIONS ====================
    if data.startswith("adm_msedit|") and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': data, 'action_type': 'msedit'}
        bot.edit_message_text(
            f"{P_DOC} <b>Please reply to this message with the new value.</b>\n"
            f"Type /cancel to cancel the operation.",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== APSET ACTIONS ====================
    if data.startswith("adm_apset|") and has_perm(uid, 'p_manage_stock'):
        temp_data[uid] = {'admin_action': data, 'action_type': 'apset'}
        bot.edit_message_text(
            f"{P_DOC} <b>Please reply to this message with the price (0 to remove).</b>\n"
            f"Type /cancel to cancel the operation.",
            chat_id, message_id
        )
        bot.answer_callback_query(call.id)
        return
    
    # ==================== DEFAULT ====================
    bot.answer_callback_query(call.id)
# ==================== SET BIO HANDLER ====================

def handle_admin_setbio(call):
    """Handle Set Bio admin action"""
    uid = call.from_user.id
    chat_id = call.message.chat.id
    
    if not is_admin(uid):
        return bot.answer_callback_query(call.id, "❌ You are not an admin!", show_alert=True)
    
    temp_data[uid] = {'admin_action': 'adm_setbio_phone'}
    
    markup = InlineKeyboardMarkup()
    markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
    
    # Send new message instead of editing
    bot.send_message(
        chat_id,
        f"{P_PHONE} <b>📝 Set Bio</b>\n\n"
        f"Enter phone number to set bio\n"
        f"Type <code>all</code> to set bio for ALL accounts in stock.\n\n"
        f"<i>Type /cancel to cancel</i>",
        reply_markup=markup
    )
    bot.answer_callback_query(call.id)

def handle_admin_massjoin(call):
    """Handle Mass Join admin action"""
    uid = call.from_user.id
    chat_id = call.message.chat.id
    
    if not is_admin(uid):
        return bot.answer_callback_query(call.id, "❌ You are not an admin!", show_alert=True)
    
    temp_data[uid] = {'admin_action': 'adm_massjoin_channel'}
    
    markup = InlineKeyboardMarkup()
    markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
    
    # Send new message instead of editing
    bot.send_message(
        chat_id,
        f"{P_TG} <b>📢 Mass Join</b>\n\n"
        f"Enter Channel Invite Link or Username\n"
        f"Examples:\n"
        f"• <code>https://t.me/yourchannel</code>\n"
        f"• <code>@yourchannel</code>\n"
        f"• <code>yourchannel</code>\n\n"
        
        f"<i>Type /cancel to cancel</i>",
        reply_markup=markup
    )
    bot.answer_callback_query(call.id)
def process_admin_setbio(message):
    """Process Set Bio conversation"""
    uid = message.from_user.id
    chat_id = message.chat.id
    text = message.text.strip()
    
    if text.lower() == '/cancel':
        temp_data.pop(uid, None)
        bot.send_message(chat_id, f"{P_NO} <b>Cancelled.</b>")
        return
    
    if uid not in temp_data:
        return
    
    admin_action = temp_data[uid].get('admin_action')
    
    if admin_action == 'adm_setbio_phone':
        target_phone = text
        
        if target_phone.lower() == 'all':
            temp_data[uid]['target'] = 'all'
        else:
            # Validate phone exists
            account = cur.execute("SELECT phone FROM stock WHERE phone=? AND available=1", (target_phone,)).fetchone()
            if not account:
                bot.send_message(chat_id, f"{P_NO} Account {target_phone} not found in stock!")
                temp_data.pop(uid, None)
                return
            temp_data[uid]['target'] = target_phone
        
        temp_data[uid]['admin_action'] = 'adm_setbio_text'
        
        markup = InlineKeyboardMarkup()
        markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
        
        bot.send_message(
            chat_id,
            f"{P_DOC} <b>Enter the bio text:</b>\n"
            f"<i>(Max 70 characters for Telegram bio)</i>\n"
            f"Type <code>/cancel</code> to cancel.",
            reply_markup=markup
        )
        return
    
    elif admin_action == 'adm_setbio_text':
        bio = text
        
        if len(bio) > 70:
            bot.send_message(chat_id, f"{P_NO} Bio too long! Max 70 characters.")
            return
        
        target = temp_data[uid].get('target', '')
        
        if target == 'all':
            # Get all accounts
            accounts = cur.execute("SELECT phone, session_file FROM stock WHERE available=1").fetchall()
            
            if not accounts:
                bot.send_message(chat_id, f"{P_NO} No accounts in stock!")
                temp_data.pop(uid, None)
                return
            
            bot.send_message(chat_id, f"{P_WAIT} Setting bio for {len(accounts)} accounts... This may take a while.")
            
            # Process each account using a loop with proper async handling
            async def process_all_bios():
                success_count = 0
                fail_count = 0
                
                for phone, session_file in accounts:
                    try:
                        clean_sess = session_file if not session_file.endswith(".session") else session_file[:-8]
                        # Call the async function
                        result = await set_bio_single_async(phone, clean_sess, bio, chat_id)
                        if result:
                            success_count += 1
                        else:
                            fail_count += 1
                        await asyncio.sleep(0.5)  # Rate limit
                    except Exception as e:
                        logger.error(f"Failed to set bio for {phone}: {e}")
                        fail_count += 1
                
                # Send final summary
                bot.send_message(
                    chat_id,
                    f"{P_YES} <b>Bio Update Complete!</b>\n"
                    f"✅ Success: {success_count}\n"
                    f"❌ Failed: {fail_count}\n"
                    f"📊 Total: {len(accounts)}"
                )
            
            # Run the async function
            asyncio.run_coroutine_threadsafe(process_all_bios(), telethon_loop)
            
            temp_data.pop(uid, None)
            
        else:
            # Set bio for specific account
            account = cur.execute("SELECT phone, session_file FROM stock WHERE phone=? AND available=1", (target,)).fetchone()
            
            if not account:
                bot.send_message(chat_id, f"{P_NO} Account {target} not found!")
                temp_data.pop(uid, None)
                return
            
            phone, session_file = account
            clean_sess = session_file if not session_file.endswith(".session") else session_file[:-8]
            
            asyncio.run_coroutine_threadsafe(
                set_bio_single_async(phone, clean_sess, bio, chat_id),
                telethon_loop
            )
            temp_data.pop(uid, None)
# ==================== MASS JOIN HANDLER ====================
def process_admin_massjoin(message):
    """Process Mass Join conversation"""
    uid = message.from_user.id
    chat_id = message.chat.id
    text = message.text.strip()
    
    if text.lower() == '/cancel':
        temp_data.pop(uid, None)
        bot.send_message(chat_id, f"{P_NO} <b>Cancelled.</b>")
        return
    
    if uid not in temp_data:
        return
    
    admin_action = temp_data[uid].get('admin_action')
    
    if admin_action == 'adm_massjoin_channel':
        channel = text
        
        # Store channel
        temp_data[uid]['channel'] = channel
        temp_data[uid]['admin_action'] = 'adm_massjoin_phone'
        
        markup = InlineKeyboardMarkup()
        markup.add(InlineKeyboardButton("❌ Cancel", callback_data="cancel_action"))
        
        bot.send_message(
            chat_id,
            f"{P_PHONE} <b>Enter phone number to join</b>\n"
            f"Type <code>all</code> to join ALL accounts in stock.\n"
            f"Type <code>/cancel</code> to cancel.",
            reply_markup=markup
        )
        return
    
    elif admin_action == 'adm_massjoin_phone':
        target_phone = text
        
        if target_phone.lower() == 'all':
            temp_data[uid]['target'] = 'all'
        else:
            account = cur.execute("SELECT phone FROM stock WHERE phone=? AND available=1", (target_phone,)).fetchone()
            if not account:
                bot.send_message(chat_id, f"{P_NO} Account {target_phone} not found in stock!")
                temp_data.pop(uid, None)
                return
            temp_data[uid]['target'] = target_phone
        
        channel = temp_data[uid].get('channel', '')
        target = temp_data[uid].get('target', '')
        
        # Process mass join
        asyncio.run_coroutine_threadsafe(
            process_massjoin_async(chat_id, uid, channel, target),
            telethon_loop
        )
        temp_data.pop(uid, None)
# ==================== ASYNC HELPERS FOR SET BIO ====================

async def set_bio_single_async(phone, session_path, bio, chat_id):
    """Set bio for a single account"""
    client = None
    try:
        from telethon.tl.functions.account import UpdateProfileRequest
        
        client = TelegramClient(session_path, API_ID, API_HASH)
        await client.connect()
        
        if await client.is_user_authorized():
            # Use UpdateProfileRequest to set bio
            await client(UpdateProfileRequest(about=bio))
            
            bot.send_message(chat_id, f"{P_YES} Bio set for +{phone}:\n\n<code>{bio}</code>")
            await client.disconnect()
            return True
        else:
            bot.send_message(chat_id, f"{P_NO} Account +{phone} is not authorized!")
            await client.disconnect()
            return False
    except Exception as e:
        error_msg = str(e)
        if "about" in error_msg.lower() or "bio" in error_msg.lower():
            bot.send_message(chat_id, f"{P_NO} Failed for +{phone}: Bio may be too long or invalid format.")
        elif "flood" in error_msg.lower():
            bot.send_message(chat_id, f"{P_WARN} Rate limited for +{phone}: Please wait before trying again.")
        else:
            bot.send_message(chat_id, f"{P_NO} Failed for +{phone}: {error_msg}")
        return False
    finally:
        if client:
            try:
                await client.disconnect()
            except:
                pass

async def set_bio_bulk_async(phone, session_path, bio):
    """Set bio for a single account (bulk version)"""
    try:
        client = TelegramClient(session_path, API_ID, API_HASH)
        await client.connect()
        
        if await client.is_user_authorized():
            await client.edit_profile(about=bio)
            logger.info(f"✅ Bio set for +{phone}")
        else:
            logger.warning(f"❌ Account +{phone} not authorized")
            
        await client.disconnect()
        return True
    except Exception as e:
        logger.error(f"Failed to set bio for +{phone}: {e}")
        return False
# ==================== ASYNC HELPERS FOR MASS JOIN ====================
async def process_massjoin_async(chat_id, uid, channel, target):
    """Process mass join using correct Telethon method"""
    try:
        from telethon.tl.functions.channels import JoinChannelRequest
        from telethon.tl.types import InputChannel
        
        # Resolve channel
        if channel.startswith("https://t.me/"):
            channel = channel.replace("https://t.me/", "")
        elif channel.startswith("@"):
            channel = channel[1:]
        
        # Get channel entity
        try:
            channel_entity = await telethon_client.get_entity(channel)
            channel_username = channel
            bot.send_message(chat_id, f"🔍 Found channel: @{channel_username}")
            
            # Get the channel ID for verification
            channel_id = getattr(channel_entity, 'id', None)
            bot.send_message(chat_id, f"📊 Channel ID: {channel_id}")
            
            # Ensure we have the right type
            if hasattr(channel_entity, 'username'):
                bot.send_message(chat_id, f"✅ Channel is accessible")
            else:
                bot.send_message(chat_id, f"{P_WARN} Channel might be private or inaccessible")
                
        except Exception as e:
            bot.send_message(chat_id, f"{P_NO} Cannot find channel: {e}")
            return
        
        if target == 'all':
            accounts = cur.execute("SELECT phone, session_file FROM stock WHERE available=1").fetchall()
            
            if not accounts:
                bot.send_message(chat_id, f"{P_NO} No accounts in stock!")
                return
            
            bot.send_message(chat_id, f"{P_WAIT} Joining {len(accounts)} accounts to channel... This may take a while.")
            
            success = 0
            failed = 0
            already_member = 0
            
            for phone, session_file in accounts:
                client = None
                try:
                    clean_sess = session_file if not session_file.endswith(".session") else session_file[:-8]
                    
                    client = TelegramClient(clean_sess, API_ID, API_HASH)
                    await client.connect()
                    
                    if await client.is_user_authorized():
                        try:
                            # Method 1: Try using the channel username
                            try:
                                await client(JoinChannelRequest(channel_entity))
                                success += 1
                                bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username}")
                            except Exception as e1:
                                # Method 2: Try using the channel username as string
                                try:
                                    await client(JoinChannelRequest(channel_username))
                                    success += 1
                                    bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username}")
                                except Exception as e2:
                                    # Method 3: Try using InputChannel
                                    try:
                                        input_channel = InputChannel(channel_entity.id, channel_entity.access_hash)
                                        await client(JoinChannelRequest(input_channel))
                                        success += 1
                                        bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username}")
                                    except Exception as e3:
                                        raise Exception(f"All join methods failed: {e3}")
                            
                            await asyncio.sleep(0.5)  # Rate limit
                        except Exception as e:
                            error_msg = str(e).lower()
                            if "already a member" in error_msg:
                                already_member += 1
                                bot.send_message(chat_id, f"{P_YES} +{phone} already a member")
                            elif "flood" in error_msg:
                                bot.send_message(chat_id, f"{P_WARN} +{phone} rate limited, waiting...")
                                await asyncio.sleep(2)
                            else:
                                failed += 1
                                bot.send_message(chat_id, f"{P_NO} +{phone} join failed: {e}")
                    else:
                        failed += 1
                        bot.send_message(chat_id, f"{P_NO} +{phone} not authorized")
                        
                except Exception as e:
                    failed += 1
                    bot.send_message(chat_id, f"{P_NO} +{phone} error: {e}")
                finally:
                    if client:
                        try:
                            await client.disconnect()
                        except:
                            pass
            
            bot.send_message(
                chat_id,
                f"{P_YES} <b>📢 Mass Join Complete!</b>\n\n"
                f"✅ Joined: <b>{success}</b>\n"
                f"👥 Already Member: <b>{already_member}</b>\n"
                f"❌ Failed: <b>{failed}</b>\n"
                f"📢 Channel: <b>@{channel_username}</b>"
            )
            
        else:
            # Join specific account
            account = cur.execute("SELECT phone, session_file FROM stock WHERE phone=? AND available=1", (target,)).fetchone()
            
            if not account:
                bot.send_message(chat_id, f"{P_NO} Account {target} not found in stock!")
                return
            
            phone, session_file = account
            clean_sess = session_file if not session_file.endswith(".session") else session_file[:-8]
            client = TelegramClient(clean_sess, API_ID, API_HASH)
            
            try:
                await client.connect()
                if await client.is_user_authorized():
                    try:
                        # Try multiple methods
                        try:
                            await client(JoinChannelRequest(channel_entity))
                            bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username} successfully!")
                        except:
                            try:
                                await client(JoinChannelRequest(channel_username))
                                bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username} successfully!")
                            except:
                                input_channel = InputChannel(channel_entity.id, channel_entity.access_hash)
                                await client(JoinChannelRequest(input_channel))
                                bot.send_message(chat_id, f"{P_YES} +{phone} joined @{channel_username} successfully!")
                    except Exception as e:
                        if "already a member" in str(e).lower():
                            bot.send_message(chat_id, f"{P_YES} +{phone} is already a member of @{channel_username}")
                        else:
                            bot.send_message(chat_id, f"{P_NO} +{phone} failed to join: {e}")
                else:
                    bot.send_message(chat_id, f"{P_NO} Account +{phone} is not authorized!")
            except Exception as e:
                bot.send_message(chat_id, f"{P_NO} Failed: {e}")
            finally:
                await client.disconnect()
                
    except Exception as e:
        bot.send_message(chat_id, f"{P_NO} Error: {e}")
        logger.error(f"Mass join error: {e}")
def manage_admins_menu(call):
    rows = cur.execute("SELECT user_id FROM admins").fetchall()
    msg = f"{P_USERS} <b>Manage Sub-Admins</b>\n\n"
    for r in rows:
        msg += f"{P_ACC} <code>{r[0]}</code>\n"
    markup = InlineKeyboardMarkup(row_width=2)
    markup.add(
        InlineKeyboardButton("Add Admin", callback_data="adm_addadmin"),
        InlineKeyboardButton("Edit Admin", callback_data="adm_editadminreq"),
        InlineKeyboardButton("Back", callback_data="adm_adminmain")
    )
    bot.edit_message_text(msg, call.message.chat.id, call.message.message_id, reply_markup=markup)

def edit_admin_menu(call, target_id):
    row = cur.execute("SELECT p_add_stock, p_manage_stock, p_stats, p_bal, p_settings FROM admins WHERE user_id=?", (target_id,)).fetchone()
    if not row:
        return bot.answer_callback_query(call.id, "Admin not found", show_alert=True)
    p = ["✅" if x == 1 else "❌" for x in row]
    
    markup = InlineKeyboardMarkup(row_width=1)
    markup.add(
        InlineKeyboardButton(f"Add Stock: {p[0]}", callback_data=f"adm_tglperm|{target_id}|p_add_stock"),
        InlineKeyboardButton(f"Manage Stock: {p[1]}", callback_data=f"adm_tglperm|{target_id}|p_manage_stock"),
        InlineKeyboardButton(f"Stats & Bcast: {p[2]}", callback_data=f"adm_tglperm|{target_id}|p_stats"),
        InlineKeyboardButton(f"Bal & Users: {p[3]}", callback_data=f"adm_tglperm|{target_id}|p_bal"),
        InlineKeyboardButton(f"Settings: {p[4]}", callback_data=f"adm_tglperm|{target_id}|p_settings"),
        InlineKeyboardButton(f"Remove Admin", callback_data=f"adm_deladmin|{target_id}"),
        InlineKeyboardButton("Back", callback_data="adm_manageadmins")
    )
    bot.edit_message_text(f"✏️ <b>Editing Admin:</b> <code>{target_id}</code>", call.message.chat.id, call.message.message_id, reply_markup=markup)

def send_manage_stock_page(call, page):
    limit = 10
    offset = (page - 1) * limit
    rows = cur.execute("SELECT DISTINCT country_name FROM stock ORDER BY country_name").fetchall()
    total = len(rows)
    countries = rows[offset:offset+limit]
    
    markup = InlineKeyboardMarkup(row_width=1)
    for (c,) in countries:
        flag = get_flag_by_country_name(c)
        markup.add(InlineKeyboardButton(f"{flag} {c}", callback_data=f"adm_msc|{c}"))
    
    nav_buttons = []
    if page > 1:
        nav_buttons.append(InlineKeyboardButton("Prev", callback_data=f"adm_mspg|{page-1}"))
    if offset + limit < total:
        nav_buttons.append(InlineKeyboardButton("Next", callback_data=f"adm_mspg|{page+1}"))
    if nav_buttons:
        markup.row(*nav_buttons)
    markup.add(InlineKeyboardButton("Back", callback_data="adm_adminmain"))
    bot.edit_message_text(f"{P_DOC} <b>Manage Stock</b> (Page {page})\nSelect a country to edit its properties:", call.message.chat.id, call.message.message_id, reply_markup=markup)

def send_manage_stock_country(call, c_name):
    years = cur.execute("SELECT DISTINCT account_year FROM stock WHERE country_name=? ORDER BY account_year DESC", (c_name,)).fetchall()
    flag = get_flag_by_country_name(c_name)
    markup = InlineKeyboardMarkup(row_width=2)
    markup.add(
        InlineKeyboardButton("Edit Country Name", callback_data=f"adm_msedit|name|{c_name}"),
        InlineKeyboardButton("Edit Flag", callback_data=f"adm_msedit|flag|{c_name}"),
        InlineKeyboardButton("Edit Common Price (All Years)", callback_data=f"adm_msedit|cprice|{c_name}")
    )
    for (y,) in years:
        markup.add(InlineKeyboardButton(f"{y}", callback_data=f"adm_msedit|yprice|{c_name}|{y}"))
    markup.add(InlineKeyboardButton("Back", callback_data="adm_mspg|1"))
    bot.edit_message_text(f"{flag} <b>Managing: {c_name}</b>\nSelect an option to edit:", call.message.chat.id, call.message.message_id, reply_markup=markup)

def send_autoprice_page(call, page):
    limit = 10
    offset = (page - 1) * limit
    c_list = set([c[0] for c in COUNTRY_CODES.values()])
    db_countries = cur.execute("SELECT DISTINCT country_name FROM stock").fetchall()
    for (c,) in db_countries:
        c_list.add(c)
    
    custom_countries = cur.execute("SELECT DISTINCT name FROM custom_countries").fetchall()
    for (c,) in custom_countries:
        c_list.add(c)

    c_list = sorted(list(c_list))
    total = len(c_list)
    countries = c_list[offset:offset+limit]
    
    markup = InlineKeyboardMarkup(row_width=1)
    for c in countries:
        flag = get_flag_by_country_name(c)
        markup.add(InlineKeyboardButton(f"{flag} {c}", callback_data=f"adm_apc|{c}"))
    
    nav_buttons = []
    if page > 1:
        nav_buttons.append(InlineKeyboardButton("Prev", callback_data=f"adm_appg|{page-1}"))
    if offset + limit < total:
        nav_buttons.append(InlineKeyboardButton("Next", callback_data=f"adm_appg|{page+1}"))
    if nav_buttons:
        markup.row(*nav_buttons)
    markup.add(
        InlineKeyboardButton("Add Custom Country", callback_data="adm_ap_add_country"),
        InlineKeyboardButton("Back", callback_data="adm_adminmain")
    )
    bot.edit_message_text(f"{P_ASST} <b>Auto Price Setup</b> (Page {page})\nSelect a country to set fixed prices:", call.message.chat.id, call.message.message_id, reply_markup=markup)

def send_autoprice_country(call, c_name):
    flag = get_flag_by_country_name(c_name)
    markup = InlineKeyboardMarkup(row_width=2)
    markup.add(InlineKeyboardButton("Set Common Price", callback_data=f"adm_apset|{c_name}|Common"))
    for y in range(2024, 1999, -1):
        markup.add(InlineKeyboardButton(f"{y}", callback_data=f"adm_apset|{c_name}|{y}"))
    markup.add(InlineKeyboardButton("Back", callback_data="adm_appg|1"))
    bot.edit_message_text(f"{flag} <b>Auto Price: {c_name}</b>\nSelect 'Common' for default price, or specific years:", call.message.chat.id, call.message.message_id, reply_markup=markup)

# ================= TELEGRAM CLIENT SETUP =================
async def telethon_main():
    global telethon_loop
    telethon_loop = asyncio.get_running_loop()
    
    # Start Telethon client
    await telethon_client.start(bot_token=BOT_TOKEN)
    logger.info("Telethon client started")
    
    # Keep the connection alive
    while True:
        await asyncio.sleep(1)

# ================= MAIN =================
def main():# ================= MAIN =================
    print("✅ HYBRID BOT STARTED (pyTelegramBotAPI + Telethon)")
    
    # Start Telethon in a separate thread
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    
    def run_telethon():
        loop.run_until_complete(telethon_main())
    
    telethon_thread = threading.Thread(target=run_telethon, daemon=True)
    telethon_thread.start()
    
    # Start pyTelegramBotAPI
    print("✅ pyTelegramBotAPI running...")
    bot.infinity_polling(
        logger_level=logging.DEBUG,
        timeout=60,
        long_polling_timeout=30,
        allowed_updates=None
    )

if __name__ == '__main__':
    main()