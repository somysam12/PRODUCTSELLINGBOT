import os
import logging
import sqlite3
import json
import asyncio
from datetime import datetime, timedelta
from typing import Dict, List, Optional

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup, Bot
from telegram.ext import (
    Application, CommandHandler, MessageHandler, filters, 
    CallbackQueryHandler, ContextTypes, CallbackContext
)
from telegram.constants import ParseMode

# Configure logging
logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO
)
logger = logging.getLogger(__name__)

# Bot configuration
BOT_TOKEN = os.getenv('BOT_TOKEN')
ADMIN_IDS = list(map(int, os.getenv('ADMIN_IDS', '').split(','))) if os.getenv('ADMIN_IDS') else []

# Database setup
class Database:
    def __init__(self):
        self.conn = sqlite3.connect('bot_database.db', check_same_thread=False)
        self.create_tables()
    
    def create_tables(self):
        cursor = self.conn.cursor()
        
        # Users table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS users (
                user_id INTEGER PRIMARY KEY,
                username TEXT,
                first_name TEXT,
                last_name TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN DEFAULT TRUE
            )
        ''')
        
        # Chats table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS chats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                message_id INTEGER,
                admin_message_id INTEGER,
                message_type TEXT,
                content TEXT,
                is_from_user BOOLEAN,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (user_id)
            )
        ''')
        
        # Trigger messages table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS triggers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT UNIQUE,
                response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Products table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                description TEXT,
                price REAL,
                image_url TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Active chats table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS active_chats (
                user_id INTEGER PRIMARY KEY,
            admin_id INTEGER,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        self.conn.commit()
    
    def add_user(self, user_id: int, username: str, first_name: str, last_name: str = ""):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT OR REPLACE INTO users (user_id, username, first_name, last_name, is_active)
            VALUES (?, ?, ?, ?, TRUE)
        ''', (user_id, username, first_name, last_name))
        self.conn.commit()
    
    def get_user(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM users WHERE user_id = ?', (user_id,))
        return cursor.fetchone()
    
    def get_all_users(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM users ORDER BY created_at DESC')
        return cursor.fetchall()
    
    def add_chat_message(self, user_id: int, message_id: int, admin_message_id: int, 
                        message_type: str, content: str, is_from_user: bool):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT INTO chats (user_id, message_id, admin_message_id, message_type, content, is_from_user)
            VALUES (?, ?, ?, ?, ?, ?)
        ''', (user_id, message_id, admin_message_id, message_type, content, is_from_user))
        self.conn.commit()
    
    def get_user_chats(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('''
            SELECT * FROM chats 
            WHERE user_id = ? 
            ORDER BY timestamp ASC
        ''', (user_id,))
        return cursor.fetchall()
    
    def add_trigger(self, keyword: str, response: str):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT OR REPLACE INTO triggers (keyword, response)
            VALUES (?, ?)
        ''', (keyword, response))
        self.conn.commit()
    
    def get_trigger(self, keyword: str):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM triggers WHERE keyword = ?', (keyword,))
        return cursor.fetchone()
    
    def get_all_triggers(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM triggers ORDER BY keyword')
        return cursor.fetchall()
    
    def delete_trigger(self, keyword: str):
        cursor = self.conn.cursor()
        cursor.execute('DELETE FROM triggers WHERE keyword = ?', (keyword,))
        self.conn.commit()
    
    def add_product(self, name: str, description: str, price: float, image_url: str = ""):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT INTO products (name, description, price, image_url)
            VALUES (?, ?, ?, ?)
        ''', (name, description, price, image_url))
        self.conn.commit()
        return cursor.lastrowid
    
    def get_all_products(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM products ORDER BY created_at DESC')
        return cursor.fetchall()
    
    def set_active_chat(self, user_id: int, admin_id: int):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT OR REPLACE INTO active_chats (user_id, admin_id)
            VALUES (?, ?)
        ''', (user_id, admin_id))
        self.conn.commit()
    
    def get_active_chat(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM active_chats WHERE user_id = ?', (user_id,))
        return cursor.fetchone()
    
    def remove_active_chat(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('DELETE FROM active_chats WHERE user_id = ?', (user_id,))
        self.conn.commit()
    
    def get_all_active_chats(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM active_chats')
        return cursor.fetchall()

# Initialize database
db = Database()

class BotManager:
    def __init__(self):
        self.waiting_users: Dict[int, datetime] = {}
        self.busy_admins: Dict[int, int] = {}  # admin_id: user_id
    
    async def handle_user_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        user = update.effective_user
        message = update.effective_message
        
        # Add user to database
        db.add_user(user.id, user.username, user.first_name, user.last_name)
        
        # Check for triggers
        if message.text:
            triggers = db.get_all_triggers()
            for trigger in triggers:
                if trigger[1].lower() in message.text.lower():
                    await message.reply_text(trigger[2])
                    return
        
        # Check if admin is available
        active_chat = db.get_active_chat(user.id)
        
        if active_chat:
            # Forward to the admin who's handling this chat
            admin_id = active_chat[1]
            await self.forward_to_admin(user, message, admin_id, context)
        else:
            # Check if any admin is available
            available_admin = await self.get_available_admin()
            
            if available_admin:
                db.set_active_chat(user.id, available_admin)
                await self.forward_to_admin(user, message, available_admin, context)
            else:
                # All admins are busy
                if user.id in self.waiting_users:
                    if datetime.now() - self.waiting_users[user.id] < timedelta(minutes=5):
                        await message.reply_text("🕒 Please wait 5 minutes. I'm currently busy with other users.")
                        return
                
                self.waiting_users[user.id] = datetime.now()
                await message.reply_text("⏳ All admins are currently busy. Please wait for 5 minutes and try again.")
    
    async def forward_to_admin(self, user, message, admin_id: int, context: ContextTypes.DEFAULT_TYPE):
        try:
            # Forward the message to admin
            if message.text:
                admin_msg = await context.bot.send_message(
                    chat_id=admin_id,
                    text=f"📩 From: @{user.username or user.id}\n\n{message.text}",
                    reply_markup=self.get_user_reply_keyboard(user.id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "text", message.text, True)
            
            elif message.photo:
                admin_msg = await context.bot.send_photo(
                    chat_id=admin_id,
                    photo=message.photo[-1].file_id,
                    caption=f"📷 From: @{user.username or user.id}",
                    reply_markup=self.get_user_reply_keyboard(user.id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "photo", "Photo", True)
            
            elif message.video:
                admin_msg = await context.bot.send_video(
                    chat_id=admin_id,
                    video=message.video.file_id,
                    caption=f"🎥 From: @{user.username or user.id}",
                    reply_markup=self.get_user_reply_keyboard(user.id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "video", "Video", True)
            
            elif message.voice:
                admin_msg = await context.bot.send_voice(
                    chat_id=admin_id,
                    voice=message.voice.file_id,
                    caption=f"🎤 From: @{user.username or user.id}",
                    reply_markup=self.get_user_reply_keyboard(user.id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "voice", "Voice", True)
            
            elif message.document:
                admin_msg = await context.bot.send_document(
                    chat_id=admin_id,
                    document=message.document.file_id,
                    caption=f"📄 From: @{user.username or user.id}",
                    reply_markup=self.get_user_reply_keyboard(user.id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "document", "Document", True)
        
        except Exception as e:
            logger.error(f"Error forwarding message to admin: {e}")
            await message.reply_text("❌ Error sending message. Please try again.")
    
    def get_user_reply_keyboard(self, user_id: int):
        keyboard = [
            [InlineKeyboardButton("💬 Reply", callback_data=f"reply_{user_id}")],
            [InlineKeyboardButton("❌ End Chat", callback_data=f"end_{user_id}")],
            [InlineKeyboardButton("👀 View Chats", callback_data=f"view_{user_id}")]
        ]
        return InlineKeyboardMarkup(keyboard)
    
    async def get_available_admin(self) -> Optional[int]:
        # Simple round-robin admin assignment
        active_chats = db.get_all_active_chats()
        busy_admins = set(chat[1] for chat in active_chats)
        
        for admin_id in ADMIN_IDS:
            if admin_id not in busy_admins:
                return admin_id
        return None
    
    async def handle_admin_reply(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        query = update.callback_query
        await query.answer()
        
        data = query.data
        user_id = int(data.split('_')[1])
        
        if data.startswith('reply_'):
            context.user_data['replying_to'] = user_id
            await query.edit_message_text(
                f"💬 Replying to user {user_id}. Send your message now:"
            )
        
        elif data.startswith('end_'):
            db.remove_active_chat(user_id)
            await context.bot.send_message(
                chat_id=user_id,
                text="✅ Chat session ended. Thank you for contacting us!"
            )
            await query.edit_message_text("✅ Chat ended successfully")
        
        elif data.startswith('view_'):
            chats = db.get_user_chats(user_id)
            chat_history = "📋 Chat History:\n\n"
            
            for chat in chats:
                timestamp = chat[7]
                sender = "User" if chat[6] else "Admin"
                chat_history += f"{timestamp} - {sender}: {chat[5]}\n"
            
            await query.edit_message_text(chat_history[:4000])  # Telegram message limit
    
    async def process_admin_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        if update.effective_user.id not in ADMIN_IDS:
            return
        
        if 'replying_to' in context.user_data:
            user_id = context.user_data['replying_to']
            message = update.effective_message
            
            try:
                # Forward admin's message to user
                if message.text:
                    await context.bot.send_message(chat_id=user_id, text=message.text)
                    db.add_chat_message(user_id, message.message_id, message.message_id, 
                                      "text", message.text, False)
                
                elif message.photo:
                    await context.bot.send_photo(
                        chat_id=user_id, 
                        photo=message.photo[-1].file_id,
                        caption=message.caption
                    )
                    db.add_chat_message(user_id, message.message_id, message.message_id, 
                                      "photo", "Photo", False)
                
                elif message.video:
                    await context.bot.send_video(
                        chat_id=user_id,
                        video=message.video.file_id,
                        caption=message.caption
                    )
                    db.add_chat_message(user.id, message.message_id, message.message_id, 
                                      "video", "Video", False)
                
                elif message.voice:
                    await context.bot.send_voice(
                        chat_id=user_id,
                        voice=message.voice.file_id
                    )
                    db.add_chat_message(user_id, message.message_id, message.message_id, 
                                      "voice", "Voice", False)
                
                await update.message.reply_text("✅ Message sent successfully!")
                # Keep replying to same user until explicitly ended
                
            except Exception as e:
                logger.error(f"Error sending message to user: {e}")
                await update.message.reply_text("❌ Failed to send message to user.")

# Initialize bot manager
bot_manager = BotManager()

# Command handlers
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    db.add_user(user.id, user.username, user.first_name, user.last_name)
    
    welcome_text = """
🤖 Welcome to our Store Bot!

I'm here to help you with our products and services. 
Feel free to ask any questions or type your requirements.

Our admin will respond to you shortly.
    """
    
    await update.message.reply_text(welcome_text)

async def admin_panel(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        await update.message.reply_text("❌ Access denied.")
        return
    
    keyboard = [
        [InlineKeyboardButton("👥 All Users", callback_data="admin_users")],
        [InlineKeyboardButton("⚡ Add Trigger", callback_data="admin_add_trigger")],
        [InlineKeyboardButton("📋 View Triggers", callback_data="admin_view_triggers")],
        [InlineKeyboardButton("📦 Add Product", callback_data="admin_add_product")],
        [InlineKeyboardButton("🛍️ View Products", callback_data="admin_view_products")],
        [InlineKeyboardButton("💬 Active Chats", callback_data="admin_active_chats")],
        [InlineKeyboardButton("📊 View User Chats", callback_data="admin_view_user_chats")]
    ]
    
    reply_markup = InlineKeyboardMarkup(keyboard)
    await update.message.reply_text("🛠️ Admin Panel:", reply_markup=reply_markup)

async def handle_admin_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    
    if query.data == "admin_users":
        users = db.get_all_users()
        users_list = "👥 All Users:\n\n"
        for user in users:
            users_list += f"ID: {user[0]}\nUsername: @{user[1]}\nName: {user[2]}\nJoined: {user[4]}\n\n"
        
        await query.edit_message_text(users_list[:4000])
    
    elif query.data == "admin_add_trigger":
        context.user_data['awaiting_trigger'] = True
        await query.edit_message_text("Send trigger keyword and response in format:\n`/keyword Response message`")
    
    elif query.data == "admin_view_triggers":
        triggers = db.get_all_triggers()
        triggers_list = "⚡ Trigger Messages:\n\n"
        for trigger in triggers:
            triggers_list += f"Keyword: {trigger[1]}\nResponse: {trigger[2]}\n\n"
        
        if not triggers:
            triggers_list = "No triggers set yet."
        
        await query.edit_message_text(triggers_list)
    
    elif query.data == "admin_add_product":
        context.user_data['awaiting_product'] = True
        await query.edit_message_text("Send product details in format:\n`Product Name|Description|Price|Image_URL(optional)`")
    
    elif query.data == "admin_view_products":
        products = db.get_all_products()
        products_list = "📦 Products:\n\n"
        for product in products:
            products_list += f"Name: {product[1]}\nDesc: {product[2]}\nPrice: ${product[3]}\n\n"
        
        if not products:
            products_list = "No products added yet."
        
        await query.edit_message_text(products_list)
    
    elif query.data == "admin_active_chats":
        active_chats = db.get_all_active_chats()
        active_list = "💬 Active Chats:\n\n"
        for chat in active_chats:
            active_list += f"User ID: {chat[0]}\nAdmin ID: {chat[1]}\n\n"
        
        if not active_chats:
            active_list = "No active chats."
        
        await query.edit_message_text(active_list)
    
    elif query.data == "admin_view_user_chats":
        context.user_data['awaiting_user_for_chats'] = True
        await query.edit_message_text("Send user ID to view their chats:")

async def handle_admin_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    message = update.effective_message
    
    if 'awaiting_trigger' in context.user_data:
        try:
            parts = message.text.split(' ', 1)
            if len(parts) >= 2:
                keyword = parts[0].replace('/', '')
                response = parts[1]
                db.add_trigger(keyword, response)
                await message.reply_text(f"✅ Trigger added:\nKeyword: {keyword}\nResponse: {response}")
            else:
                await message.reply_text("❌ Invalid format. Use: `/keyword Response message`")
        except Exception as e:
            await message.reply_text(f"❌ Error: {e}")
        finally:
            context.user_data.pop('awaiting_trigger', None)
    
    elif 'awaiting_product' in context.user_data:
        try:
            parts = message.text.split('|')
            if len(parts) >= 3:
                name = parts[0].strip()
                description = parts[1].strip()
                price = float(parts[2].strip())
                image_url = parts[3].strip() if len(parts) > 3 else ""
                
                product_id = db.add_product(name, description, price, image_url)
                await message.reply_text(f"✅ Product added:\nID: {product_id}\nName: {name}\nPrice: ${price}")
            else:
                await message.reply_text("❌ Invalid format. Use: `Product Name|Description|Price|Image_URL`")
        except Exception as e:
            await message.reply_text(f"❌ Error: {e}")
        finally:
            context.user_data.pop('awaiting_product', None)
    
    elif 'awaiting_user_for_chats' in context.user_data:
        try:
            user_id = int(message.text)
            chats = db.get_user_chats(user_id)
            chat_history = f"📋 Chat History for User {user_id}:\n\n"
            
            for chat in chats:
                timestamp = chat[7]
                message_type = "📸" if chat[4] == "photo" else "🎥" if chat[4] == "video" else "💬"
                sender = "👤 User" if chat[6] else "🛠️ Admin"
                content = chat[5]
                chat_history += f"{timestamp} {message_type} {sender}: {content}\n"
            
            if not chats:
                chat_history = f"No chat history found for user {user_id}"
            
            await message.reply_text(chat_history[:4000])
        except ValueError:
            await message.reply_text("❌ Invalid user ID")
        except Exception as e:
            await message.reply_text(f"❌ Error: {e}")
        finally:
            context.user_data.pop('awaiting_user_for_chats', None)

def main():
    # Create application
    application = Application.builder().token(BOT_TOKEN).build()
    
    # Add handlers
    application.add_handler(CommandHandler("start", start))
    application.add_handler(CommandHandler("admin", admin_panel))
    
    # Admin callback handlers
    application.add_handler(CallbackQueryHandler(handle_admin_callback, pattern="^admin_"))
    application.add_handler(CallbackQueryHandler(bot_manager.handle_admin_reply, pattern="^(reply|end|view)_"))
    
    # Message handlers
    application.add_handler(MessageHandler(
        filters.TEXT & ~filters.COMMAND & ~filters.User(ADMIN_IDS), 
        bot_manager.handle_user_message
    ))
    application.add_handler(MessageHandler(
        (filters.PHOTO | filters.VIDEO | filters.VOICE | filters.DOCUMENT) & ~filters.User(ADMIN_IDS),
        bot_manager.handle_user_message
    ))
    
    # Admin message handlers
    application.add_handler(MessageHandler(
        filters.TEXT & filters.User(ADMIN_IDS) & ~filters.COMMAND,
        bot_manager.process_admin_message
    ))
    application.add_handler(MessageHandler(
        (filters.PHOTO | filters.VIDEO | filters.VOICE | filters.DOCUMENT) & filters.User(ADMIN_IDS),
        bot_manager.process_admin_message
    ))
    application.add_handler(MessageHandler(
        filters.TEXT & filters.User(ADMIN_IDS),
        handle_admin_message
    ))
    
    # Start the bot
    if os.getenv('RENDER'):
        # For Render deployment
        port = int(os.environ.get('PORT', 8443))
        application.run_webhook(
            listen="0.0.0.0",
            port=port,
            url_path=BOT_TOKEN,
            webhook_url=f"https://{os.getenv('RENDER_SERVICE_NAME')}.onrender.com/{BOT_TOKEN}"
        )
    else:
        # For local development
        application.run_polling()

if __name__ == '__main__':
    main()
