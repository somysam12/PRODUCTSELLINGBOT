# Telegram Bot Project

## Overview
This is a Telegram customer support bot that enables communication between users and admins. It provides automated triggers, product management, and chat history tracking.

## Features
- User-admin messaging system
- Automated trigger responses
- Product catalog management
- Chat history tracking
- SQLite database for persistence
- Admin panel for bot management

## Tech Stack
- Python 3.11
- python-telegram-bot library (v20.7)
- SQLite database
- python-dotenv for environment management

## Project Structure
- `main.py` - Main bot application with all handlers and database logic
- `requirements.txt` - Python dependencies
- `render.yaml` - Deployment configuration
- `bot_database.db` - SQLite database (created at runtime)

## Environment Variables Required
- `BOT_TOKEN` - Telegram bot token from @BotFather
- `ADMIN_IDS` - Comma-separated list of Telegram user IDs who have admin access

## Setup Date
- October 18, 2025 - Initial Replit import and configuration

## Recent Changes
- Fixed bug on line 375: Changed `user.id` to `user_id` in video message handling
- Installed Python 3.11 and required dependencies
- Created .gitignore for Python project
