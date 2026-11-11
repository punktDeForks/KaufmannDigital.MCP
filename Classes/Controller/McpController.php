<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Controller;

use KaufmannDigital\MCP\Mcp\McpHandler;
use KaufmannDigital\MCP\Security\IpAllowlist;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\MediaTypeHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ActionController;

class McpController extends ActionController
{
    public function processRequest(ActionRequest $request, ActionResponse $response): void
    {
        // Accept whatever the client accepts — MCP content type is set explicitly per action
        $this->supportedMediaTypes = MediaTypeHelper::determineAcceptedMediaTypes($request->getHttpRequest());

        parent::processRequest($request, $response);
    }

    /**
     * @Flow\Inject
     * @var McpHandler
     */
    protected $mcpHandler;

    /**
     * @Flow\InjectConfiguration(path="Token")
     * @var string
     */
    protected $token;

    /**
     * @Flow\InjectConfiguration(path="allowedIps")
     * @var array
     */
    protected $allowedIps;

    public function initializeAction(): void
    {
        // IP allowlist check (before token to fail fast).
        // Empty list = block all. Must explicitly allow IPs or CIDRs.
        // Flow's client IP is the directly connected address unless trusted proxies are configured
        // (Neos.Flow.http.trustedProxies), in which case the real client address behind them is used.
        $httpRequest = $this->request->getHttpRequest();
        $clientIp = $httpRequest->getAttribute(ServerRequestAttributes::CLIENT_IP);
        if ($clientIp === null) {
            // The attribute is only missing if the trusted proxies middleware did not run. It is false if Flow could not
            // determine a client address — that must not fall back to the (possibly allowlisted) proxy address.
            $clientIp = $httpRequest->getServerParams()['REMOTE_ADDR'] ?? '';
        }
        $clientIp = (string)$clientIp;
        if (!IpAllowlist::isAllowed($clientIp, (array)$this->allowedIps)) {
            $this->throwStatus(403, 'Forbidden');
        }

        if (empty($this->token)) {
            $this->throwStatus(401, 'Unauthorized');
        }

        $givenToken = current($this->request->getHttpRequest()->getHeader('X-Api-Token')) ?: null;

        if (empty($givenToken)) {
            $this->throwStatus(401, 'Unauthorized');
        }

        if (!hash_equals((string)$this->token, $givenToken)) {
            $this->throwStatus(403, 'Forbidden');
        }
    }

    #[Flow\SkipCsrfProtection]
    public function indexAction(): string
    {
        $body = (string)$this->request->getHttpRequest()->getBody();
        $data = json_decode($body, true);

        $this->response->setContentType('application/json');

        if (!is_array($data)) {
            $this->response->setStatusCode(400);
            return $this->toJson([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
                'id' => null,
            ]);
        }

        // Notifications (no "id" field) require no response body
        if (!array_key_exists('id', $data)) {
            $this->throwStatus(202);
        }

        return $this->toJson($this->mcpHandler->handle($data));
    }

    // GET /mcp — SSE not supported yet, return 405
    #[Flow\SkipCsrfProtection]
    public function streamAction(): string
    {
        $this->response->setStatusCode(405);
        $this->response->setContentType('application/json');
        return $this->toJson(['error' => 'SSE streaming not supported. Use POST.']);
    }

    protected function toJson(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
