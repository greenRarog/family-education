<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAdvertisementRequests extends ThrottleRequests
{
    /**
     * @param  array<int, \stdClass>  $limits
     */
    protected function handleRequest($request, Closure $next, array $limits): Response
    {
        // Laravel rejects the first exhausted limit. Report the longest wait when
        // both windows are exhausted, including near the end of a daily window.
        usort($limits, fn ($left, $right) => $this->limiter->availableIn($right->key)
            <=> $this->limiter->availableIn($left->key));

        return parent::handleRequest($request, $next, $limits);
    }
}
