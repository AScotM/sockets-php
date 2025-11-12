#!/usr/bin/env php
<?php

class ToolConfig {
    public $log_level = 'INFO';
    public $json_output = false;
    public $help = false;
    public $show_performance = false;
    public $quiet = false;
    public $extended = false;
    public $sockstat_path = '/proc/net/sockstat';
    
    public function __construct(array $options = []) {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class SocketStatsTool {
    private $config;
    private $logLevels;
    private $startTime;
    private $extendedMode;
    private $maxFileSize;
    
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
    }
    
    public function run() {
        try {
            $this->parseCommandLine();
            
            if ($this->config->help) {
                $this->showHelp();
                exit(0);
            }
            
            $this->validateConfig();
            $this->checkSockstatFile();
            
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
    
    private function parseCommandLine() {
        global $argv;
        
        $options = getopt('', [
            'json',
            'log-level:',
            'help',
            'path:',
            'performance',
            'version',
            'quiet',
            'extended'
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
            $configUpdates['sockstat_path'] = $options['path'];
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

        if (isset($options['version'])) {
            $this->showVersion();
            exit(0);
        }
        
        $this->config = new ToolConfig($configUpdates);
    }
    
    private function validateConfig() {
        if (!isset($this->logLevels[$this->config->log_level])) {
            throw new RuntimeException(
                "Invalid log level: {$this->config->log_level}. " .
                "Valid levels: " . implode(', ', array_keys($this->logLevels))
            );
        }
        
        if (strpos($this->config->sockstat_path, "\0") !== false) {
            throw new RuntimeException("Invalid path: contains null byte");
        }
    }
    
    private function checkSockstatFile() {
        $path = $this->config->sockstat_path;
        
        if (is_link($path)) {
            $realPath = realpath($path);
            if ($realPath === false) {
                throw new RuntimeException("Cannot resolve symbolic link: {$path}");
            }
            $this->config->sockstat_path = $realPath;
            $path = $realPath;
        }
        
        if (!file_exists($path)) {
            throw new RuntimeException(
                "'{$path}' not found. " .
                "Ensure you are running on a Linux system or specify --path for an alternate file"
            );
        }
        
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read '{$path}'");
        }

        if (is_dir($path)) {
            throw new RuntimeException("'{$path}' is a directory, expected a file");
        }
        
        $fileSize = filesize($path);
        if ($fileSize > $this->maxFileSize) {
            throw new RuntimeException("File too large: {$path}");
        }
    }
    
    private function logMessage($level, $message) {
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

    private function showVersion() {
        echo "Socket Statistics Tool 1.1.0" . PHP_EOL;
        echo "PHP " . PHP_VERSION . PHP_EOL;
    }
    
    private function showHelp() {
        global $argv;
        $scriptName = basename($argv[0]);
        
        $helpText = "Socket Statistics Tool 1.1.0\n\n" .
                   "Usage: {$scriptName} [OPTIONS]\n\n" .
                   "Options:\n" .
                   "  --json                 Output socket summary in JSON format\n" .
                   "  --log-level LEVEL      Set log level (DEBUG, INFO, WARNING, ERROR)\n" .
                   "  --path PATH            Path to sockstat file (default: /proc/net/sockstat)\n" .
                   "  --performance          Show performance metrics\n" .
                   "  --quiet                Suppress all non-error output\n" .
                   "  --extended             Show extended protocol information\n" .
                   "  --version              Display version information\n" .
                   "  --help                 Display this help message\n\n" .
                   "Examples:\n" .
                   "  {$scriptName} --json\n" .
                   "  {$scriptName} --log-level DEBUG\n" .
                   "  {$scriptName} --json --log-level WARNING\n" .
                   "  {$scriptName} --path /tmp/test-sockstat --json\n" .
                   "  {$scriptName} --performance --json\n" .
                   "  {$scriptName} --quiet --json\n" .
                   "  {$scriptName} --extended --json\n\n";
        
        echo $helpText;
    }
    
    private function getSocketStats() {
        $this->logMessage('INFO', "Reading socket statistics from {$this->config->sockstat_path}");
        
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
        
        $this->readSockstatFile($stats);

        if ($this->extendedMode) {
            $this->loadExtendedProtocolInfo($stats);
        }
        
        return $stats;
    }

    private function readSockstatFile(&$stats) {
        try {
            $file = new SplFileObject($this->config->sockstat_path, 'r');
            $lineCount = 0;
            
            while (!$file->eof()) {
                $line = $file->fgets();
                if ($line === false) break;
                
                $line = trim($line);
                if ($line === '') continue;
                
                $this->parseLine($line, $stats);
                $lineCount++;
            }
            
            $this->logMessage('DEBUG', "Processed {$lineCount} lines from sockstat file");
            
        } catch (RuntimeException $e) {
            throw new RuntimeException("Failed to read {$this->config->sockstat_path}: " . $e->getMessage());
        }
    }

    private function getProtocolParsers() {
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

    private function parseLine($line, &$stats) {
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

    private function initializeExtendedStats(&$stats) {
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
    
    private function loadExtendedProtocolInfo(&$stats) {
        $this->loadUnixSockets($stats);
        $this->loadNetlinkSockets($stats);
        $this->loadPacketSockets($stats);
        $this->loadICMPInfo($stats);
        $this->loadAdditionalNetworkStats($stats);
    }

    private function loadUnixSockets(&$stats) {
        $sockstat6Path = '/proc/net/sockstat6';
        $this->loadProtocolFile($sockstat6Path, 'UNIX:', $stats, 'unix', [
            'inuse' => 'in_use', 'dynamic' => 'dynamic', 'inode' => 'inode'
        ]);
    }

    private function loadNetlinkSockets(&$stats) {
        $netlinkPath = '/proc/net/netlink';
        if (!$this->checkFileAccess($netlinkPath)) {
            return;
        }
        
        try {
            $content = file($netlinkPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($content === false) {
                return;
            }
            
            array_shift($content);
            $stats['netlink']['in_use'] = count($content);
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read netlink socket info: " . $e->getMessage());
        }
    }

    private function loadPacketSockets(&$stats) {
        $packetPath = '/proc/net/packet';
        if (!$this->checkFileAccess($packetPath)) {
            return;
        }
        
        try {
            $content = file($packetPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($content === false) {
                return;
            }
            
            array_shift($content);
            $stats['packet']['in_use'] = count($content);
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read packet socket info: " . $e->getMessage());
        }
    }

    private function loadICMPInfo(&$stats) {
        $snmpPath = '/proc/net/snmp';
        if (!$this->checkFileAccess($snmpPath)) {
            return;
        }
        
        try {
            $file = new SplFileObject($snmpPath, 'r');
            $inIcmpLine = false;
            $inIcmp6Line = false;
            
            while (!$file->eof()) {
                $line = $file->fgets();
                if ($line === false) break;
                
                $line = trim($line);
                
                if (strpos($line, 'Icmp:') === 0) {
                    $inIcmpLine = true;
                    continue;
                } elseif (strpos($line, 'Icmp6:') === 0) {
                    $inIcmp6Line = true;
                    continue;
                }
                
                if ($inIcmpLine) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) > 0) {
                        $stats['icmp']['in_use'] = $this->parseInt($parts[0]);
                    }
                    $inIcmpLine = false;
                }
                
                if ($inIcmp6Line) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) > 0) {
                        $stats['icmp6']['in_use'] = $this->parseInt($parts[0]);
                    }
                    $inIcmp6Line = false;
                }
            }
            
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read ICMP info: " . $e->getMessage());
        }
    }

    private function loadAdditionalNetworkStats(&$stats) {
        $netstatPath = '/proc/net/netstat';
        if (!$this->checkFileAccess($netstatPath)) {
            return;
        }
        
        try {
            $file = new SplFileObject($netstatPath, 'r');
            
            while (!$file->eof()) {
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

    private function loadProtocolFile($filePath, $section, &$stats, $protocol, $mapping) {
        if (!$this->checkFileAccess($filePath)) {
            return;
        }
        
        try {
            $file = new SplFileObject($filePath, 'r');
            while (!$file->eof()) {
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

    private function checkFileAccess($filePath) {
        return file_exists($filePath) && is_readable($filePath) && !is_dir($filePath);
    }

    private function parseTcpExtendedStats($line, &$stats) {
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
    
    private function parseProtocolSection($parts, &$stats, $protocol, $mapping) {
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
    
    private function parseInt($value) {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        if ($val === false) {
            $this->logMessage('WARNING', "Failed to parse integer: '{$value}'");
            return 0;
        }
        return $val;
    }
    
    private function displayStats($stats) {
        if ($this->config->json_output) {
            $this->outputJSON($stats);
        } else {
            $this->outputHumanReadable($stats);
        }
    }
    
    private function outputJSON($stats) {
        $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        
        $jsonData = json_encode($stats, $jsonOptions);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }
        
        echo $jsonData . PHP_EOL;
    }
    
    private function outputHumanReadable($stats) {
        echo "Socket Statistics" . PHP_EOL;
        echo "=================" . PHP_EOL;
        echo "Generated: {$stats['metadata']['generated_at']}" . PHP_EOL;
        echo "Hostname:  {$stats['metadata']['hostname']}" . PHP_EOL;
        echo "Source:    {$stats['metadata']['source']}" . PHP_EOL;
        echo PHP_EOL;
        
        echo "Sockets used: {$stats['sockets_used']}" . PHP_EOL . PHP_EOL;
        
        echo "TCP:" . PHP_EOL;
        echo "  In use:     {$stats['tcp']['in_use']}" . PHP_EOL;
        echo "  Orphan:     {$stats['tcp']['orphan']}" . PHP_EOL;
        echo "  Time wait:  {$stats['tcp']['time_wait']}" . PHP_EOL;
        echo "  Allocated:  {$stats['tcp']['allocated']}" . PHP_EOL;
        echo "  Memory:     {$stats['tcp']['memory']} pages" . PHP_EOL . PHP_EOL;
        
        echo "UDP:" . PHP_EOL;
        echo "  In use:     {$stats['udp']['in_use']}" . PHP_EOL;
        echo "  Memory:     {$stats['udp']['memory']} pages" . PHP_EOL . PHP_EOL;
        
        echo "UDPLite:" . PHP_EOL;
        echo "  In use:     {$stats['udp_lite']['in_use']}" . PHP_EOL . PHP_EOL;
        
        echo "RAW:" . PHP_EOL;
        echo "  In use:     {$stats['raw']['in_use']}" . PHP_EOL . PHP_EOL;
        
        echo "FRAG:" . PHP_EOL;
        echo "  In use:     {$stats['frag']['in_use']}" . PHP_EOL;
        echo "  Memory:     {$stats['frag']['memory']} pages" . PHP_EOL;

        if ($this->extendedMode) {
            $this->outputExtendedHumanReadable($stats);
        }
    }

    private function outputExtendedHumanReadable($stats) {
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
        
        foreach ($extendedProtocols as $key => $name) {
            if (isset($stats[$key]) && $stats[$key]['in_use'] > 0) {
                echo "{$name}:" . PHP_EOL;
                foreach ($stats[$key] as $field => $value) {
                    $fieldName = str_replace('_', ' ', $field);
                    $fieldName = ucwords($fieldName);
                    echo "  {$fieldName}:{$value}" . str_repeat(' ', 10 - strlen($fieldName)) . "{$value}" . PHP_EOL;
                }
                echo PHP_EOL;
            }
        }
    }

    private function showPerformanceMetrics() {
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
            echo PHP_EOL . "Performance Metrics:" . PHP_EOL;
            echo "===================" . PHP_EOL;
            echo "Execution time: {$executionTime}s" . PHP_EOL;
            echo "Peak memory:    {$memoryUsage} MB" . PHP_EOL;
            echo "PHP version:    " . PHP_VERSION . PHP_EOL;
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
