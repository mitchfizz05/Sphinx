<?php

namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Log all requests a request log.
 *
 * @package App\Http\Middleware
 */
class LogMiddleware
{
    /**
     * Log a request to the request log.
     *
     * @param Request $request
     * @param Response $response
     * @param bool $routed Was the request successfully routed? i.e. didn't 404?
     */
    public function log($request, $response) {
        $path = '/' . $request->path();
        $method = $request->method();

        // Work out if request was successful.
        $responseClass = substr($response->getStatusCode(), 0, 1);
        $routed = ($responseClass == '2' || $responseClass == '3');

        // Generate message
        $timestamp = date('c');
        if ($routed) {
            $message = "[$timestamp]: $method to \"$path\".";
        } else {
            $message = "[$timestamp]: Errored $method to \"$path\"! Response code: " . $response->getStatusCode();
        }

        // Append to file.
        $file = fopen(storage_path('logs/request.log'), 'a');
        fwrite($file, $message . "\r\n");
        fclose($file);
    }

    public function handle($request, \Closure $next)
    {
        return $next($request);
    }

    /**
     * @param Request $request
     * @param Response $response
     */
    public function terminate($request, $response)
    {
        if (!env('APP_DEBUG', false)) {
            return; // debug mode disable, skip request logging.
        }

        // Log request to file.
        $this->log($request, $response);
    }
}