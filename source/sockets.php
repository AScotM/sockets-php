#!/usr/bin/env php
<?php

class SocketStatsTool {
    private $config;
    private $logLevels;
    private $sockstatPath;
    private $startTime;
    private $extendedMode;
    
    public function __construct() {
        $this->sockstatPath = '/proc/net/sockstat';
        $this->logLevels = array(
            'DEBUG' => 0,
            'INFO' => 1,
            'WARNING' => 2,
            'ERROR' => 3
        );
        
        $this->config = array(
            'log_level' => 'INFO',
            'json_output' => false,
            'help' => false,
            'show_performance' => false,
            'quiet' => false,
            'extended' => false
        );

        $this->startTime = microtime(true);
        $this->extendedMode = false;
    }
    
    public function run() {
        try {
            $this->parseCommandLine();
            
            if ($this->config['help']) {
                $this->showHelp();
                exit(0);
            }
            
            $this->validateConfig();
            $this->checkSockstatFile();
            
            $stats = $this->getSocketStats();
            
            if (!$this->config['quiet']) {
                $this->displayStats($stats);
            }

            if ($this->config['show_performance']) {
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
        
        $options = getopt('', array(
            'json',
            'log-level:',
            'help',
            'path:',
            'performance',
            'version',
            'quiet',
            'extended'
        ));
        
        if (isset($options['json'])) {
            $this->config['json_output'] = true;
        }
        
        if (isset($options['log-level'])) {
            $this->config['log_level'] = strtoupper($options['log-level']);
        }
        
        if (isset($options['help'])) {
            $this->config['help'] = true;
        }

        if (isset($options['path'])) {
            $this->sockstatPath = $options['path'];
        }

        if (isset($options['performance'])) {
            $this->config['show_performance'] = true;
        }

        if (isset($options['quiet'])) {
            $this->config['quiet'] = true;
        }

        if (isset($options['extended'])) {
            $this->config['extended'] = true;
            $this->extendedMode = true;
        }

        if (isset($options['version'])) {
            $this->showVersion();
            exit(0);
        }
    }
    
    private function validateConfig() {
        if (!isset($this->logLevels[$this->config['log_level']])) {
            throw new RuntimeException(
                "Invalid log level: {$this->config['log_level']}. " .
                "Valid levels: " . implode(', ', array_keys($this->logLevels))
            );
        }
        
        if (strpos($this->sockstatPath, "\0") !== false) {
            throw new RuntimeException("Invalid path: contains null byte");
        }
    }
    
    private function checkSockstatFile() {
        if (is_link($this->sockstatPath)) {
            $realPath = realpath($this->sockstatPath);
            if ($realPath === false) {
                throw new RuntimeException("Cannot resolve symbolic link: {$this->sockstatPath}");
            }
            $this->sockstatPath = $realPath;
        }
        
        if (!file_exists($this->sockstatPath)) {
            throw new RuntimeException(
                "'{$this->sockstatPath}' not found. " .
                "Ensure you are running on a Linux system or specify --path for an alternate file"
            );
        }
        
        if (!is_readable($this->sockstatPath)) {
            throw new RuntimeException("Cannot read '{$this->sockstatPath}'");
        }

        if (is_dir($this->sockstatPath)) {
            throw new RuntimeException("'{$this->sockstatPath}' is a directory, expected a file");
        }
    }
    
    private function logMessage($level, $message) {
        $msgLevel = isset($this->logLevels[$level]) ? $this->logLevels[$level] : $this->logLevels['INFO'];
        $confLevel = $this->logLevels[$this->config['log_level']];
        
        if ($msgLevel < $confLevel) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = sprintf('[%s] %s: %s', $timestamp, $level, $message);
        
        if ($this->config['json_output'] || $level === 'ERROR') {
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
        $this->logMessage('INFO', "Reading socket statistics from {$this->sockstatPath}");
        
        $stats = array(
            'metadata' => array(
                'source' => $this->sockstatPath,
                'generated_at' => date('c'),
                'hostname' => gethostname() ?: 'unknown'
            ),
            'sockets_used' => 0,
            'tcp' => array(
                'in_use' => 0,
                'orphan' => 0,
                'time_wait' => 0,
                'allocated' => 0,
                'memory' => 0
            ),
            'udp' => array(
                'in_use' => 0,
                'memory' => 0
            ),
            'udp_lite' => array(
                'in_use' => 0
            ),
            'raw' => array(
                'in_use' => 0
            ),
            'frag' => array(
                'in_use' => 0,
                'memory' => 0
            )
        );

        if ($this->extendedMode) {
            $this->initializeExtendedStats($stats);
        }
        
        try {
            $file = new SplFileObject($this->sockstatPath, 'r');
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
            throw new RuntimeException("Failed to read {$this->sockstatPath}: " . $e->getMessage());
        }

        if ($this->extendedMode) {
            $this->loadExtendedProtocolInfo($stats);
        }
        
        return $stats;
    }

    private function initializeExtendedStats(&$stats) {
        $stats['tcp6'] = array(
            'in_use' => 0,
            'orphan' => 0,
            'time_wait' => 0,
            'allocated' => 0,
            'memory' => 0
        );
        
        $stats['udp6'] = array(
            'in_use' => 0,
            'memory' => 0
        );
        
        $stats['unix'] = array(
            'in_use' => 0,
            'dynamic' => 0,
            'inode' => 0
        );
        
        $stats['icmp'] = array(
            'inuse' => 0
        );
        
        $stats['icmp6'] = array(
            'inuse' => 0
        );
        
        $stats['netlink'] = array(
            'in_use' => 0
        );
        
        $stats['packet'] = array(
            'in_use' => 0,
            'memory' => 0
        );
    }
    
    private function parseLine($line, &$stats) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) {
            $this->logMessage('DEBUG', "Skipping malformed line: {$line}");
            return;
        }
        
        switch ($parts[0]) {
            case 'sockets:':
                if (count($parts) >= 3) {
                    $stats['sockets_used'] = $this->parseInt($parts[2]);
                }
                break;
                
            case 'TCP:':
                $this->parseProtocolSection($parts, $stats, 'tcp', array(
                    'inuse' => 'in_use',
                    'orphan' => 'orphan',
                    'tw' => 'time_wait',
                    'alloc' => 'allocated',
                    'mem' => 'memory'
                ));
                break;
                
            case 'UDP:':
                $this->parseProtocolSection($parts, $stats, 'udp', array(
                    'inuse' => 'in_use',
                    'mem' => 'memory'
                ));
                break;
                
            case 'UDPLITE:':
                $this->parseProtocolSection($parts, $stats, 'udp_lite', array(
                    'inuse' => 'in_use'
                ));
                break;
                
            case 'RAW:':
                $this->parseProtocolSection($parts, $stats, 'raw', array(
                    'inuse' => 'in_use'
                ));
                break;
                
            case 'FRAG:':
                $this->parseProtocolSection($parts, $stats, 'frag', array(
                    'inuse' => 'in_use',
                    'memory' => 'memory'
                ));
                break;

            case 'TCP6:':
                if ($this->extendedMode) {
                    $this->parseProtocolSection($parts, $stats, 'tcp6', array(
                        'inuse' => 'in_use',
                        'orphan' => 'orphan',
                        'tw' => 'time_wait',
                        'alloc' => 'allocated',
                        'mem' => 'memory'
                    ));
                }
                break;

            case 'UDP6:':
                if ($this->extendedMode) {
                    $this->parseProtocolSection($parts, $stats, 'udp6', array(
                        'inuse' => 'in_use',
                        'mem' => 'memory'
                    ));
                }
                break;

            default:
                $this->logMessage('DEBUG', "Unknown section: {$parts[0]}");
                break;
        }
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
        if (file_exists($sockstat6Path) && is_readable($sockstat6Path)) {
            try {
                $file = new SplFileObject($sockstat6Path, 'r');
                while (!$file->eof()) {
                    $line = $file->fgets();
                    if ($line === false) break;
                    
                    $line = trim($line);
                    if (strpos($line, 'UNIX:') === 0) {
                        $parts = preg_split('/\s+/', $line);
                        $this->parseProtocolSection($parts, $stats, 'unix', array(
                            'inuse' => 'in_use',
                            'dynamic' => 'dynamic',
                            'inode' => 'inode'
                        ));
                        break;
                    }
                }
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not read UNIX socket info: " . $e->getMessage());
            }
        }
    }

    private function loadNetlinkSockets(&$stats) {
        $netlinkPath = '/proc/net/netlink';
        if (file_exists($netlinkPath) && is_readable($netlinkPath)) {
            try {
                $file = new SplFileObject($netlinkPath, 'r');
                $netlinkCount = 0;
                
                $file->fgets();
                
                while (!$file->eof()) {
                    $line = $file->fgets();
                    if ($line === false) break;
                    
                    $line = trim($line);
                    if ($line !== '') {
                        $netlinkCount++;
                    }
                }
                
                $stats['netlink']['in_use'] = $netlinkCount;
                
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not read netlink socket info: " . $e->getMessage());
            }
        }
    }

    private function loadPacketSockets(&$stats) {
        $packetPath = '/proc/net/packet';
        if (file_exists($packetPath) && is_readable($packetPath)) {
            try {
                $file = new SplFileObject($packetPath, 'r');
                $packetCount = 0;
                
                $file->fgets();
                
                while (!$file->eof()) {
                    $line = $file->fgets();
                    if ($line === false) break;
                    
                    $line = trim($line);
                    if ($line !== '') {
                        $packetCount++;
                    }
                }
                
                $stats['packet']['in_use'] = $packetCount;
                
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not read packet socket info: " . $e->getMessage());
            }
        }
    }

    private function loadICMPInfo(&$stats) {
        $snmpPath = '/proc/net/snmp';
        if (file_exists($snmpPath) && is_readable($snmpPath)) {
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
                            $stats['icmp']['inuse'] = $this->parseInt($parts[0]);
                        }
                        $inIcmpLine = false;
                    }
                    
                    if ($inIcmp6Line) {
                        $parts = preg_split('/\s+/', $line);
                        if (count($parts) > 0) {
                            $stats['icmp6']['inuse'] = $this->parseInt($parts[0]);
                        }
                        $inIcmp6Line = false;
                    }
                }
                
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not read ICMP info: " . $e->getMessage());
            }
        }
    }

    private function loadAdditionalNetworkStats(&$stats) {
        $netstatPath = '/proc/net/netstat';
        if (file_exists($netstatPath) && is_readable($netstatPath)) {
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
    }

    private function parseTcpExtendedStats($line, &$stats) {
        if (!$this->extendedMode) return;
        
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) return;
        
        $stats['tcp_ext'] = array();
        
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
        if ($this->config['json_output']) {
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
        
        if (isset($stats['tcp6']) && $stats['tcp6']['in_use'] > 0) {
            echo "TCP6:" . PHP_EOL;
            echo "  In use:     {$stats['tcp6']['in_use']}" . PHP_EOL;
            echo "  Orphan:     {$stats['tcp6']['orphan']}" . PHP_EOL;
            echo "  Time wait:  {$stats['tcp6']['time_wait']}" . PHP_EOL;
            echo "  Allocated:  {$stats['tcp6']['allocated']}" . PHP_EOL;
            echo "  Memory:     {$stats['tcp6']['memory']} pages" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['udp6']) && $stats['udp6']['in_use'] > 0) {
            echo "UDP6:" . PHP_EOL;
            echo "  In use:     {$stats['udp6']['in_use']}" . PHP_EOL;
            echo "  Memory:     {$stats['udp6']['memory']} pages" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['unix']) && $stats['unix']['in_use'] > 0) {
            echo "UNIX:" . PHP_EOL;
            echo "  In use:     {$stats['unix']['in_use']}" . PHP_EOL;
            echo "  Dynamic:    {$stats['unix']['dynamic']}" . PHP_EOL;
            echo "  Inode:      {$stats['unix']['inode']}" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['netlink']) && $stats['netlink']['in_use'] > 0) {
            echo "Netlink:" . PHP_EOL;
            echo "  In use:     {$stats['netlink']['in_use']}" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['packet']) && $stats['packet']['in_use'] > 0) {
            echo "Packet:" . PHP_EOL;
            echo "  In use:     {$stats['packet']['in_use']}" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['icmp']) && $stats['icmp']['inuse'] > 0) {
            echo "ICMP:" . PHP_EOL;
            echo "  In use:     {$stats['icmp']['inuse']}" . PHP_EOL . PHP_EOL;
        }
        
        if (isset($stats['icmp6']) && $stats['icmp6']['inuse'] > 0) {
            echo "ICMP6:" . PHP_EOL;
            echo "  In use:     {$stats['icmp6']['inuse']}" . PHP_EOL . PHP_EOL;
        }
    }

    private function showPerformanceMetrics() {
        $endTime = microtime(true);
        $executionTime = round($endTime - $this->startTime, 4);
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        
        $metrics = array(
            'performance' => array(
                'execution_time_seconds' => $executionTime,
                'peak_memory_mb' => $memoryUsage,
                'php_version' => PHP_VERSION
            )
        );
        
        if ($this->config['json_output']) {
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
