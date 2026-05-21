# USDT Withdrawal System with Pass Management

A complete PHP/MySQL application for managing USDT withdrawals, deposits, and pass generation with TRON blockchain integration.

## Features

- 🔐 Secure PHP Login/Register System
- 💰 USDT Deposit Detection
- 💸 Withdrawal API with TRON Integration
- 👨‍💼 Admin Dashboard
- 🎟️ Pass Generation with Auto-Increment
- 📱 Mobile Responsive UI
- ⚙️ Auto Withdrawal Cron System
- 🔄 QR Code Generator for TRON Addresses
- 💾 Complete MySQL Database Schema

## Project Structure

```
mydb/
├── config/
│   └── database.php
├── sql/
│   └── schema.sql
├── public/
│   ├── index.php
│   ├── dashboard/
│   ├── admin/
│   ├── api/
│   ├── css/
│   ├── js/
│   └── images/
├── includes/
│   ├── auth.php
│   ├── wallet.php
│   ├── withdrawal.php
│   ├── pass.php
│   └── functions.php
├── cron/
│   └── auto_withdrawal.php
└── .htaccess
```

## Installation

1. Extract files to `C:\xampp\htdocs\mydb`
2. Import `sql/schema.sql` into MySQL
3. Configure `config/database.php` with your database credentials
4. Access the application at `http://localhost/mydb`

## Requirements

- PHP 7.4+
- MySQL 5.7+
- XAMPP
- TRON API Key (from Trongrid)

## Default Credentials

- Admin: admin@system.com / Admin@123
- User Registration: Available on Login Page

## License

Private - SaidKhan166
