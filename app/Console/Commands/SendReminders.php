<?php

namespace App\Console\Commands;

use App\Libraries\CurlUtils;
use Carbon;
use Str;
use Cache;
use Exception;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\Currency;
use App\Ninja\Mailers\UserMailer;
use App\Ninja\Repositories\AccountRepository;
use App\Ninja\Repositories\InvoiceRepository;
use App\Models\ScheduledReport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use App\Jobs\ExportReportResults;
use App\Jobs\RunReport;

/**
 * Class SendReminders.
 */
class SendReminders extends Command
{
    /**
     * @var string
     */
    protected $name = 'ninja:send-reminders';

    /**
     * @var string
     */
    protected $description = 'Send reminder emails';

    /**
     * @var InvoiceRepository
     */
    protected $invoiceRepo;

    /**
     * @var accountRepository
     */
    protected $accountRepo;

    /**
     * SendReminders constructor.
     *
     * @param Mailer            $mailer
     * @param InvoiceRepository $invoiceRepo
     * @param accountRepository $accountRepo
     */
    public function __construct(InvoiceRepository $invoiceRepo, AccountRepository $accountRepo, UserMailer $userMailer)
    {
        parent::__construct();

        $this->invoiceRepo = $invoiceRepo;
        $this->accountRepo = $accountRepo;
        $this->userMailer = $userMailer;
    }

    public function fire()
    {
        $this->info(date('r') . ' Running SendReminders...');

        if ($database = $this->option('database')) {
            config(['database.default' => $database]);
        }

        $this->chargeLateFees();
        $this->sendReminderEmails();
        $this->sendScheduledReports();
        $this->loadExchangeRates();

        $this->info('Done');

        if ($errorEmail = env('ERROR_EMAIL')) {
            \Mail::raw('EOM', function ($message) use ($errorEmail, $database) {
                $message->to($errorEmail)
                        ->from(CONTACT_EMAIL)
                        ->subject("SendReminders [{$database}]: Finished successfully");
            });
        }
    }

    private function chargeLateFees()
    {
        $accounts = $this->accountRepo->findWithFees();
        $this->info($accounts->count() . ' accounts found with fees');

        foreach ($accounts as $account) {
            if (! $account->hasFeature(FEATURE_EMAIL_TEMPLATES_REMINDERS)) {
                continue;
            }

            $invoices = $this->invoiceRepo->findNeedingReminding($account, false);
            $this->info($account->name . ': ' . $invoices->count() . ' invoices found');

            foreach ($invoices as $invoice) {
                if ($reminder = $account->getInvoiceReminder($invoice, false)) {
                    $this->info('Charge fee: ' . $invoice->id);
                    $account->loadLocalizationSettings($invoice->client); // support trans to add fee line item
                    $number = preg_replace('/[^0-9]/', '', $reminder);

                    $amount = $account->account_email_settings->{"late_fee{$number}_amount"};
                    $percent = $account->account_email_settings->{"late_fee{$number}_percent"};
                    $this->invoiceRepo->setLateFee($invoice, $amount, $percent);
                }
            }
        }
    }

    private function sendReminderEmails()
    {
        $accounts = $this->accountRepo->findWithReminders();
        $this->info(count($accounts) . ' accounts found with reminders');

        foreach ($accounts as $account) {
            if (! $account->hasFeature(FEATURE_EMAIL_TEMPLATES_REMINDERS)) {
                continue;
            }

            // standard reminders
            $invoices = $this->invoiceRepo->findNeedingReminding($account);
            $this->info($account->name . ': ' . $invoices->count() . ' invoices found');

            foreach ($invoices as $invoice) {
                if ($reminder = $account->getInvoiceReminder($invoice)) {
                    if ($invoice->last_sent_date == date('Y-m-d')) {
                        continue;
                    }
                    $this->info('Send email: ' . $invoice->id);
                    dispatch(new SendInvoiceEmail($invoice, $invoice->user_id, $reminder));
                }
            }

            // endless reminders
            $invoices = $this->invoiceRepo->findNeedingEndlessReminding($account);
            $this->info($account->name . ': ' . $invoices->count() . ' endless invoices found');

            foreach ($invoices as $invoice) {
                if ($invoice->last_sent_date == date('Y-m-d')) {
                    continue;
                }
                $this->info('Send email: ' . $invoice->id);
                dispatch(new SendInvoiceEmail($invoice, $invoice->user_id, 'reminder4'));
            }
        }
    }

    private function sendScheduledReports()
    {
        $scheduledReports = ScheduledReport::where('send_date', '<=', date('Y-m-d'))
            ->with('user', 'account.company')
            ->get();
        $this->info($scheduledReports->count() . ' scheduled reports');

        foreach ($scheduledReports as $scheduledReport) {
            $this->info('Processing report: ' . $scheduledReport->id);

            $user = $scheduledReport->user;
            $account = $scheduledReport->account;
            $account->loadLocalizationSettings();

            if (! $account->hasFeature(FEATURE_REPORTS)) {
                continue;
            }

            $config = (array) json_decode($scheduledReport->config);
            $reportType = $config['report_type'];

            // send email as user
            auth()->onceUsingId($user->id);

            $report = dispatch(new RunReport($scheduledReport->user, $reportType, $config, true));
            $file = dispatch(new ExportReportResults($scheduledReport->user, $config['export_format'], $reportType, $report->exportParams));

            if ($file) {
                try {
                    $this->userMailer->sendScheduledReport($scheduledReport, $file);
                    $this->info('Sent report');
                } catch (Exception $exception) {
                    $this->info('ERROR: ' . $exception->getMessage());
                }
            } else {
                $this->info('ERROR: Failed to run report');
            }

            $scheduledReport->updateSendDate();

            auth()->logout();
        }
    }

    private function loadExchangeRates()
    {
        $this->info('Loading latest exchange rates...');

        $data = CurlUtils::get(config('ninja.exchange_rates_url'));
        $data = json_decode($data);

        Currency::whereCode(config('ninja.exchange_rates_base'))->update(['exchange_rate' => 1]);

        foreach ($data->rates as $code => $rate) {
            Currency::whereCode($code)->update(['exchange_rate' => $rate]);
        }

        CurlUtils::get(SITE_URL . '?clear_cache=true');
    }

    /**
     * @return array
     */
    protected function getArguments()
    {
        return [];
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['database', null, InputOption::VALUE_OPTIONAL, 'Database', null],
        ];
    }
}
