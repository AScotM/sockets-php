#!/usr/bin/env php
<?php

class ToolConfig {
    public string $log_level = 'INFO';
    public bool $json_output = false;
    public bool $help = false;
    public bool $show_performance = false;
    public bool $quiet = false;
    public bool $extended = false;
    public string $sockstat_path = '/proc/net/sockstat';
    public ?string $config_file = null;
    
    public function __construct(array $options = []) {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    public function loadFromFile(string $filePath): void {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Config file not found: {$filePath}");
        }
        
        $allowedPaths = [
            '/etc/socket-stats/',
            getenv('HOME') . '/.config/socket-stats/',
            __DIR__ . '/config/'
        ];
        
        $isAllowed = false;
        foreach ($allowedPaths as $allowed) {
            if (strpos(realpath($filePath), $allowed) === 0) {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed) {
            throw new RuntimeException("Config file path not allowed: {$filePath}");
        }
        
        $config = parse_ini_file($filePath, true);
        if ($config === false) {
            throw new RuntimeException("Invalid config file format");
        }
        
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class SocketStatsTool {
    private ToolConfig $config;
    private array $logLevels;
    private float $startTime;
    private bool $extendedMode;
    private int $maxFileSize;
    private array $logCache = [];
    private array $logCacheTime = [];
    private bool $shutdownRequested = false;
    
    public function __construct() {
        $this->logLevels = [
            'DEBUG' => 0,
            'INFO' => 1,
            'WARNING' => 2,
            'ERROR' => 3
        ];
        
        $this->config = new ToolConfig();
        $this->startTime = microtime(true);
        $this->extendedMode = false;
        $this->maxFileSize = 10 * 1024 * 1024;
        
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        }
    }
    
    public function signalHandler(int $signo): void {
        $signals = [
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM'
        ];
        
        $signalName = $signals[$signo] ?? "Signal $signo";
        $this->logMessage('INFO', "Received $signalName, shutting down");
        $this->shutdownRequested = true;
    }
    
    public function run(): void {
        if (extension_loaded('pcntl')) {
            declare(ticks=1);
        }
        
        try {
            $this->parseCommandLine();
            
            if ($this->config->help) {
                $this->showHelp();
                exit(0);
            }
            
            if ($this->config->config_file) {
                $this->config->loadFromFile($this->config->config_file);
            }
            
            $this->validateConfig();
            
            $stats = $this->getSocketStats();
            
            if (!$this->config->quiet) {
                $this->displayStats($stats);
            }

            if ($this->config->show_performance) {
                $this->showPerformanceMetrics();
            }
            
            exit(0);
            
        } catch (Throwable $e) {
            $this->logMessage('ERROR', $e->getMessage());
            exit(1);
        }
    }
    
    private function parseCommandLine(): void {
        global $argv;
        
        $options = getopt('', [
            'json',
            'log-level:',
            'help',
            'path:',
            'performance',
            'version',
            'quiet',
            'extended',
            'config:'
        ]);
        
        $configUpdates = [];
        
        if (isset($options['json'])) {
            $configUpdates['json_output'] = true;
        }
        
        if (isset($options['log-level'])) {
            $configUpdates['log_level'] = strtoupper($options['log-level']);
        }
        
        if (isset($options['help'])) {
            $configUpdates['help'] = true;
        }

        if (isset($options['path'])) {
            $configUpdates['sockstat_path'] = $this->sanitizePath($options['path']);
        }

        if (isset($options['performance'])) {
            $configUpdates['show_performance'] = true;
        }

        if (isset($options['quiet'])) {
            $configUpdates['quiet'] = true;
        }

        if (isset($options['extended'])) {
            $configUpdates['extended'] = true;
            $this->extendedMode = true;
        }

        if (isset($options['config'])) {
            $configUpdates['config_file'] = $this->sanitizePath($options['config']);
        }

        if (isset($options['version'])) {
            $this->showVersion();
            exit(0);
        }
        
        $this->config = new ToolConfig($configUpdates);
    }
    
    private function sanitizePath(string $path): string {
        $path = str_replace("\0", '', $path);
        $path = trim($path);
        $path = str_replace(['../', '..\\'], '', $path);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\/+/', '/', $path);
        return $path;
    }
    
    private function validateConfig(): void {
        if (!isset($this->logLevels[$this->config->log_level])) {
            throw new RuntimeException(
                "Invalid log level: {$this->config->log_level}. " .
                "Valid levels: " . implode(', ', array_keys($this->logLevels))
            );
        }
        
        if (strpos($this->config->sockstat_path, "\0") !== false) {
            throw new RuntimeException("Invalid path: contains null byte");
        }
        
        if (!preg_match('/^[a-zA-Z0-9\/\.\-_]+$/', $this->config->sockstat_path)) {
            throw new RuntimeException("Invalid path format: contains invalid characters");
        }
    }
    
    private function validateFilePath(string $path): bool {
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }
        
        $allowedPaths = [
            '/proc/net/',
            '/proc/',
            '/tmp/socket-stats/'
        ];
        
        foreach ($allowedPaths as $allowed) {
            if (strpos($realPath, $allowed) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    private function logMessage(string $level, string $message): void {
        if ($this->shutdownRequested) {
            return;
        }
        
        if ($level === 'DEBUG') {
            $cacheKey = md5($message);
            $now = time();
            
            if (isset($this->logCache[$cacheKey]) && 
                ($now - $this->logCacheTime[$cacheKey]) < 5) {
                return;
            }
            
            $this->logCache[$cacheKey] = $message;
            $this->logCacheTime[$cacheKey] = $now;
        }
        
        $msgLevel = $this->logLevels[$level] ?? $this->logLevels['INFO'];
        $confLevel = $this->logLevels[$this->config->log_level];
        
        if ($msgLevel < $confLevel) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] {$level}: {$message}";
        
        if ($this->config->json_output || $level === 'ERROR') {
            file_put_contents('php://stderr', $formattedMessage . PHP_EOL);
        } else {
            echo $formattedMessage . PHP_EOL;
        }
    }

    private function showVersion(): void {
        echo "Socket Statistics Tool 1.2.0" . PHP_EOL;
        echo "PHP " . PHP_VERSION . PHP_EOL;
    }
    
    private function showHelp(): void {
        global $argv;
        $scriptName = basename($argv[0]);
        
        $helpText = "Socket Statistics Tool 1.2.0\n\n" .
                   "Usage: {$scriptName} [OPTIONS]\n\n" .
                   "Options:\n" .
                   "  --json                 Output socket summary in JSON format\n" .
                   "  --log-level LEVEL      Set log level (DEBUG, INFO, WARNING, ERROR)\n" .
                   "  --path PATH            Path to sockstat file (default: /proc/net/sockstat)\n" .
                   "  --performance          Show performance metrics\n" .
                   "  --quiet                Suppress all non-error output\n" .
                   "  --extended             Show extended protocol information\n" .
                   "  --config FILE          Load configuration from file\n" .
                   "  --version              Display version information\n" .
                   "  --help                 Display this help message\n\n" .
                   "Examples:\n" .
                   "  {$scriptName} --json\n" .
                   "  {$scriptName} --log-level DEBUG\n" .
                   "  {$scriptName} --json --log-level WARNING\n" .
                   "  {$scriptName} --path /tmp/test-sockstat --json\n" .
                   "  {$scriptName} --performance --json\n" .
                   "  {$scriptName} --quiet --json\n" .
                   "  {$scriptName} --extended --json\n" .
                   "  {$scriptName} --config /etc/socket-stats/config.ini\n\n";
        
        echo $helpText;
    }
    
    private function getSocketStats(): array {
        if (!$this->validateFilePath($this->config->sockstat_path)) {
            throw new RuntimeException("Path not allowed: {$this->config->sockstat_path}");
        }
        
        $this->logMessage('INFO', "Reading socket statistics from {$this->config->sockstat_path}");
        
        try {
            $file = new SplFileObject($this->config->sockstat_path, 'r');
        } catch (RuntimeException $e) {
            throw new RuntimeException("Cannot access socket stats file: " . $e->getMessage());
        }
        
        $stats = [
            'metadata' => [
                'source' => $this->config->sockstat_path,
                'generated_at' => date('c'),
                'hostname' => gethostname() ?: 'unknown'
            ],
            'sockets_used' => 0,
            'tcp' => [
                'in_use' => 0,
                'orphan' => 0,
                'time_wait' => 0,
                'allocated' => 0,
                'memory' => 0
            ],
            'udp' => [
                'in_use' => 0,
                'memory' => 0
            ],
            'udp_lite' => [
                'in_use' => 0
            ],
            'raw' => [
                'in_use' => 0
            ],
            'frag' => [
                'in_use' => 0,
                'memory' => 0
            ]
        ];

        if ($this->extendedMode) {
            $this->initializeExtendedStats($stats);
        }
        
        $this->readSockstatFile($file, $stats);

        if ($this->extendedMode) {
            $this->loadExtendedProtocolInfo($stats);
        }
        
        return $stats;
    }

    private function readSockstatFile(SplFileObject $file, array &$stats): void {
        $lineCount = 0;
        
        while (!$file->eof()) {
            if ($this->shutdownRequested) {
                break;
            }
            
            $line = $file->fgets();
            if ($line === false) break;
            
            $line = trim($line);
            if ($line === '') continue;
            
            $this->parseLine($line, $stats);
            $lineCount++;
        }
        
        $this->logMessage('DEBUG', "Processed {$lineCount} lines from sockstat file");
    }

    private function getProtocolParsers(): array {
        return [
            'sockets:' => function($parts, &$stats) {
                if (count($parts) >= 3) {
                    $stats['sockets_used'] = $this->parseInt($parts[2]);
                }
            },
            'TCP:' => function($parts, &$stats) {
                $this->parseProtocolSection($parts, $stats, 'tcp', [
                    'inuse' => 'in_use', 'orphan' => 'orphan', 'tw' => 'time_wait',
                    'alloc' => 'allocated', 'mem' => 'memory'
                ]);
            },
            'UDP:' => function($parts, &$stats) {
                $this->parseProtocolSection($parts, $stats, 'udp', [
                    'inuse' => 'in_use', 'mem' => 'memory'
                ]);
            },
            'UDPLITE:' => function($parts, &$stats) {
                $this->parseProtocolSection($parts, $stats, 'udp_lite', [
                    'inuse' => 'in_use'
                ]);
            },
            'RAW:' => function($parts, &$stats) {
                $this->parseProtocolSection($parts, $stats, 'raw', [
                    'inuse' => 'in_use'
                ]);
            },
            'FRAG:' => function($parts, &$stats) {
                $this->parseProtocolSection($parts, $stats, 'frag', [
                    'inuse' => 'in_use', 'memory' => 'memory'
                ]);
            },
            'TCP6:' => function($parts, &$stats) {
                if ($this->extendedMode) {
                    $this->parseProtocolSection($parts, $stats, 'tcp6', [
                        'inuse' => 'in_use', 'orphan' => 'orphan', 'tw' => 'time_wait',
                        'alloc' => 'allocated', 'mem' => 'memory'
                    ]);
                }
            },
            'UDP6:' => function($parts, &$stats) {
                if ($this->extendedMode) {
                    $this->parseProtocolSection($parts, $stats, 'udp6', [
                        'inuse' => 'in_use', 'mem' => 'memory'
                    ]);
                }
            }
        ];
    }

    private function parseLine(string $line, array &$stats): void {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) {
            $this->logMessage('DEBUG', "Skipping malformed line: {$line}");
            return;
        }
        
        $parsers = $this->getProtocolParsers();
        $section = $parts[0];
        
        if (isset($parsers[$section])) {
            $parsers[$section]($parts, $stats);
        } else {
            $this->logMessage('DEBUG', "Unknown section: {$section}");
        }
    }

    private function initializeExtendedStats(array &$stats): void {
        $stats['tcp6'] = [
            'in_use' => 0,
            'orphan' => 0,
            'time_wait' => 0,
            'allocated' => 0,
            'memory' => 0
        ];
        
        $stats['udp6'] = [
            'in_use' => 0,
            'memory' => 0
        ];
        
        $stats['unix'] = [
            'in_use' => 0,
            'dynamic' => 0,
            'inode' => 0
        ];
        
        $stats['icmp'] = [
            'in_use' => 0
        ];
        
        $stats['icmp6'] = [
            'in_use' => 0
        ];
        
        $stats['netlink'] = [
            'in_use' => 0
        ];
        
        $stats['packet'] = [
            'in_use' => 0,
            'memory' => 0
        ];
    }
    
    private function loadExtendedProtocolInfo(array &$stats): void {
        $filesToLoad = [
            'unix' => ['path' => '/proc/net/sockstat6', 'section' => 'UNIX:', 'type' => 'section'],
            'netlink' => ['path' => '/proc/net/netlink', 'type' => 'count'],
            'packet' => ['path' => '/proc/net/packet', 'type' => 'count'],
            'icmp' => ['path' => '/proc/net/snmp', 'type' => 'snmp']
        ];
        
        foreach ($filesToLoad as $protocol => $info) {
            if ($this->checkFileAccess($info['path'])) {
                if ($info['type'] === 'count') {
                    $this->countFileLines($info['path'], $stats[$protocol]['in_use']);
                } elseif ($info['type'] === 'section') {
                    $this->loadProtocolFile($info['path'], $info['section'], $stats, $protocol, [
                        'inuse' => 'in_use', 'dynamic' => 'dynamic', 'inode' => 'inode'
                    ]);
                } else {
                    $this->loadSnmpInfo($info['path'], $protocol, $stats[$protocol]['in_use']);
                }
            }
        }
        
        $this->loadAdditionalNetworkStats($stats);
    }

    private function countFileLines(string $filePath, int &$count): void {
        try {
            $file = new SplFileObject($filePath, 'r');
            $lineCount = 0;
            $firstLine = true;
            
            while (!$file->eof()) {
                if ($this->shutdownRequested) {
                    break;
                }
                
                $line = $file->fgets();
                if ($line === false) break;
                
                if ($firstLine) {
                    $firstLine = false;
                    continue;
                }
                
                $lineCount++;
            }
            
            $count = $lineCount;
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not count lines in {$filePath}: " . $e->getMessage());
        }
    }

    private function loadProtocolFile(string $filePath, string $section, array &$stats, string $protocol, array $mapping): void {
        try {
            $file = new SplFileObject($filePath, 'r');
            while (!$file->eof()) {
                if ($this->shutdownRequested) {
                    break;
                }
                
                $line = $file->fgets();
                if ($line === false) break;
                
                $line = trim($line);
                if (strpos($line, $section) === 0) {
                    $parts = preg_split('/\s+/', $line);
                    $this->parseProtocolSection($parts, $stats, $protocol, $mapping);
                    break;
                }
            }
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read {$protocol} info: " . $e->getMessage());
        }
    }

    private function loadSnmpInfo(string $filePath, string $protocol, int &$count): void {
        try {
            $file = new SplFileObject($filePath, 'r');
            $targetLine = $protocol === 'icmp' ? 'Icmp:' : 'Icmp6:';
            $inTargetLine = false;
            
            while (!$file->eof()) {
                if ($this->shutdownRequested) {
                    break;
                }
                
                $line = $file->fgets();
                if ($line === false) break;
                
                $line = trim($line);
                
                if (strpos($line, $targetLine) === 0) {
                    $inTargetLine = true;
                    continue;
                }
                
                if ($inTargetLine) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) > 0) {
                        $count = $this->parseInt($parts[0]);
                    }
                    break;
                }
            }
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read SNMP info: " . $e->getMessage());
        }
    }

    private function checkFileAccess(string $filePath): bool {
        if (!file_exists($filePath) || !is_readable($filePath) || is_dir($filePath)) {
            return false;
        }
        
        $size = @filesize($filePath);
        if ($size === false || $size > 1024 * 1024) {
            return false;
        }
        
        return $this->validateFilePath($filePath);
    }

    private function parseTcpExtendedStats(string $line, array &$stats): void {
        if (!$this->extendedMode) return;
        
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) return;
        
        $stats['tcp_ext'] = [];
        
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) break;
            
            $key = $parts[$i];
            $value = $parts[$i + 1];
            
            $stats['tcp_ext'][$key] = $this->parseInt($value);
        }
    }
    
    private function parseProtocolSection(array $parts, array &$stats, string $protocol, array $mapping): void {
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            $key = $parts[$i];
            $value = $parts[$i + 1];
            
            if (isset($mapping[$key])) {
                $stats[$protocol][$mapping[$key]] = $this->parseInt($value);
            } else {
                $this->logMessage('DEBUG', "Unknown {$protocol} field: {$key}");
            }
        }
    }
    
    private function parseInt(string $value): int {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        if ($val === false) {
            $this->logMessage('WARNING', "Failed to parse integer: '{$value}'");
            return 0;
        }
        return $val;
    }
    
    private function displayStats(array $stats): void {
        if ($this->config->json_output) {
            $this->outputJSON($stats);
        } else {
            $this->outputHumanReadable($stats);
        }
    }
    
    private function outputJSON(array $stats): void {
        $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        
        $jsonData = json_encode($stats, $jsonOptions);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }
        
        echo $jsonData . PHP_EOL;
    }
    
    private function outputHumanReadable(array $stats): void {
        $format = $this->detectTerminalCapabilities();
        
        if ($format === 'color' && !$this->config->json_output) {
            $this->outputColored($stats);
        } else {
            $this->outputPlain($stats);
        }
    }
    
    private function detectTerminalCapabilities(): string {
        if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            if (getenv('TERM') && strpos(getenv('TERM'), 'xterm') !== false) {
                return 'color';
            }
        }
        return 'plain';
    }
    
    private function outputColored(array $stats): void {
        echo "\033[1;36mSocket Statistics\033[0m" . PHP_EOL;
        echo "\033[1;36m=================\033[0m" . PHP_EOL;
        echo "\033[1;33mGenerated:\033[0m {$stats['metadata']['generated_at']}" . PHP_EOL;
        echo "\033[1;33mHostname:\033[0m  {$stats['metadata']['hostname']}" . PHP_EOL;
        echo "\033[1;33mSource:\033[0m    {$stats['metadata']['source']}" . PHP_EOL;
        echo PHP_EOL;
        
        echo "\033[1;32mSockets used:\033[0m {$stats['sockets_used']}" . PHP_EOL . PHP_EOL;
        
        $this->outputProtocolColored('TCP', $stats['tcp']);
        $this->outputProtocolColored('UDP', $stats['udp']);
        $this->outputProtocolColored('UDPLite', $stats['udp_lite']);
        $this->outputProtocolColored('RAW', $stats['raw']);
        $this->outputProtocolColored('FRAG', $stats['frag']);

        if ($this->extendedMode) {
            $this->outputExtendedColored($stats);
        }
    }
    
    private function outputProtocolColored(string $name, array $data): void {
        echo "\033[1;35m{$name}:\033[0m" . PHP_EOL;
        foreach ($data as $field => $value) {
            $fieldName = str_replace('_', ' ', $field);
            $fieldName = ucwords($fieldName);
            $padding = str_repeat(' ', 12 - strlen($fieldName));
            echo "  \033[1;34m{$fieldName}:\033[0m{$padding}{$value}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    private function outputExtendedColored(array $stats): void {
        echo PHP_EOL . "\033[1;36mExtended Protocol Information:\033[0m" . PHP_EOL;
        echo "\033[1;36m=============================\033[0m" . PHP_EOL;
        
        $extendedProtocols = [
            'tcp6' => 'TCP6',
            'udp6' => 'UDP6', 
            'unix' => 'UNIX',
            'netlink' => 'Netlink',
            'packet' => 'Packet',
            'icmp' => 'ICMP',
            'icmp6' => 'ICMP6'
        ];
        
        foreach ($extendedProtocols as $key => $protocolName) {
            if (isset($stats[$key]) && $stats[$key]['in_use'] > 0) {
                $this->outputProtocolColored($protocolName, $stats[$key]);
            }
        }
    }
    
    private function outputPlain(array $stats): void {
        echo "Socket Statistics" . PHP_EOL;
        echo "=================" . PHP_EOL;
        echo "Generated: {$stats['metadata']['generated_at']}" . PHP_EOL;
        echo "Hostname:  {$stats['metadata']['hostname']}" . PHP_EOL;
        echo "Source:    {$stats['metadata']['source']}" . PHP_EOL;
        echo PHP_EOL;
        
        echo "Sockets used: {$stats['sockets_used']}" . PHP_EOL . PHP_EOL;
        
        $this->outputProtocolPlain('TCP', $stats['tcp']);
        $this->outputProtocolPlain('UDP', $stats['udp']);
        $this->outputProtocolPlain('UDPLite', $stats['udp_lite']);
        $this->outputProtocolPlain('RAW', $stats['raw']);
        $this->outputProtocolPlain('FRAG', $stats['frag']);

        if ($this->extendedMode) {
            $this->outputExtendedPlain($stats);
        }
    }
    
    private function outputProtocolPlain(string $name, array $data): void {
        echo "{$name}:" . PHP_EOL;
        foreach ($data as $field => $value) {
            $fieldName = str_replace('_', ' ', $field);
            $fieldName = ucwords($fieldName);
            $padding = str_repeat(' ', 12 - strlen($fieldName));
            echo "  {$fieldName}:{$padding}{$value}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    private function outputExtendedPlain(array $stats): void {
        echo PHP_EOL . "Extended Protocol Information:" . PHP_EOL;
        echo "=============================" . PHP_EOL;
        
        $extendedProtocols = [
            'tcp6' => 'TCP6',
            'udp6' => 'UDP6', 
            'unix' => 'UNIX',
            'netlink' => 'Netlink',
            'packet' => 'Packet',
            'icmp' => 'ICMP',
            'icmp6' => 'ICMP6'
        ];
        
        foreach ($extendedProtocols as $key => $protocolName) {
            if (isset($stats[$key]) && $stats[$key]['in_use'] > 0) {
                $this->outputProtocolPlain($protocolName, $stats[$key]);
            }
        }
    }

    private function loadAdditionalNetworkStats(array &$stats): void {
        $netstatPath = '/proc/net/netstat';
        if (!$this->checkFileAccess($netstatPath)) {
            return;
        }
        
        try {
            $file = new SplFileObject($netstatPath, 'r');
            
            while (!$file->eof()) {
                if ($this->shutdownRequested) {
                    break;
                }
                
                $line = $file->fgets();
                if ($line === false) break;
                
                $line = trim($line);
                
                if (strpos($line, 'TcpExt:') === 0) {
                    $this->parseTcpExtendedStats($line, $stats);
                }
            }
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read extended network stats: " . $e->getMessage());
        }
    }

    private function showPerformanceMetrics(): void {
        $endTime = microtime(true);
        $executionTime = round($endTime - $this->startTime, 4);
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        
        $metrics = [
            'performance' => [
                'execution_time_seconds' => $executionTime,
                'peak_memory_mb' => $memoryUsage,
                'php_version' => PHP_VERSION
            ]
        ];
        
        if ($this->config->json_output) {
            echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            $format = $this->detectTerminalCapabilities();
            
            if ($format === 'color') {
                echo PHP_EOL . "\033[1;36mPerformance Metrics:\033[0m" . PHP_EOL;
                echo "\033[1;36m===================\033[0m" . PHP_EOL;
                echo "\033[1;33mExecution time:\033[0m {$executionTime}s" . PHP_EOL;
                echo "\033[1;33mPeak memory:\033[0m    {$memoryUsage} MB" . PHP_EOL;
                echo "\033[1;33mPHP version:\033[0m    " . PHP_VERSION . PHP_EOL;
            } else {
                echo PHP_EOL . "Performance Metrics:" . PHP_EOL;
                echo "===================" . PHP_EOL;
                echo "Execution time: {$executionTime}s" . PHP_EOL;
                echo "Peak memory:    {$memoryUsage} MB" . PHP_EOL;
                echo "PHP version:    " . PHP_VERSION . PHP_EOL;
            }
        }
    }
}

if (PHP_SAPI === 'cli') {
    $app = new SocketStatsTool();
    $app->run();
} else {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}
