<?php

namespace App\Console;

use App\Model\MailJob\Condition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Utils;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [

      'App\Console\Commands\Inspire',
      'App\Console\Commands\SendReport',
      'App\Console\Commands\CloseWork',
      'App\Console\Commands\TicketFetch',
      'App\Console\Commands\UpdateEncryption',
      \App\Console\Commands\DropTables::class,
      \App\Console\Commands\Install::class,
      \App\Console\Commands\InstallDB::class,

      'App\Console\Commands\SendRecurringInvoices',
      'App\Console\Commands\RemoveOrphanedDocuments',
      'App\Console\Commands\ResetData',
      'App\Console\Commands\CheckData',
      'App\Console\Commands\PruneData',
      'App\Console\Commands\CreateTestData',
      'App\Console\Commands\CreateLuisData',
      'App\Console\Commands\SendRenewalInvoices',
      'App\Console\Commands\ChargeRenewalInvoices',
      'App\Console\Commands\SendReminders',
      'App\Console\Commands\TestOFX',
      'App\Console\Commands\MakeModule',
      'App\Console\Commands\MakeClass',
      'App\Console\Commands\InitLookup',
      'App\Console\Commands\CalculatePayouts',
      'App\Console\Commands\UpdateKey',
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        if (env('DB_INSTALL') == 1) {
            if ($this->getCurrentQueue() != 'sync') {
                $schedule->command('queue:listen ' . $this->getCurrentQueue() . ' --sleep 60')->everyMinute();
            }
            $this->execute($schedule, 'fetching');
            $this->execute($schedule, 'notification');
            $this->execute($schedule, 'work');
            $schedule->command('sla-escalate')->everyThirtyMinutes();
        }


        $logFile = storage_path() . '/logs/cron.log';
        $schedule
          ->command('ninja:send-invoices --force')
          ->sendOutputTo($logFile)
          ->withoutOverlapping()
          ->hourly();
        $schedule
          ->command('ninja:send-reminders --force')
          ->sendOutputTo($logFile)
          ->daily();
    }



    public function getCurrentQueue()
    {
        $queue = 'database';
        $services = new \App\Model\MailJob\QueueService();
        $current = $services->where('status', 1)->first();
        if ($current) {
            $queue = $current->short_name;
        }
        return $queue;
    }

    public function execute($schedule, $task)
    {
        $condition = new Condition();
        $command = $condition->getConditionValue($task);
        switch ($task) {
            case 'fetching':
                $this->getCondition($schedule->command('ticket:fetch'), $command);
                break;
            case 'notification':
                $this->getCondition($schedule->command('report:send'), $command);
                break;
            case 'work':
                $this->getCondition($schedule->command('ticket:close'), $command);
                break;
        }
    }

    public function getCondition($schedule, $command)
    {
        $condition = $command['condition'];
        $at = $command['at'];
        switch ($condition) {
            case 'everyMinute':
                $schedule->everyMinute();
                break;
            case 'everyFiveMinutes':
                $schedule->everyFiveMinutes();
                break;
            case 'everyTenMinutes':
                $schedule->everyTenMinutes();
                break;
            case 'everyThirtyMinutes':
                $schedule->everyThirtyMinutes();
                break;
            case 'hourly':
                $schedule->hourly();
                break;
            case 'daily':
                $schedule->daily();
                break;
            case 'dailyAt':
                $this->getConditionWithOption($schedule, $condition, $at);
                break;
            case 'weekly':
                $schedule->weekly();
                break;
            case 'monthly':
                $schedule->monthly();
                break;
            case 'yearly':
                $schedule->yearly();
                break;
        }
    }

    public function getConditionWithOption($schedule, $command, $at)
    {
        switch ($command) {
            case 'dailyAt':
                $schedule->dailyAt($at);
                break;
        }
    }

    /**
     * Register the Closure based commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        require base_path('routes/console.php');
    }


}
