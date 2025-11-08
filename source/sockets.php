#!/usr/bin/env php
<?php

/**
 * Socket Statistics Tool - PHP version
 * Reads and displays socket statistics from /proc/net/sockstat (Linux)
 *
 * Features:
 * - JSON and human-readable output formats
 * - Configurable logging levels
 * - Flexible file path configuration
 * - Comprehensive error handling
 * - Performance monitoring
 */

class SocketStatsTool {
    private $config;
    private $logLevels;
    private $sockstatPath;
    private $startTime;
    
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
            'show_performance' => false
        );

        $this->startTime = microtime(true);
    }
    
    public function run() {
        $this->parseCommandLine();
        
        if ($this->config['help']) {
            $this->showHelp();
            return;
        }
        
        try {
            $this->validateConfig();
            $this->checkSockstatFile();
            
            $stats = $this->getSocketStats();
            $this->displayStats($stats);

            if ($this->config['show_performance']) {
                $this->showPerformanceMetrics();
            }
            
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
            'version'
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
    }
    
    private function checkSockstatFile() {
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
        echo "Socket Statistics Tool 1.0.0" . PHP_EOL;
        echo "PHP " . PHP_VERSION . PHP_EOL;
    }
    
    private function showHelp() {
        global $argv;
        $scriptName = basename($argv[0]);
        
        $helpText = "Socket Statistics Tool 1.0.0\n\n" .
                   "Usage: {$scriptName} [OPTIONS]\n\n" .
                   "Options:\n" .
                   "  --json                 Output socket summary in JSON format\n" .
                   "  --log-level LEVEL      Set log level (DEBUG, INFO, WARNING, ERROR)\n" .
                   "  --path PATH            Path to sockstat file (default: /proc/net/sockstat)\n" .
                   "  --performance          Show performance metrics\n" .
                   "  --version              Display version information\n" .
                   "  --help                 Display this help message\n\n" .
                   "Examples:\n" .
                   "  {$scriptName} --json\n" .
                   "  {$scriptName} --log-level DEBUG\n" .
                   "  {$scriptName} --json --log-level WARNING\n" .
                   "  {$scriptName} --path /tmp/test-sockstat --json\n" .
                   "  {$scriptName} --performance --json\n\n";
        
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
        
        $lines = file($this->sockstatPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read {$this->sockstatPath}");
        }
        
        $lineCount = 0;
        foreach ($lines as $line) {
            $this->parseLine($line, $stats);
            $lineCount++;
        }

        $this->logMessage('DEBUG', "Processed {$lineCount} lines from sockstat file");
        
        return $stats;
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
                $this->parseTCP($parts, $stats);
                break;
                
            case 'UDP:':
                $this->parseUDP($parts, $stats);
                break;
                
            case 'UDPLITE:':
                $this->parseUDPLite($parts, $stats);
                break;
                
            case 'RAW:':
                $this->parseRAW($parts, $stats);
                break;
                
            case 'FRAG:':
                $this->parseFRAG($parts, $stats);
                break;

            default:
                $this->logMessage('DEBUG', "Unknown section: {$parts[0]}");
                break;
        }
    }
    
    private function parseTCP($parts, &$stats) {
        $mapping = array(
            'inuse' => 'in_use',
            'orphan' => 'orphan',
            'tw' => 'time_wait',
            'alloc' => 'allocated',
            'mem' => 'memory'
        );
        
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            $key = $parts[$i];
            $value = $parts[$i + 1];
            
            if (isset($mapping[$key])) {
                $stats['tcp'][$mapping[$key]] = $this->parseInt($value);
            } else {
                $this->logMessage('DEBUG', "Unknown TCP field: {$key}");
            }
        }
    }
    
    private function parseUDP($parts, &$stats) {
        $mapping = array(
            'inuse' => 'in_use',
            'mem' => 'memory'
        );
        
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            $key = $parts[$i];
            $value = $parts[$i + 1];
            
            if (isset($mapping[$key])) {
                $stats['udp'][$mapping[$key]] = $this->parseInt($value);
            } else {
                $this->logMessage('DEBUG', "Unknown UDP field: {$key}");
            }
        }
    }
    
    private function parseUDPLite($parts, &$stats) {
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            if ($parts[$i] === 'inuse') {
                $stats['udp_lite']['in_use'] = $this->parseInt($parts[$i + 1]);
            }
        }
    }
    
    private function parseRAW($parts, &$stats) {
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            if ($parts[$i] === 'inuse') {
                $stats['raw']['in_use'] = $this->parseInt($parts[$i + 1]);
            }
        }
    }
    
    private function parseFRAG($parts, &$stats) {
        $mapping = array(
            'inuse' => 'in_use',
            'memory' => 'memory'
        );
        
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($i + 1 >= count($parts)) {
                break;
            }
            
            $key = $parts[$i];
            $value = $parts[$i + 1];
            
            if (isset($mapping[$key])) {
                $stats['frag'][$mapping[$key]] = $this->parseInt($value);
            } else {
                $this->logMessage('DEBUG', "Unknown FRAG field: {$key}");
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
        $jsonOptions = JSON_PRETTY_PRINT;
        if (!$this->config['show_performance']) {
            $jsonOptions |= JSON_UNESCAPED_SLASHES;
        }
        
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
            echo json_encode($metrics, JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo PHP_EOL . "Performance Metrics:" . PHP_EOL;
            echo "===================" . PHP_EOL;
            echo "Execution time: {$executionTime}s" . PHP_EOL;
            echo "Peak memory:    {$memoryUsage} MB" . PHP_EOL;
            echo "PHP version:    " . PHP_VERSION . PHP_EOL;
        }
    }
}

// Run the application
if (PHP_SAPI === 'cli') {
    $app = new SocketStatsTool();
    $app->run();
} else {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}
