<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A long purge on a large trash must not be joined by the next day's run, so
// the schedule holds withoutOverlapping(). The command also takes its own
// cache lock, which is what turns away a second scheduler on another node;
// onOneServer() is left off here because it throws on a cache store without
// lock support and this file is yours to edit. Add ->onOneServer() once you
// run redis/memcached/database cache across multiple nodes.
Schedule::command('file-manager:purge-trash')->daily()->withoutOverlapping();
