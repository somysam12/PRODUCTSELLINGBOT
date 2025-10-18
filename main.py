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

logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO
)
logger = logging.getLogger(__name__)

BOT_TOKEN = os.getenv('BOT_TOKEN')
ADMIN_IDS = list(map(int, os.getenv('ADMIN_IDS', '').split(','))) if os.getenv('ADMIN_IDS') else []

class Database:
    def __init__(self):
        self.conn = sqlite3.connect('bot_database.db', check_same_thread=False)
        self.create_tables()
    
    def create_tables(self):
        cursor = self.conn.cursor()
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS users (
                user_id INTEGER PRIMARY KEY,
                username TEXT,
                first_name TEXT,
                last_name TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN DEFAULT TRUE,
                last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
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
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS triggers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT UNIQUE,
                response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS quick_replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS pending_users (
                user_id INTEGER PRIMARY KEY,
                first_message TEXT,
                waiting_since TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                message_count INTEGER DEFAULT 1
            )
        ''')
        
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
            INSERT OR REPLACE INTO users (user_id, username, first_name, last_name, is_active, last_message_at)
            VALUES (?, ?, ?, ?, TRUE, CURRENT_TIMESTAMP)
        ''', (user_id, username, first_name, last_name))
        self.conn.commit()
    
    def get_user(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM users WHERE user_id = ?', (user_id,))
        return cursor.fetchone()
    
    def add_chat_message(self, user_id: int, message_id: int, admin_message_id: int, 
                        message_type: str, content: str, is_from_user: bool):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT INTO chats (user_id, message_id, admin_message_id, message_type, content, is_from_user)
            VALUES (?, ?, ?, ?, ?, ?)
        ''', (user_id, message_id, admin_message_id, message_type, content, is_from_user))
        self.conn.commit()
    
    def get_user_chats(self, user_id: int, limit: int = 10):
        cursor = self.conn.cursor()
        cursor.execute('''
            SELECT * FROM chats 
            WHERE user_id = ? 
            ORDER BY timestamp DESC
            LIMIT ?
        ''', (user_id, limit))
        return cursor.fetchall()
    
    def add_trigger(self, keyword: str, response: str):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT OR REPLACE INTO triggers (keyword, response)
            VALUES (?, ?)
        ''', (keyword, response))
        self.conn.commit()
    
    def get_all_triggers(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM triggers ORDER BY keyword')
        return cursor.fetchall()
    
    def add_quick_reply(self, title: str, message: str):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT INTO quick_replies (title, message)
            VALUES (?, ?)
        ''', (title, message))
        self.conn.commit()
        return cursor.lastrowid
    
    def get_all_quick_replies(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM quick_replies ORDER BY title')
        return cursor.fetchall()
    
    def get_quick_reply(self, qr_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM quick_replies WHERE id = ?', (qr_id,))
        return cursor.fetchone()
    
    def delete_quick_reply(self, qr_id: int):
        cursor = self.conn.cursor()
        cursor.execute('DELETE FROM quick_replies WHERE id = ?', (qr_id,))
        self.conn.commit()
    
    def add_pending_user(self, user_id: int, first_message: str):
        cursor = self.conn.cursor()
        cursor.execute('''
            INSERT INTO pending_users (user_id, first_message, message_count)
            VALUES (?, ?, 1)
            ON CONFLICT(user_id) DO UPDATE SET message_count = message_count + 1
        ''', (user_id, first_message))
        self.conn.commit()
    
    def get_pending_user(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM pending_users WHERE user_id = ?', (user_id,))
        return cursor.fetchone()
    
    def get_pending_users(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM pending_users ORDER BY waiting_since ASC')
        return cursor.fetchall()
    
    def remove_pending_user(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('DELETE FROM pending_users WHERE user_id = ?', (user_id,))
        self.conn.commit()
    
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
    
    def get_active_chat_by_admin(self, admin_id: int):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM active_chats WHERE admin_id = ? ORDER BY started_at DESC', (admin_id,))
        return cursor.fetchall()
    
    def remove_active_chat(self, user_id: int):
        cursor = self.conn.cursor()
        cursor.execute('DELETE FROM active_chats WHERE user_id = ?', (user_id,))
        self.conn.commit()
    
    def get_all_active_chats(self):
        cursor = self.conn.cursor()
        cursor.execute('SELECT * FROM active_chats')
        return cursor.fetchall()

db = Database()

class BotManager:
    def __init__(self):
        self.admin_current_user: Dict[int, int] = {}
    
    async def handle_user_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        user = update.effective_user
        message = update.effective_message
        
        db.add_user(user.id, user.username or "", user.first_name or "", user.last_name or "")
        
        if message.text:
            triggers = db.get_all_triggers()
            for trigger in triggers:
                if trigger[1].lower() in message.text.lower():
                    await message.reply_text(trigger[2])
                    return
        
        active_chat = db.get_active_chat(user.id)
        
        if active_chat:
            admin_id = active_chat[1]
            await self.forward_to_admin(user, message, admin_id, context)
        else:
            message_text = message.text or message.caption or f"{message.content_type}"
            db.add_pending_user(user.id, message_text[:100])
            
            for admin_id in ADMIN_IDS:
                try:
                    pending_count = len(db.get_pending_users())
                    await context.bot.send_message(
                        chat_id=admin_id,
                        text=f"🔔 New message from @{user.username or user.first_name}!\n\n"
                             f"📊 Total pending users: {pending_count}\n\n"
                             f"Use /queue to see all pending users."
                    )
                except Exception as e:
                    logger.error(f"Error notifying admin {admin_id}: {e}")
    
    async def forward_to_admin(self, user, message, admin_id: int, context: ContextTypes.DEFAULT_TYPE):
        try:
            user_info = f"👤 {user.first_name} (@{user.username or user.id})"
            
            if message.text:
                admin_msg = await context.bot.send_message(
                    chat_id=admin_id,
                    text=f"{user_info}\n\n💬 {message.text}",
                    reply_markup=self.get_chat_keyboard(user.id, admin_id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "text", message.text, True)
            
            elif message.photo:
                admin_msg = await context.bot.send_photo(
                    chat_id=admin_id,
                    photo=message.photo[-1].file_id,
                    caption=f"{user_info}\n\n📷 Photo" + (f": {message.caption}" if message.caption else ""),
                    reply_markup=self.get_chat_keyboard(user.id, admin_id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "photo", message.caption or "Photo", True)
            
            elif message.video:
                admin_msg = await context.bot.send_video(
                    chat_id=admin_id,
                    video=message.video.file_id,
                    caption=f"{user_info}\n\n🎥 Video" + (f": {message.caption}" if message.caption else ""),
                    reply_markup=self.get_chat_keyboard(user.id, admin_id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "video", message.caption or "Video", True)
            
            elif message.voice:
                admin_msg = await context.bot.send_voice(
                    chat_id=admin_id,
                    voice=message.voice.file_id,
                    caption=f"{user_info}\n\n🎤 Voice message",
                    reply_markup=self.get_chat_keyboard(user.id, admin_id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "voice", "Voice", True)
            
            elif message.document:
                admin_msg = await context.bot.send_document(
                    chat_id=admin_id,
                    document=message.document.file_id,
                    caption=f"{user_info}\n\n📄 Document" + (f": {message.caption}" if message.caption else ""),
                    reply_markup=self.get_chat_keyboard(user.id, admin_id)
                )
                db.add_chat_message(user.id, message.message_id, admin_msg.message_id, 
                                  "document", message.caption or "Document", True)
        
        except Exception as e:
            logger.error(f"Error forwarding message to admin: {e}")
    
    def get_chat_keyboard(self, user_id: int, admin_id: int):
        admin_chats = db.get_active_chat_by_admin(admin_id)
        user_ids = [chat[0] for chat in admin_chats]
        
        current_index = user_ids.index(user_id) if user_id in user_ids else 0
        
        keyboard = []
        
        nav_row = []
        if len(user_ids) > 1:
            if current_index > 0:
                nav_row.append(InlineKeyboardButton("⬅️ Previous", callback_data=f"nav_prev_{user_id}"))
            if current_index < len(user_ids) - 1:
                nav_row.append(InlineKeyboardButton("➡️ Next", callback_data=f"nav_next_{user_id}"))
        
        if nav_row:
            keyboard.append(nav_row)
        
        keyboard.append([
            InlineKeyboardButton("💬 Reply", callback_data=f"reply_{user_id}"),
            InlineKeyboardButton("⚡ Quick Reply", callback_data=f"quick_{user_id}")
        ])
        keyboard.append([
            InlineKeyboardButton("📋 Chat History", callback_data=f"history_{user_id}"),
            InlineKeyboardButton("❌ End Chat", callback_data=f"end_{user_id}")
        ])
        
        return InlineKeyboardMarkup(keyboard)
    
    async def handle_admin_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        query = update.callback_query
        await query.answer()
        
        data = query.data
        parts = data.split('_')
        action = parts[0]
        
        if action == "reply":
            user_id = int(parts[1])
            context.user_data['replying_to'] = user_id
            self.admin_current_user[query.from_user.id] = user_id
            await query.edit_message_text(
                f"💬 Now replying to user {user_id}.\n\nType your message:"
            )
        
        elif action == "quick":
            user_id = int(parts[1])
            quick_replies = db.get_all_quick_replies()
            
            if not quick_replies:
                await query.edit_message_text("No quick replies set. Use /addquick to add one.")
                return
            
            keyboard = []
            for qr in quick_replies:
                keyboard.append([InlineKeyboardButton(
                    qr[1],
                    callback_data=f"sendquick_{user_id}_{qr[0]}"
                )])
            keyboard.append([InlineKeyboardButton("« Back", callback_data=f"back_{user_id}")])
            
            await query.edit_message_text(
                "Select a quick reply:",
                reply_markup=InlineKeyboardMarkup(keyboard)
            )
        
        elif action == "sendquick":
            user_id = int(parts[1])
            qr_id = int(parts[2])
            quick_reply = db.get_quick_reply(qr_id)
            
            if quick_reply:
                try:
                    await context.bot.send_message(chat_id=user_id, text=quick_reply[2])
                    db.add_chat_message(user_id, 0, 0, "text", quick_reply[2], False)
                    await query.edit_message_text(f"✅ Quick reply sent to user {user_id}!")
                except Exception as e:
                    await query.edit_message_text(f"❌ Error: {e}")
        
        elif action == "history":
            user_id = int(parts[1])
            chats = db.get_user_chats(user_id, 20)
            
            history_text = f"📋 Recent chat history with {user_id}:\n\n"
            for chat in reversed(chats):
                sender = "👤 User" if chat[6] else "🛠️ Admin"
                history_text += f"{sender}: {chat[5][:50]}...\n"
            
            await query.edit_message_text(history_text[:4000])
        
        elif action == "end":
            user_id = int(parts[1])
            db.remove_active_chat(user_id)
            db.remove_pending_user(user_id)
            
            try:
                await context.bot.send_message(
                    chat_id=user_id,
                    text="Thanks for chatting! Feel free to message again anytime. 👋"
                )
            except:
                pass
            
            await query.edit_message_text(f"✅ Chat with user {user_id} ended.")
        
        elif action == "nav":
            direction = parts[1]
            current_user_id = int(parts[2])
            admin_id = query.from_user.id
            
            admin_chats = db.get_active_chat_by_admin(admin_id)
            user_ids = [chat[0] for chat in admin_chats]
            
            if current_user_id in user_ids:
                current_index = user_ids.index(current_user_id)
                
                if direction == "prev" and current_index > 0:
                    new_user_id = user_ids[current_index - 1]
                elif direction == "next" and current_index < len(user_ids) - 1:
                    new_user_id = user_ids[current_index + 1]
                else:
                    await query.answer("No more chats in that direction!")
                    return
                
                self.admin_current_user[admin_id] = new_user_id
                user_info = db.get_user(new_user_id)
                
                await query.edit_message_text(
                    f"Switched to chat with:\n👤 {user_info[2]} (@{user_info[1] or new_user_id})\n\n"
                    f"Active chat {current_index + 1}/{len(user_ids)}",
                    reply_markup=self.get_chat_keyboard(new_user_id, admin_id)
                )
        
        elif action == "back":
            user_id = int(parts[1])
            admin_id = query.from_user.id
            await query.edit_message_text(
                f"Chat with user {user_id}",
                reply_markup=self.get_chat_keyboard(user_id, admin_id)
            )
        
        elif action == "select":
            user_id = int(parts[1])
            admin_id = query.from_user.id
            
            pending_user = db.get_pending_user(user_id)
            
            db.set_active_chat(user_id, admin_id)
            db.remove_pending_user(user_id)
            self.admin_current_user[admin_id] = user_id
            
            user_info = db.get_user(user_id)
            
            if pending_user and pending_user[1]:
                await context.bot.send_message(
                    chat_id=admin_id,
                    text=f"👤 {user_info[2]} (@{user_info[1] or user_id})\n\n💬 {pending_user[1]}",
                    reply_markup=self.get_chat_keyboard(user_id, admin_id)
                )
                await query.edit_message_text(
                    f"✅ Started chat with {user_info[2]}. Their message is shown above."
                )
            else:
                await query.edit_message_text(
                    f"✅ Now chatting with:\n👤 {user_info[2]} (@{user_info[1] or user_id})\n\n"
                    f"Waiting for messages...",
                    reply_markup=self.get_chat_keyboard(user_id, admin_id)
                )
    
    async def process_admin_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        if update.effective_user.id not in ADMIN_IDS:
            return
        
        admin_id = update.effective_user.id
        message = update.effective_message
        
        user_id = context.user_data.get('replying_to') or self.admin_current_user.get(admin_id)
        
        if not user_id:
            return
        
        try:
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
                                  "photo", message.caption or "Photo", False)
            
            elif message.video:
                await context.bot.send_video(
                    chat_id=user_id,
                    video=message.video.file_id,
                    caption=message.caption
                )
                db.add_chat_message(user_id, message.message_id, message.message_id, 
                                  "video", message.caption or "Video", False)
            
            elif message.voice:
                await context.bot.send_voice(
                    chat_id=user_id,
                    voice=message.voice.file_id
                )
                db.add_chat_message(user_id, message.message_id, message.message_id, 
                                  "voice", "Voice", False)
            
            await update.message.reply_text("✅ Message sent!")
            
        except Exception as e:
            logger.error(f"Error sending message to user: {e}")
            await update.message.reply_text(f"❌ Failed to send: {e}")

bot_manager = BotManager()

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    db.add_user(user.id, user.username or "", user.first_name or "", user.last_name or "")
    
    await update.message.reply_text(
        "Hi! I'm here to help. Just send me your message and I'll connect you with support. 💬"
    )

async def queue(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    pending = db.get_pending_users()
    
    if not pending:
        await update.message.reply_text("✅ No pending users!")
        return
    
    queue_text = f"📊 {len(pending)} user(s) waiting:\n\n"
    keyboard = []
    
    for user_data in pending:
        user_id = user_data[0]
        first_msg = user_data[1]
        msg_count = user_data[3]
        
        user_info = db.get_user(user_id)
        name = user_info[2] if user_info else "Unknown"
        username = user_info[1] if user_info else ""
        
        preview = first_msg[:50] + "..." if len(first_msg) > 50 else first_msg
        queue_text += f"👤 {name} (@{username or user_id})\n"
        queue_text += f"💬 {preview}\n"
        queue_text += f"📊 {msg_count} message(s)\n\n"
        
        keyboard.append([InlineKeyboardButton(
            f"👤 {name} - {msg_count} msg(s)",
            callback_data=f"select_{user_id}"
        )])
    
    await update.message.reply_text(
        queue_text,
        reply_markup=InlineKeyboardMarkup(keyboard)
    )

async def addquick(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    context.user_data['awaiting_quick_reply'] = True
    await update.message.reply_text(
        "Add a quick reply in this format:\n\n"
        "`Title|Message text`\n\n"
        "Example: `Greeting|Hello! How can I help you today?`"
    )

async def listquick(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    quick_replies = db.get_all_quick_replies()
    
    if not quick_replies:
        await update.message.reply_text("No quick replies set yet. Use /addquick to add one.")
        return
    
    text = "⚡ Quick Replies:\n\n"
    for qr in quick_replies:
        text += f"ID {qr[0]}: {qr[1]}\n{qr[2]}\n\n"
    
    await update.message.reply_text(text)

async def admin(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    active_chats = db.get_all_active_chats()
    pending_users = db.get_pending_users()
    
    stats_text = f"""
🛠️ Admin Dashboard

📊 Statistics:
• Active chats: {len(active_chats)}
• Pending users: {len(pending_users)}

📝 Commands:
/queue - View pending users
/addquick - Add quick reply
/listquick - View quick replies
/admin - This panel
"""
    
    await update.message.reply_text(stats_text)

async def handle_admin_text(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id not in ADMIN_IDS:
        return
    
    if context.user_data.get('awaiting_quick_reply'):
        try:
            text = update.message.text
            parts = text.split('|', 1)
            
            if len(parts) >= 2:
                title = parts[0].strip()
                message = parts[1].strip()
                qr_id = db.add_quick_reply(title, message)
                await update.message.reply_text(f"✅ Quick reply added (ID: {qr_id})")
            else:
                await update.message.reply_text("❌ Invalid format. Use: `Title|Message`")
        except Exception as e:
            await update.message.reply_text(f"❌ Error: {e}")
        finally:
            context.user_data.pop('awaiting_quick_reply', None)
    else:
        await bot_manager.process_admin_message(update, context)

def main():
    application = Application.builder().token(BOT_TOKEN).build()
    
    application.add_handler(CommandHandler("start", start))
    application.add_handler(CommandHandler("queue", queue))
    application.add_handler(CommandHandler("admin", admin))
    application.add_handler(CommandHandler("addquick", addquick))
    application.add_handler(CommandHandler("listquick", listquick))
    
    application.add_handler(CallbackQueryHandler(bot_manager.handle_admin_callback))
    
    application.add_handler(MessageHandler(
        filters.TEXT & ~filters.COMMAND & ~filters.User(ADMIN_IDS), 
        bot_manager.handle_user_message
    ))
    application.add_handler(MessageHandler(
        (filters.PHOTO | filters.VIDEO | filters.VOICE | filters.Document.ALL) & ~filters.User(ADMIN_IDS),
        bot_manager.handle_user_message
    ))
    
    application.add_handler(MessageHandler(
        filters.TEXT & filters.User(ADMIN_IDS) & ~filters.COMMAND,
        handle_admin_text
    ))
    application.add_handler(MessageHandler(
        (filters.PHOTO | filters.VIDEO | filters.VOICE | filters.Document.ALL) & filters.User(ADMIN_IDS),
        bot_manager.process_admin_message
    ))
    
    if os.getenv('RENDER'):
        port = int(os.environ.get('PORT', 8443))
        application.run_webhook(
            listen="0.0.0.0",
            port=port,
            url_path=BOT_TOKEN,
            webhook_url=f"https://{os.getenv('RENDER_SERVICE_NAME')}.onrender.com/{BOT_TOKEN}"
        )
    else:
        application.run_polling()

if __name__ == '__main__':
    main()
