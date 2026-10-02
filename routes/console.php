<?php
use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring')->dailyAt('00:10')->withoutOverlapping();
