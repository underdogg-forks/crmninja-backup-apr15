<?php

namespace App\Http\Controllers;

use App\Libraries\Utils;
use App\Models\Account;
use App\Ninja\Mailers\Mailer;
use Auth;
use Input;
use Mail;
use Redirect;
use Request;
use Response;
use Session;
use View;

/**
 * Class HomeController.
 */
class HomeController extends BaseController
{
    /**
     * @var Mailer
     */
    protected $mailer;

    /**
     * HomeController constructor.
     *
     * @param Mailer $mailer
     */
    public function __construct(Mailer $mailer)
    {
        //parent::__construct();
        $this->mailer = $mailer;
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function showIndex()
    {
        Session::reflash();
        if (!Utils::isNinja() && (!Utils::isDatabaseSetup() || Account::count() == 0)) {
            return Redirect::to('/setup');
        } elseif (Auth::check()) {
            return Redirect::to('/dashboard');
        } else {
            return Redirect::to('/login');
        }
    }

    /**
     * @return \Illuminate\Contracts\View\View
     */
    public function viewLogo()
    {
        return View::make('public.logo');
    }

    /**
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function invoiceNow()
    {
        // Track the referral/campaign code
        if (Input::has('rc')) {
            session([SESSION_REFERRAL_CODE => Input::get('rc')]);
        }
        if (Auth::check()) {
            $redirectTo = Input::get('redirect_to') ? SITE_URL . '/' . ltrim(Input::get('redirect_to'),
                '/') : 'invoices/create';
            return Redirect::to($redirectTo)->with('sign_up', Input::get('sign_up'));
        } else {
            return View::make('public.invoice_now');
        }
    }

    /**
     * @param $userType
     * @param $version
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function newsFeed($userType, $version)
    {
        $response = Utils::getNewsFeedResponse($userType);
        return Response::json($response);
    }

    /**
     * @return string
     */
    public function hideMessage()
    {
        if (Auth::check() && Session::has('news_feed_id')) {
            $newsFeedId = Session::get('news_feed_id');
            if ($newsFeedId != NEW_VERSION_AVAILABLE && $newsFeedId > Auth::user()->news_feed_id) {
                $user = Auth::user();
                $user->news_feed_id = $newsFeedId;
                $user->save();
            }
        }
        Session::forget('news_feed_message');
        return 'success';
    }

    /**
     * Show the application dashboard to the user.
     *
     * @return Response
     */
    public function index()
    {
        // ksjdckjdsnc
        return view('themes/default1/admin/dashboard');
    }


    public function getdata()
    {
        return \View::make('emails/notifications/agent');
    }


    public function getreport()
    {
        return \View::make('test');
    }


    public function pushdata()
    {
        $date2 = strtotime(date('Y-m-d'));
        $date3 = date('Y-m-d');
        $format = 'Y-m-d';
        $date1 = strtotime(date($format, strtotime('-1 month' . $date3)));
        $return = '';
        $last = '';
        for ($i = $date1; $i <= $date2; $i = $i + 86400) {
            $thisDate = date('Y-m-d', $i);
            $created = \DB::table('tickets')->select('created_at')->where('created_at', 'LIKE',
              '%' . $thisDate . '%')->count();
            $closed = \DB::table('tickets')->select('closed_at')->where('closed_at', 'LIKE',
              '%' . $thisDate . '%')->count();
            $reopened = \DB::table('tickets')->select('reopened_at')->where('reopened_at', 'LIKE',
              '%' . $thisDate . '%')->count();
            $value = ['date' => $thisDate, 'open' => $created, 'closed' => $closed, 'reopened' => $reopened];
            $array = array_map('htmlentities', $value);
            $json = html_entity_decode(json_encode($array));
            $return .= $json . ',';
        }
        $last = rtrim($return, ',');
        return '[' . $last . ']';
    }


    /**
     * @return string
     */
    public function logError()
    {
        return Utils::logError(Input::get('error'), 'JavaScript');
    }

    /**
     * @return mixed
     */
    public function keepAlive()
    {
        return RESULT_SUCCESS;
    }

    /**
     * @return mixed
     */
    public function loggedIn()
    {
        return RESULT_SUCCESS;
    }

    /**
     * @return mixed
     */
    public function contactUs()
    {
        $message = request()->contact_us_message;
        if (request()->include_errors) {
            $message .= "\n\n" . join("\n", Utils::getErrors());
        }
        Mail::raw($message, function ($message) {
            $subject = 'Customer Message [';
            if (Utils::isNinjaProd()) {
                $subject .= str_replace('db-ninja-', '', config('database.default'));
                $subject .= Auth::user()->present()->statusCode . '] ';
            } else {
                $subject .= 'Self-Host] | ';
            }
            $subject .= date('M jS, g:ia');
            $message->to(env('CONTACT_EMAIL', 'contact@invoiceninja.com'))
              ->from(CONTACT_EMAIL, Auth::user()->present()->fullName)
              ->replyTo(Auth::user()->email, Auth::user()->present()->fullName)
              ->subject($subject);
        });
        return RESULT_SUCCESS;
    }
}
