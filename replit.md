# Telegram Bot Project

## Overview
Advanced Telegram customer support bot with natural messaging experience, admin queue management, and quick replies.

## Key Features

### User Experience
- **Direct Messaging**: Users can message bot directly without /start button - feels like talking to a real person
- **Natural Flow**: No commands required, just send a message and get connected to support
- **Media Support**: Send text, photos, videos, voice messages, and documents

### Admin Features
- **Pending Users Queue** (`/queue`): See all waiting users with message previews and counts
- **User Selection**: Admin can choose which user to handle first when multiple users are waiting
- **Navigation Controls**: Switch between active chats using Previous/Next buttons
- **Quick Replies**: Create and use template messages for common responses
- **Chat History**: View conversation history with any user
- **Multi-Admin Support**: Multiple admins can handle different users simultaneously

### Management Tools
- **Trigger Messages**: Auto-respond to keywords
- **Admin Dashboard** (`/admin`): View statistics and active chats
- **Quick Reply Management**: Add, view, and use quick reply templates

## Tech Stack
- Python 3.11
- python-telegram-bot library (v20.7)
- SQLite database with advanced schema
- Async/await for concurrent handling

## Project Structure
- `main.py` - Complete bot application with all handlers
- `requirements.txt` - Python dependencies
- `render.yaml` - Deployment configuration
- `bot_database.db` - SQLite database (auto-created)
- `.gitignore` - Python gitignore rules

## Environment Variables
- `BOT_TOKEN` - Telegram bot token from @BotFather
- `ADMIN_IDS` - Comma-separated list of admin Telegram user IDs

## Admin Commands
- `/start` - Welcome message (optional for users)
- `/queue` - View and select from pending users
- `/admin` - Admin dashboard with statistics
- `/addquick` - Add a new quick reply template
- `/listquick` - View all quick reply templates

## How It Works

### For Users
1. Open bot and send a message (no /start needed)
2. Message goes to pending queue
3. When admin accepts, conversation begins
4. Chat naturally with admin until session ends

### For Admins
1. Get notification when user messages
2. Use `/queue` to see all pending users with message previews
3. Select a user to start chatting
4. See user's initial message immediately
5. Reply normally or use quick replies
6. Navigate between multiple active chats with Previous/Next buttons
7. End chat when conversation is complete

## Database Schema
- `users` - User profiles and metadata
- `chats` - Full message history
- `triggers` - Auto-response keywords
- `quick_replies` - Admin message templates
- `pending_users` - Queue of users waiting for admin
- `active_chats` - Currently active conversations

## Recent Changes (October 18, 2025)
- ✅ Complete bot rewrite with queue system
- ✅ Added pending users management
- ✅ Implemented message preview in queue
- ✅ Added navigation buttons for multi-chat handling
- ✅ Created quick reply system
- ✅ Fixed message counter bug
- ✅ Improved user selection flow
- ✅ Enhanced admin workflow

## Notes
- Bot runs in polling mode for development
- Supports webhook mode for production (Render deployment)
- All admin IDs must be configured in environment secrets
- Database persists across restarts
