<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BlockSuspiciousAgents
{
  protected array $blockedAgents = [
    'curl',
    'python-requests',
    'python-urllib',
    'scrapy',
    'wget',
    'libwww-perl',
    'go-http-client',
    'java/',
    'okhttp',
    'httpclient',
    'bot',
    'spider',
    'crawl',
    'semrush',
    'ahrefsbot',
    'mj12bot',
    'dotbot',
    'petalbot',
  ];

  public function handle(Request $request, Closure $next): Response
  {
    $userAgent = $request->userAgent();

    if (empty($userAgent)) {
      $this->logAttempt($request, 'Empty User-Agent');
      return $this->blockResponse();
    }

    $userAgentLower = strtolower($userAgent);

    foreach ($this->blockedAgents as $blocked) {
      if (str_contains($userAgentLower, strtolower($blocked))) {
        $this->logAttempt($request, "Blocked agent: {$blocked}");
        return $this->blockResponse();
      }
    }

    return $next($request);
  }

  protected function blockResponse(): Response
  {
    return response()->json([
      'error'   => 'Access denied.',
      'message' => 'Your client is not allowed to access this resource.',
    ], 403);
  }

  protected function logAttempt(Request $request, string $reason): void
  {
    Log::warning('Bot/Suspicious request blocked', [
      'reason'     => $reason,
      'ip'         => $request->ip(),
      'user_agent' => $request->userAgent(),
      'url'        => $request->fullUrl(),
      'method'     => $request->method(),
      'timestamp'  => now()->toIso8601String(),
    ]);
  }
}
