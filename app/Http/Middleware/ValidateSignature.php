<?php

namespace Illuminate\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ValidateSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if the signature is valid
        if (! $this->hasValidSignature($request)) {
            throw new BadRequestHttpException('Invalid signature');
        }

        return $next($request);
    }

    /**
     * Determine if the request has a valid signature.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function hasValidSignature(Request $request)
    {
        return $request->hasValidSignature();
    }
}
