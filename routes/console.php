<?php
use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring')->dailyAt('00:10')->withoutOverlapping();

Schedule::command('nextor:webhooks --limit=25')->everyMinute()->withoutOverlapping();

Schedule::command('nextor:accounting-export')->monthlyOn(1,'01:10')->withoutOverlapping();
