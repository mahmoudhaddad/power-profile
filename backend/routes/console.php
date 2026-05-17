<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:auto')->daily();
