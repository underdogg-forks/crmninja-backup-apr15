<?php

namespace App\Exceptions;

// controller
use Bugsnag;
use Bugsnag\BugsnagLaravel\BugsnagExceptionHandler as ExceptionHandler;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Foundation\Validation\ValidationException as FoundationValidation;
use Illuminate\Http\Exception\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Response;
use Redirect;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Utils;

/**
 * Class Handler.
 */
class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
      TokenMismatchException::class,
        //        'Symfony\Component\HttpKernel\Exception\HttpException',
      \Illuminate\Http\Exception\HttpResponseException::class,
      ModelNotFoundException::class,
      FoundationValidation::class,
      \Illuminate\Validation\FoundationValidation::class,
        //AuthorizationException::class,
        //HttpException::class,
      ModelNotFoundException::class,
      \Symfony\Component\HttpKernel\Exception\HttpException::class,
      \Illuminate\Validation\ValidationException::class,
      \DaveJamesMiller\Breadcrumbs\Exception::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param \Exception $e
     *
     * @return bool|void
     */
    public function report(Exception $e)
    {
        $debug = \Config::get('app.bugsnag_reporting');
        $debug = ($debug) ? 'true' : 'false';
        if ($debug == 'false') {
            //            Bugsnag::setBeforeNotifyFunction(function ($error) {
            //                return false;
            //            });
        } else {
            $version = \Config::get('app.version');
            Bugsnag::setAppVersion($version);
        }



        if (!$this->shouldReport($e)) {
            return false;
        }
        // if these classes don't exist the install is broken, maybe due to permissions
        if (!class_exists('Utils') || !class_exists('Crawler')) {
            return parent::report($e);
        }
        if (\Crawler::isCrawler()) {
            return false;
        }
        // don't show these errors in the logs
        if ($e instanceof NotFoundHttpException) {
            // The logo can take a few seconds to get synced between servers
            // TODO: remove once we're using cloud storage for logos
            if (Utils::isNinja() && strpos(request()->url(), '/logo/') !== false) {
                return false;
            }
            // Log 404s to a separate file
            $errorStr = date('Y-m-d h:i:s') . ' ' . $e->getMessage() . ' URL:' . request()->url() . "\n" . json_encode(Utils::prepareErrorData('PHP')) . "\n\n";
            if (config('app.log') == 'single') {
                @file_put_contents(storage_path('logs/not-found.log'), $errorStr, FILE_APPEND);
            } else {
                Utils::logError('[not found] ' . $errorStr);
            }
            return false;
        } elseif ($e instanceof HttpResponseException) {
            return false;
        }
        if (!Utils::isTravis()) {
            Utils::logError(Utils::getErrorString($e));
            $stacktrace = date('Y-m-d h:i:s') . ' ' . $e->getMessage() . ': ' . $e->getTraceAsString() . "\n\n";
            if (config('app.log') == 'single') {
                @file_put_contents(storage_path('logs/stacktrace.log'), $stacktrace, FILE_APPEND);
            } else {
                Utils::logError('[stacktrace] ' . $stacktrace);
            }
            return false;
        } else {
            return parent::report($e);
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception $e
     *
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $e)
    {
        if ($e instanceof ModelNotFoundException) {
            return Redirect::to('/');
        }
        if (!class_exists('Utils')) {
            return parent::render($request, $e);
        }
        if ($e instanceof TokenMismatchException) {
            if (!in_array($request->path(), ['get_started', 'save_sidebar_state'])) {
                // https://gist.github.com/jrmadsen67/bd0f9ad0ef1ed6bb594e
                return redirect()
                  ->back()
                  ->withInput($request->except('password', '_token'))
                  ->with([
                    'warning' => trans('texts.token_expired'),
                  ]);
            }
        }



        switch ($e) {
            case $e instanceof \Illuminate\Http\Exception\HttpResponseException:
                return parent::render($request, $e);
            case $e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException:
                return response()->json(['message' => $e->getMessage(), 'code' => $e->getStatusCode()]);
            case $e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException:
                return response()->json(['message' => $e->getMessage(), 'code' => $e->getStatusCode()]);
            default:
                return $this->common($request, $e);
        }


        if ($this->isHttpException($e)) {
            switch ($e->getStatusCode()) {
                // not found
                case 404:
                    if ($request->header('X-Ninja-Token') != '') {
                        //API request which has hit a route which does not exist
                        $error['error'] = ['message' => 'Route does not exist'];
                        $error = json_encode($error, JSON_PRETTY_PRINT);
                        $headers = Utils::getApiHeaders();
                        return response()->make($error, 404, $headers);
                        //return $this->render404($request, $e);
                    }
                    break;
                // internal error
                case '500':
                    if ($request->header('X-Ninja-Token') != '') {
                        //API request which produces 500 error
                        $error['error'] = ['message' => 'Internal Server Error'];
                        $error = json_encode($error, JSON_PRETTY_PRINT);
                        $headers = Utils::getApiHeaders();
                        return response()->make($error, 500, $headers);
                        //return $this->render500($request, $e);
                    }
                    break;

            }
        }
        // In production, except for maintenance mode, we'll show a custom error screen
        if (Utils::isNinjaProd()
          && !Utils::isDownForMaintenance()
          && !($e instanceof HttpResponseException)
          && !($e instanceof \Illuminate\Validation\FoundationValidation)
          && !($e instanceof FoundationValidation)) {
            $data = [
              'error' => get_class($e),
              'hideHeader' => true,
            ];
            return response()->view('error', $data, 500);
            //return $this->render500($request, $e);
        } else {
            return parent::render($request, $e);
        }
    }



    /**
     * Common finction to render both types of codes.
     *
     * @param type $request
     * @param type $e
     *
     * @return type mixed
     */
    public function common($request, $e)
    {
        switch ($e) {
            case $e instanceof HttpException:
                return $this->render404($request, $e);
            case $e instanceof NotFoundHttpException:
                return $this->render404($request, $e);
            case $e instanceof PDOException:
                if (strpos('1045', $e->getMessage()) == true) {
                    return $this->renderDB($request, $e);
                } else {
                    return $this->render500($request, $e);
                }
            //            case $e instanceof ErrorException:
            //                if($e->getMessage() == 'Breadcrumb not found with name "" ') {
            //                    return $this->render404($request, $e);
            //                } else {
            //                    return parent::render($request, $e);
            //                }
            case $e instanceof TokenMismatchException:
                if ($request->ajax()) {
                    return response()->json(['message' => \Lang::get('lang.session-expired')], 402);
                }
                return redirect()->back()->with('fails', \Lang::get('lang.session-expired'));
            default:
                return $this->render500($request, $e);
        }
        return parent::render($request, $e);
    }




    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Illuminate\Auth\AuthenticationException $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $guard = array_get($exception->guards(), 0);
        switch ($guard) {
            case 'client':
                $url = '/client/login';
                break;
            default:
                $url = '/login';
                break;
        }
        return redirect()->guest($url);
    }












    /**
     * Function to render 404 error page.
     *
     * @param type $request
     * @param type $e
     *
     * @return type mixed
     */
    public function render404($request, $e)
    {
        $seg = $request->segments();
        if (in_array('api', $seg)) {
            return response()->json(['status' => '404']);
        }
        if (config('app.debug') == true) {
            if ($e->getStatusCode() == '404') {
                return redirect()->route('error404', []);
            }
            return parent::render($request, $e);
        }
        return redirect()->route('error404', []);
    }

    /**
     * Function to render database connection failed.
     *
     * @param type $request
     * @param type $e
     *
     * @return type mixed
     */
    public function renderDB($request, $e)
    {
        $seg = $request->segments();
        if (in_array('api', $seg)) {
            return response()->json(['status' => '404']);
        }
        if (config('app.debug') == true) {
            return parent::render($request, $e);
        }
        return redirect()->route('error404', []);
    }

    /**
     * Function to render 500 error page.
     *
     * @param type $request
     * @param type $e
     *
     * @return type mixed
     */
    public function render500($request, $e)
    {
        $seg = $request->segments();
        if (in_array('api', $seg)) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        if (config('app.debug') == true) {
            return parent::render($request, $e);
        } elseif ($e instanceof ValidationException) {
            return parent::render($request, $e);
        } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
            return parent::render($request, $e);
        }
        return response()->view('errors.500');
        //return redirect()->route('error500', []);
    }

    /**
     * Convert a validation exception into a JSON response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Validation\ValidationException $exception
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        return response()->json($exception->errors(), $exception->status);
    }








}
