<?php
/**
 * gNode Proxy Client
 *
 * Lightweight client for PHP-FPM workers to communicate with gNode Proxy Daemon.
 * Replaces direct gNode-Client usage with fast Unix socket communication.
 *
 * Usage in functions.php:
 *   $proxy = new gNodeProxyClient();
 *   $result = $proxy->renderTemplate('blog-list', $data);
 *
 * @package gTemplate
 */

class gNodeProxyClient {
    private $socketPath;
    private $timeout;
    private $socket = null;

    public function __construct(string $socketPath = '/var/run/gnode-proxy.sock', float $timeout = 5.0) {
        $this->socketPath = $socketPath;
        $this->timeout = $timeout;
    }

    /**
     * Connect to proxy daemon (lazy, reuses connection)
     */
    private function connect() {
        if ($this->socket !== null) {
            return; // Already connected
        }

        // Check if socket exists
        if (!file_exists($this->socketPath)) {
            throw new \RuntimeException("gNode proxy daemon not running (socket not found: {$this->socketPath})");
        }

        // Create socket
        $this->socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($this->socket === false) {
            throw new \RuntimeException("Failed to create socket: " . socket_strerror(socket_last_error()));
        }

        // Set timeout
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => (int) $this->timeout,
            'usec' => (int) (($this->timeout - floor($this->timeout)) * 1000000)
        ]);

        // Connect
        $connected = @socket_connect($this->socket, $this->socketPath);
        if (!$connected) {
            $error = socket_strerror(socket_last_error($this->socket));
            socket_close($this->socket);
            $this->socket = null;
            throw new \RuntimeException("Failed to connect to gNode proxy: {$error}");
        }
    }

    /**
     * Send request to proxy daemon
     */
    private function request(string $cmd, array $params = []): array {
        $start = microtime(true);

        try {
            $this->connect();

            // Build request
            $request = json_encode([
                'cmd' => $cmd,
                'params' => $params
            ]) . "\n";

            // Send request
            $written = @socket_write($this->socket, $request, strlen($request));
            if ($written === false) {
                throw new \RuntimeException("Failed to send request");
            }

            // Read response
            $response = '';
            while (true) {
                $chunk = @socket_read($this->socket, 8192, PHP_NORMAL_READ);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $response .= $chunk;
                if (substr($response, -1) === "\n") {
                    break;
                }
            }

            if (empty($response)) {
                throw new \RuntimeException("Empty response from proxy");
            }

            $duration = (microtime(true) - $start) * 1000;
            error_log("[gNode Proxy Client] {$cmd} completed in {$duration}ms");

            return json_decode(trim($response), true) ?: [];

        } catch (\Exception $e) {
            // Close socket on error
            if ($this->socket) {
                @socket_close($this->socket);
                $this->socket = null;
            }
            throw $e;
        }
    }

    /**
     * Render template via proxy
     */
    public function renderTemplate(string $templateId, array $data = []): array {
        return $this->request('render_template', [
            'template_id' => $templateId,
            'data' => $data
        ]);
    }

    /**
     * Ping via proxy
     */
    public function ping(): bool {
        try {
            $result = $this->request('ping');
            return isset($result['success']) && $result['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Execute batch of commands via proxy
     */
    public function executeBatch(array $commands): array {
        return $this->request('batch', ['commands' => $commands]);
    }

    /**
     * Close connection
     */
    public function close() {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Destructor: close connection
     */
    public function __destruct() {
        $this->close();
    }
}

/**
 * Get global gNode proxy client instance
 *
 * Replaces gtemplate_gnode() in functions.php
 */
function gtemplate_gnode_proxy(): gNodeProxyClient {
    static $client = null;

    if ($client === null) {
        $client = new gNodeProxyClient();
    }

    return $client;
}
