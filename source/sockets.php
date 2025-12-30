#!/usr/bin/env php
<?php

declare(strict_types=1);

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
        
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new RuntimeException("Cannot resolve config file path: {$filePath}");
        }
        
        $allowedPaths = [
            '/etc/socket-stats/',
            getenv('HOME') . '/.config/socket-stats/',
            __DIR__ . '/config/'
        ];
        
        $isAllowed = false;
        foreach ($allowedPaths as $allowed) {
            $allowedReal = realpath($allowed);
            if ($allowedReal && strpos($realPath . '/', $allowedReal . '/') === 0) {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed) {
            throw new RuntimeException("Config file path not allowed: {$filePath}");
        }
        
        $config = parse_ini_file($realPath, true);
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
    private const MAX_FILE_SIZE = 10485760;
    private const MAX_LINE_SIZE = 1048576;
    
    private ToolConfig $config;
    private array $logLevels;
    private float $startTime;
    private bool $extendedMode;
    private array $logCache = [];
    private array $logCacheTime = [];
    private bool $shutdownRequested = false;
    private ?array $protocolParsers = null;
    
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
    }
    
    public function signalHandler(int $signo): void {
        $signals = [
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGHUP => 'SIGHUP'
        ];
        
        $signalName = $signals[$signo] ?? "Signal $signo";
        $this->logMessage('INFO', "Received $signalName, shutting down");
        $this->shutdownRequested = true;
    }
    
    public function run(): void {
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
            pcntl_signal(SIGHUP, [$this, 'signalHandler']);
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
        
        $allowedDirs = [
            realpath('/proc/net') ?: '/proc/net',
            realpath('/proc') ?: '/proc',
            realpath('/tmp/socket-stats') ?: '/tmp/socket-stats'
        ];
        
        foreach ($allowedDirs as $allowedDir) {
            if ($allowedDir && strpos($realPath . '/', $allowedDir . '/') === 0) {
                return is_file($realPath) && is_readable($realPath);
            }
        }
        
        return false;
    }
    
    private function safeFileOpen(string $path): SplFileObject {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException("Cannot resolve path: {$path}");
        }
        
        if (!$this->validateFilePath($realPath)) {
            throw new RuntimeException("Path not allowed: {$path}");
        }
        
        $size = @filesize($realPath);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException("File size exceeds limit: {$path}");
        }
        
        try {
            $file = new SplFileObject($realPath, 'r');
            $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
            return $file;
        } catch (RuntimeException $e) {
            throw new RuntimeException("Cannot access file {$path}: " . $e->getMessage());
        }
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
        
        file_put_contents('php://stderr', $formattedMessage . PHP_EOL);
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
        $this->logMessage('INFO', "Reading socket statistics from {$this->config->sockstat_path}");
        
        $file = $this->safeFileOpen($this->config->sockstat_path);
        
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
        
        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }
            
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            
            $this->parseLine($line, $stats);
            $lineCount++;
            
            if ($lineCount > 1000) {
                throw new RuntimeException("File appears too large or malformed");
            }
        }
        
        $this->logMessage('DEBUG', "Processed {$lineCount} lines from sockstat file");
    }

    private function getProtocolParsers(): array {
        if ($this->protocolParsers === null) {
            $this->protocolParsers = [
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
        
        return $this->protocolParsers;
    }

    private function parseLine(string $line, array &$stats): void {
        if (strlen($line) > self::MAX_LINE_SIZE) {
            $this->logMessage('WARNING', "Line too long, skipping");
            return;
        }
        
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
            'icmp' => ['path' => '/proc/net/snmp', 'type' => 'snmp'],
            'icmp6' => ['path' => '/proc/net/snmp', 'type' => 'snmp6']
        ];
        
        foreach ($filesToLoad as $protocol => $info) {
            try {
                $file = $this->safeFileOpen($info['path']);
                if ($info['type'] === 'count') {
                    $this->countFileLines($file, $stats[$protocol]['in_use']);
                } elseif ($info['type'] === 'section') {
                    $this->loadProtocolFile($file, $info['section'], $stats, $protocol, [
                        'inuse' => 'in_use', 'dynamic' => 'dynamic', 'inode' => 'inode'
                    ]);
                } elseif ($info['type'] === 'snmp') {
                    $this->loadSnmpInfo($file, 'Icmp:', $stats['icmp']['in_use']);
                } elseif ($info['type'] === 'snmp6') {
                    $this->loadSnmpInfo($file, 'Icmp6:', $stats['icmp6']['in_use']);
                }
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not load {$protocol} info: " . $e->getMessage());
            }
        }
        
        $this->loadAdditionalNetworkStats($stats);
    }

    private function countFileLines(SplFileObject $file, int &$count): void {
        $lineCount = 0;
        $firstLine = true;
        
        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }
            
            if ($firstLine) {
                $firstLine = false;
                continue;
            }
            
            $lineCount++;
        }
        
        $count = $lineCount;
    }

    private function loadProtocolFile(SplFileObject $file, string $section, array &$stats, string $protocol, array $mapping): void {
        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }
            
            $line = trim($line);
            if (strpos($line, $section) === 0) {
                $parts = preg_split('/\s+/', $line);
                $this->parseProtocolSection($parts, $stats, $protocol, $mapping);
                break;
            }
        }
    }

    private function loadSnmpInfo(SplFileObject $file, string $targetLine, int &$count): void {
        $inTargetLine = false;
        
        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }
            
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
    }

    private function parseTcpExtendedStats(string $line, array &$stats): void {
        if (!$this->extendedMode) {
            return;
        }
        
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) {
            return;
        }
        
        $stats['tcp_ext'] = [];
        
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
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
            throw new RuntimeException("Failed to parse integer: '{$value}'");
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
            $this->outputFormatted($stats, true);
        } else {
            $this->outputFormatted($stats, false);
        }
    }
    
    private function detectTerminalCapabilities(): string {
        if (!function_exists('posix_isatty') || !posix_isatty(STDOUT)) {
            return 'plain';
        }
        
        $term = getenv('TERM');
        if ($term && (strpos($term, 'xterm') !== false || strpos($term, 'color') !== false)) {
            return 'color';
        }
        
        return 'plain';
    }
    
    private function outputFormatted(array $stats, bool $useColor): void {
        $colorStart = $useColor ? "\033[1;36m" : '';
        $colorEnd = $useColor ? "\033[0m" : '';
        $titleColor = $useColor ? "\033[1;33m" : '';
        $sectionColor = $useColor ? "\033[1;35m" : '';
        $fieldColor = $useColor ? "\033[1;34m" : '';
        
        echo "{$colorStart}Socket Statistics{$colorEnd}" . PHP_EOL;
        echo "{$colorStart}================={$colorEnd}" . PHP_EOL;
        echo "{$titleColor}Generated:{$colorEnd} {$stats['metadata']['generated_at']}" . PHP_EOL;
        echo "{$titleColor}Hostname:{$colorEnd}  {$stats['metadata']['hostname']}" . PHP_EOL;
        echo "{$titleColor}Source:{$colorEnd}    {$stats['metadata']['source']}" . PHP_EOL;
        echo PHP_EOL;
        
        echo "{$titleColor}Sockets used:{$colorEnd} {$stats['sockets_used']}" . PHP_EOL . PHP_EOL;
        
        $this->outputProtocol('TCP', $stats['tcp'], $useColor, $sectionColor, $fieldColor, $colorEnd);
        $this->outputProtocol('UDP', $stats['udp'], $useColor, $sectionColor, $fieldColor, $colorEnd);
        $this->outputProtocol('UDPLite', $stats['udp_lite'], $useColor, $sectionColor, $fieldColor, $colorEnd);
        $this->outputProtocol('RAW', $stats['raw'], $useColor, $sectionColor, $fieldColor, $colorEnd);
        $this->outputProtocol('FRAG', $stats['frag'], $useColor, $sectionColor, $fieldColor, $colorEnd);

        if ($this->extendedMode) {
            $this->outputExtended($stats, $useColor, $colorStart, $sectionColor, $fieldColor, $colorEnd);
        }
    }
    
    private function outputProtocol(string $name, array $data, bool $useColor, string $sectionColor, string $fieldColor, string $colorEnd): void {
        echo "{$sectionColor}{$name}:{$colorEnd}" . PHP_EOL;
        foreach ($data as $field => $value) {
            $fieldName = str_replace('_', ' ', $field);
            $fieldName = ucwords($fieldName);
            $padding = str_repeat(' ', 12 - strlen($fieldName));
            echo "  {$fieldColor}{$fieldName}:{$colorEnd}{$padding}{$value}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
    
    private function outputExtended(array $stats, bool $useColor, string $titleColor, string $sectionColor, string $fieldColor, string $colorEnd): void {
        echo PHP_EOL . "{$titleColor}Extended Protocol Information:{$colorEnd}" . PHP_EOL;
        echo "{$titleColor}============================={$colorEnd}" . PHP_EOL;
        
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
                $this->outputProtocol($protocolName, $stats[$key], $useColor, $sectionColor, $fieldColor, $colorEnd);
            }
        }
    }

    private function loadAdditionalNetworkStats(array &$stats): void {
        try {
            $file = $this->safeFileOpen('/proc/net/netstat');
            
            foreach ($file as $line) {
                if ($this->shutdownRequested) {
                    break;
                }
                
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
            $useColor = $format === 'color';
            
            $colorStart = $useColor ? "\033[1;36m" : '';
            $colorEnd = $useColor ? "\033[0m" : '';
            $titleColor = $useColor ? "\033[1;33m" : '';
            
            echo PHP_EOL . "{$colorStart}Performance Metrics:{$colorEnd}" . PHP_EOL;
            echo "{$colorStart}==================={$colorEnd}" . PHP_EOL;
            echo "{$titleColor}Execution time:{$colorEnd} {$executionTime}s" . PHP_EOL;
            echo "{$titleColor}Peak memory:{$colorEnd}    {$memoryUsage} MB" . PHP_EOL;
            echo "{$titleColor}PHP version:{$colorEnd}    " . PHP_VERSION . PHP_EOL;
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
