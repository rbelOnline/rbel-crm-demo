@echo off
REM RBEL-CRM scheduler: runs the daily email automations (birthday greetings, payment reminders).
REM Keep this window open. It checks every 5 minutes; each automation sends once its send time has passed.
REM For unattended use, add a Windows Task Scheduler task that runs "php artisan schedule:run" every minute instead.
cd /d "%~dp0"
php artisan schedule:work
