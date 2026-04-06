#!/usr/bin/env php
<?php

declare(strict_types=1);

final class ToolConfig
{
    public string $logLevel;
    public bool $jsonOutput;
    public bool $help;
    public bool $showPerformance;
    public bool $quiet;
    public bool $extended;
    public string $sockstatPath;
    public ?string $configFile;

    public function __construct(array $options = [])
    {
        $this->logLevel = 'INFO';
        $this->jsonOutput = false;
        $this->help = false;
        $this->showPerformance = false;
        $this->quiet = false;
        $this->extended = false;
        $this->sockstatPath = '/proc/net/sockstat';
        $this->configFile = null;

        $this->apply($options);
    }

    public function apply(array $options): void
    {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'log_level':
                case 'logLevel':
                    $this->logLevel = strtoupper((string) $value);
                    break;
                case 'json_output':
                case 'jsonOutput':
                    $this->jsonOutput = (bool) $value;
                    break;
                case 'help':
                    $this->help = (bool) $value;
                    break;
                case 'show_performance':
                case 'showPerformance':
                    $this->showPerformance = (bool) $value;
                    break;
                case 'quiet':
                    $this->quiet = (bool) $value;
                    break;
                case 'extended':
                    $this->extended = (bool) $value;
                    break;
                case 'sockstat_path':
                case 'sockstatPath':
                    $this->sockstatPath = (string) $value;
                    break;
                case 'config_file':
                case 'configFile':
                    $this->configFile = $value === null ? null : (string) $value;
                    break;
            }
        }
    }

    public function loadFromFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Config file not found: {$filePath}");
        }

        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new RuntimeException("Cannot resolve config file path: {$filePath}");
        }

        if (!$this->isConfigPathAllowed($realPath)) {
            throw new RuntimeException("Config file path not allowed: {$filePath}");
        }

        $config = parse_ini_file($realPath, false, INI_SCANNER_TYPED);
        if ($config === false) {
            throw new RuntimeException("Invalid config file format");
        }

        if (!is_array($config)) {
            throw new RuntimeException("Invalid config file structure");
        }

        $mapped = [];

        foreach ($config as $key => $value) {
            switch ($key) {
                case 'log_level':
                    $mapped['logLevel'] = strtoupper((string) $value);
                    break;
                case 'json_output':
                    $mapped['jsonOutput'] = (bool) $value;
                    break;
                case 'help':
                    $mapped['help'] = (bool) $value;
                    break;
                case 'show_performance':
                    $mapped['showPerformance'] = (bool) $value;
                    break;
                case 'quiet':
                    $mapped['quiet'] = (bool) $value;
                    break;
                case 'extended':
                    $mapped['extended'] = (bool) $value;
                    break;
                case 'sockstat_path':
                    $mapped['sockstatPath'] = (string) $value;
                    break;
                case 'config_file':
                    $mapped['configFile'] = $value === null ? null : (string) $value;
                    break;
            }
        }

        $this->apply($mapped);
    }

    private function isConfigPathAllowed(string $realPath): bool
    {
        $allowedPaths = [];

        if (is_dir('/etc/socket-stats/')) {
            $resolved = realpath('/etc/socket-stats/');
            if ($resolved !== false) {
                $allowedPaths[] = $resolved;
            }
        }

        $home = getenv('HOME');
        if ($home && is_dir($home . '/.config/socket-stats/')) {
            $resolved = realpath($home . '/.config/socket-stats/');
            if ($resolved !== false) {
                $allowedPaths[] = $resolved;
            }
        }

        $localConfig = __DIR__ . '/config/';
        if (is_dir($localConfig)) {
            $resolved = realpath($localConfig);
            if ($resolved !== false) {
                $allowedPaths[] = $resolved;
            }
        }

        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($realPath . '/', $allowed . '/')) {
                return true;
            }
        }

        return false;
    }
}

final class ProtocolStats implements JsonSerializable
{
    private string $name;
    private array $fields = [];

    public function __construct(string $name, array $defaults = [])
    {
        $this->name = $name;
        foreach ($defaults as $key => $value) {
            $this->fields[$key] = (int) $value;
        }
    }

    public function set(string $field, int $value): void
    {
        $this->fields[$field] = $value;
    }

    public function get(string $field, int $default = 0): int
    {
        return isset($this->fields[$field]) ? (int) $this->fields[$field] : $default;
    }

    public function hasPositiveInUse(): bool
    {
        return $this->get('in_use', 0) > 0;
    }

    public function isEmpty(): bool
    {
        foreach ($this->fields as $value) {
            if ((int) $value !== 0) {
                return false;
            }
        }
        return true;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return $this->fields;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final class Metadata implements JsonSerializable
{
    public function __construct(
        public readonly string $source,
        public readonly string $generatedAt,
        public readonly string $hostname
    ) {
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'generated_at' => $this->generatedAt,
            'hostname' => $this->hostname,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final class PerformanceMetrics implements JsonSerializable
{
    public function __construct(
        public readonly float $executionTimeSeconds,
        public readonly float $peakMemoryMb,
        public readonly string $phpVersion
    ) {
    }

    public function toArray(): array
    {
        return [
            'execution_time_seconds' => $this->executionTimeSeconds,
            'peak_memory_mb' => $this->peakMemoryMb,
            'php_version' => $this->phpVersion,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final class SocketStatsReport implements JsonSerializable
{
    public Metadata $metadata;
    public int $socketsUsed = 0;
    /** @var array<string, ProtocolStats> */
    private array $protocols = [];
    /** @var array<string, int> */
    private array $tcpExt = [];

    public function __construct(Metadata $metadata)
    {
        $this->metadata = $metadata;
    }

    public function setSocketsUsed(int $value): void
    {
        $this->socketsUsed = $value;
    }

    public function addProtocol(string $key, ProtocolStats $stats): void
    {
        $this->protocols[$key] = $stats;
    }

    public function hasProtocol(string $key): bool
    {
        return isset($this->protocols[$key]);
    }

    public function getProtocol(string $key): ProtocolStats
    {
        if (!isset($this->protocols[$key])) {
            throw new RuntimeException("Unknown protocol: {$key}");
        }
        return $this->protocols[$key];
    }

    public function tryGetProtocol(string $key): ?ProtocolStats
    {
        return $this->protocols[$key] ?? null;
    }

    public function setTcpExt(array $values): void
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = (int) $value;
        }
        $this->tcpExt = $normalized;
    }

    public function getTcpExt(): array
    {
        return $this->tcpExt;
    }

    public function getProtocols(): array
    {
        return $this->protocols;
    }

    public function toArray(): array
    {
        $data = [
            'metadata' => $this->metadata->toArray(),
            'sockets_used' => $this->socketsUsed,
        ];

        foreach ($this->protocols as $key => $protocol) {
            $data[$key] = $protocol->toArray();
        }

        if (!empty($this->tcpExt)) {
            $data['tcp_ext'] = $this->tcpExt;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

final class SocketStatsTool
{
    private const MAX_FILE_SIZE = 10485760;
    private const MAX_LINE_SIZE = 1048576;
    private const MAX_LOG_CACHE_SIZE = 1000;
    private const LOG_CACHE_TTL = 300;

    private ToolConfig $config;
    /** @var array<string, int> */
    private array $logLevels;
    private float $startTime;
    private bool $shutdownRequested = false;
    /** @var array<string, string> */
    private array $logCache = [];
    /** @var array<string, int> */
    private array $logCacheTime = [];
    /** @var array<string, callable>|null */
    private ?array $protocolParsers = null;

    public function __construct()
    {
        $this->logLevels = [
            'DEBUG' => 0,
            'INFO' => 1,
            'WARNING' => 2,
            'ERROR' => 3,
        ];

        $this->config = new ToolConfig();
        $this->startTime = microtime(true);
    }

    public function signalHandler(int $signo): void
    {
        $this->shutdownRequested = true;
    }

    public function run(): void
    {
        $this->bootstrapSignals();

        try {
            $this->parseCommandLine();

            if ($this->config->help) {
                $this->showHelp();
                exit(0);
            }

            if ($this->config->configFile !== null) {
                $this->config->loadFromFile($this->config->configFile);
            }

            $this->validateConfig();

            $report = $this->getSocketStats();

            if (!$this->config->quiet) {
                $this->displayStats($report);
            }

            if ($this->config->showPerformance) {
                $this->displayPerformanceMetrics($this->buildPerformanceMetrics());
            }

            exit(0);
        } catch (Throwable $e) {
            $this->logMessage('ERROR', $e->getMessage());
            exit(1);
        }
    }

    private function bootstrapSignals(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGHUP, [$this, 'signalHandler']);
    }

    private function parseCommandLine(): void
    {
        $options = getopt('', [
            'json',
            'log-level:',
            'help',
            'path:',
            'performance',
            'version',
            'quiet',
            'extended',
            'config:',
        ]);

        if (isset($options['version'])) {
            $this->showVersion();
            exit(0);
        }

        $configUpdates = [];

        if (isset($options['json'])) {
            $configUpdates['jsonOutput'] = true;
        }

        if (isset($options['log-level'])) {
            $configUpdates['logLevel'] = strtoupper((string) $options['log-level']);
        }

        if (isset($options['help'])) {
            $configUpdates['help'] = true;
        }

        if (isset($options['path'])) {
            $configUpdates['sockstatPath'] = $this->normalizePath((string) $options['path']);
        }

        if (isset($options['performance'])) {
            $configUpdates['showPerformance'] = true;
        }

        if (isset($options['quiet'])) {
            $configUpdates['quiet'] = true;
        }

        if (isset($options['extended'])) {
            $configUpdates['extended'] = true;
        }

        if (isset($options['config'])) {
            $configUpdates['configFile'] = $this->normalizePath((string) $options['config']);
        }

        $this->config = new ToolConfig($configUpdates);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace("\0", '', trim($path));
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        if (!is_string($path)) {
            return '';
        }

        return $path;
    }

    private function validateConfig(): void
    {
        if (!isset($this->logLevels[$this->config->logLevel])) {
            throw new RuntimeException(
                "Invalid log level: {$this->config->logLevel}. Valid levels: " . implode(', ', array_keys($this->logLevels))
            );
        }

        if ($this->config->sockstatPath === '') {
            throw new RuntimeException("Invalid socket statistics path");
        }

        if (strpos($this->config->sockstatPath, "\0") !== false) {
            throw new RuntimeException("Invalid path: contains null byte");
        }

        if (!preg_match('/^[a-zA-Z0-9\/\.\-_]+$/', $this->config->sockstatPath)) {
            throw new RuntimeException("Invalid path format: contains invalid characters");
        }
    }

    private function safeFileOpen(string $path): SplFileObject
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            throw new RuntimeException("Cannot resolve path: {$path}");
        }

        if (!$this->isPathAllowed($resolvedPath)) {
            throw new RuntimeException("Path not allowed: {$path}");
        }

        clearstatcache(true, $resolvedPath);

        if (is_link($path)) {
            $linkTarget = readlink($path);
            if ($linkTarget !== false) {
                $resolvedTarget = realpath($linkTarget) ?: $linkTarget;
                if (!$this->isPathAllowed($resolvedTarget)) {
                    throw new RuntimeException("Symbolic link target not allowed: {$path}");
                }
            }
        }

        $size = @filesize($resolvedPath);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException("File size exceeds limit: {$path}");
        }

        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Cannot access file {$path}");
        }

        try {
            $file = new SplFileObject($resolvedPath, 'r');
            $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
            return $file;
        } catch (RuntimeException $e) {
            throw new RuntimeException("Cannot access file {$path}: " . $e->getMessage());
        }
    }

    private function isPathAllowed(string $realPath): bool
    {
        $allowedDirs = [
            realpath('/proc/net') ?: '/proc/net',
            realpath('/proc') ?: '/proc',
            realpath('/tmp/socket-stats') ?: '/tmp/socket-stats',
        ];

        foreach ($allowedDirs as $allowedDir) {
            if (str_starts_with($realPath . '/', $allowedDir . '/')) {
                return true;
            }
        }

        return false;
    }

    private function logMessage(string $level, string $message): void
    {
        if ($this->shutdownRequested) {
            return;
        }

        if ($level === 'DEBUG') {
            $cacheKey = md5($message);
            $now = time();

            if (count($this->logCache) > self::MAX_LOG_CACHE_SIZE) {
                $this->cleanLogCache();
            }

            if (isset($this->logCache[$cacheKey], $this->logCacheTime[$cacheKey])) {
                if (($now - $this->logCacheTime[$cacheKey]) < self::LOG_CACHE_TTL) {
                    return;
                }
            }

            $this->logCache[$cacheKey] = $message;
            $this->logCacheTime[$cacheKey] = $now;
        }

        $msgLevel = $this->logLevels[$level] ?? $this->logLevels['INFO'];
        $confLevel = $this->logLevels[$this->config->logLevel];

        if ($msgLevel < $confLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents('php://stderr', "[{$timestamp}] {$level}: {$message}" . PHP_EOL);
    }

    private function cleanLogCache(): void
    {
        $now = time();

        foreach ($this->logCacheTime as $key => $timestamp) {
            if (($now - $timestamp) > self::LOG_CACHE_TTL) {
                unset($this->logCache[$key], $this->logCacheTime[$key]);
            }
        }

        if (count($this->logCache) <= self::MAX_LOG_CACHE_SIZE) {
            return;
        }

        asort($this->logCacheTime);
        $excess = count($this->logCache) - self::MAX_LOG_CACHE_SIZE;
        $keys = array_keys($this->logCacheTime);

        for ($i = 0; $i < $excess; $i++) {
            $key = $keys[$i];
            unset($this->logCache[$key], $this->logCacheTime[$key]);
        }
    }

    private function showVersion(): void
    {
        echo "Socket Statistics Tool 1.3.0" . PHP_EOL;
        echo "PHP " . PHP_VERSION . PHP_EOL;
    }

    private function showHelp(): void
    {
        $scriptName = basename($_SERVER['argv'][0] ?? 'tool.php');

        $helpText =
            "Socket Statistics Tool 1.3.0\n\n" .
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

    private function getSocketStats(): SocketStatsReport
    {
        $this->logMessage('INFO', "Reading socket statistics from {$this->config->sockstatPath}");

        $file = $this->safeFileOpen($this->config->sockstatPath);

        $report = new SocketStatsReport(
            new Metadata(
                source: $this->config->sockstatPath,
                generatedAt: date('c'),
                hostname: gethostname() ?: 'unknown'
            )
        );

        $report->addProtocol('tcp', new ProtocolStats('TCP', [
            'in_use' => 0,
            'orphan' => 0,
            'time_wait' => 0,
            'allocated' => 0,
            'memory' => 0,
        ]));
        $report->addProtocol('udp', new ProtocolStats('UDP', [
            'in_use' => 0,
            'memory' => 0,
        ]));
        $report->addProtocol('udp_lite', new ProtocolStats('UDPLite', [
            'in_use' => 0,
        ]));
        $report->addProtocol('raw', new ProtocolStats('RAW', [
            'in_use' => 0,
        ]));
        $report->addProtocol('frag', new ProtocolStats('FRAG', [
            'in_use' => 0,
            'memory' => 0,
        ]));

        if ($this->config->extended) {
            $this->initializeExtendedStats($report);
        }

        $this->readSockstatFile($file, $report);

        if ($this->config->extended) {
            $this->loadExtendedProtocolInfo($report);
        }

        return $report;
    }

    private function readSockstatFile(SplFileObject $file, SocketStatsReport $report): void
    {
        $lineCount = 0;

        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }

            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $this->parseLine($line, $report);
            $lineCount++;

            if ($lineCount > 1000) {
                throw new RuntimeException("File appears too large or malformed");
            }
        }

        $this->logMessage('DEBUG', "Processed {$lineCount} lines from sockstat file");
    }

    private function getProtocolParsers(): array
    {
        if ($this->protocolParsers !== null) {
            return $this->protocolParsers;
        }

        $this->protocolParsers = [
            'sockets:' => function (array $parts, SocketStatsReport $report): void {
                if (count($parts) >= 3) {
                    $report->setSocketsUsed($this->parseInt($parts[2]));
                }
            },
            'TCP:' => function (array $parts, SocketStatsReport $report): void {
                $this->parseProtocolSection($parts, $report, 'tcp', [
                    'inuse' => 'in_use',
                    'orphan' => 'orphan',
                    'tw' => 'time_wait',
                    'alloc' => 'allocated',
                    'mem' => 'memory',
                ]);
            },
            'UDP:' => function (array $parts, SocketStatsReport $report): void {
                $this->parseProtocolSection($parts, $report, 'udp', [
                    'inuse' => 'in_use',
                    'mem' => 'memory',
                ]);
            },
            'UDPLITE:' => function (array $parts, SocketStatsReport $report): void {
                $this->parseProtocolSection($parts, $report, 'udp_lite', [
                    'inuse' => 'in_use',
                ]);
            },
            'RAW:' => function (array $parts, SocketStatsReport $report): void {
                $this->parseProtocolSection($parts, $report, 'raw', [
                    'inuse' => 'in_use',
                ]);
            },
            'FRAG:' => function (array $parts, SocketStatsReport $report): void {
                $this->parseProtocolSection($parts, $report, 'frag', [
                    'inuse' => 'in_use',
                    'memory' => 'memory',
                ]);
            },
            'TCP6:' => function (array $parts, SocketStatsReport $report): void {
                if ($this->config->extended && $report->hasProtocol('tcp6')) {
                    $this->parseProtocolSection($parts, $report, 'tcp6', [
                        'inuse' => 'in_use',
                        'orphan' => 'orphan',
                        'tw' => 'time_wait',
                        'alloc' => 'allocated',
                        'mem' => 'memory',
                    ]);
                }
            },
            'UDP6:' => function (array $parts, SocketStatsReport $report): void {
                if ($this->config->extended && $report->hasProtocol('udp6')) {
                    $this->parseProtocolSection($parts, $report, 'udp6', [
                        'inuse' => 'in_use',
                        'mem' => 'memory',
                    ]);
                }
            },
        ];

        return $this->protocolParsers;
    }

    private function parseLine(string $line, SocketStatsReport $report): void
    {
        if (strlen($line) > self::MAX_LINE_SIZE) {
            $this->logMessage('WARNING', "Line too long, skipping");
            return;
        }

        $parts = preg_split('/\s+/', trim($line));
        if ($parts === false || count($parts) < 2) {
            $this->logMessage('DEBUG', "Skipping malformed line: {$line}");
            return;
        }

        $parsers = $this->getProtocolParsers();
        $section = $parts[0];

        if (isset($parsers[$section])) {
            $parsers[$section]($parts, $report);
            return;
        }

        $this->logMessage('DEBUG', "Unknown section: {$section}");
    }

    private function initializeExtendedStats(SocketStatsReport $report): void
    {
        $report->addProtocol('tcp6', new ProtocolStats('TCP6', [
            'in_use' => 0,
            'orphan' => 0,
            'time_wait' => 0,
            'allocated' => 0,
            'memory' => 0,
        ]));
        $report->addProtocol('udp6', new ProtocolStats('UDP6', [
            'in_use' => 0,
            'memory' => 0,
        ]));
        $report->addProtocol('unix', new ProtocolStats('UNIX', [
            'in_use' => 0,
            'dynamic' => 0,
            'inode' => 0,
        ]));
        $report->addProtocol('icmp', new ProtocolStats('ICMP', [
            'in_use' => 0,
        ]));
        $report->addProtocol('icmp6', new ProtocolStats('ICMP6', [
            'in_use' => 0,
        ]));
        $report->addProtocol('netlink', new ProtocolStats('Netlink', [
            'in_use' => 0,
        ]));
        $report->addProtocol('packet', new ProtocolStats('Packet', [
            'in_use' => 0,
            'memory' => 0,
        ]));
    }

    private function loadExtendedProtocolInfo(SocketStatsReport $report): void
    {
        $filesToLoad = [
            'unix' => ['path' => '/proc/net/sockstat6', 'section' => 'UNIX:', 'type' => 'section'],
            'netlink' => ['path' => '/proc/net/netlink', 'type' => 'count'],
            'packet' => ['path' => '/proc/net/packet', 'type' => 'count'],
            'icmp' => ['path' => '/proc/net/snmp', 'type' => 'snmp'],
            'icmp6' => ['path' => '/proc/net/snmp', 'type' => 'snmp6'],
        ];

        foreach ($filesToLoad as $protocol => $info) {
            try {
                $file = $this->safeFileOpen($info['path']);

                if ($info['type'] === 'count') {
                    $count = $this->countFileLines($file);
                    $stats = $report->tryGetProtocol($protocol);
                    if ($stats !== null) {
                        $stats->set('in_use', $count);
                    }
                } elseif ($info['type'] === 'section') {
                    $this->loadProtocolFile($file, (string) $info['section'], $report, $protocol, [
                        'inuse' => 'in_use',
                        'dynamic' => 'dynamic',
                        'inode' => 'inode',
                    ]);
                } elseif ($info['type'] === 'snmp') {
                    $count = $this->loadSnmpInfo($file, 'Icmp:');
                    $stats = $report->tryGetProtocol('icmp');
                    if ($stats !== null) {
                        $stats->set('in_use', $count);
                    }
                } elseif ($info['type'] === 'snmp6') {
                    $count = $this->loadSnmpInfo($file, 'Icmp6:');
                    $stats = $report->tryGetProtocol('icmp6');
                    if ($stats !== null) {
                        $stats->set('in_use', $count);
                    }
                }
            } catch (RuntimeException $e) {
                $this->logMessage('DEBUG', "Could not load {$protocol} info: " . $e->getMessage());
            }
        }

        $this->loadAdditionalNetworkStats($report);
    }

    private function countFileLines(SplFileObject $file): int
    {
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

        return $lineCount;
    }

    private function loadProtocolFile(SplFileObject $file, string $section, SocketStatsReport $report, string $protocol, array $mapping): void
    {
        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }

            $line = trim((string) $line);
            if (!str_starts_with($line, $section)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts !== false) {
                $this->parseProtocolSection($parts, $report, $protocol, $mapping);
            }
            break;
        }
    }

    private function loadSnmpInfo(SplFileObject $file, string $targetLine): int
    {
        $inTargetLine = false;

        foreach ($file as $line) {
            if ($this->shutdownRequested) {
                break;
            }

            $line = trim((string) $line);

            if (str_starts_with($line, $targetLine)) {
                $inTargetLine = true;
                continue;
            }

            if ($inTargetLine) {
                $parts = preg_split('/\s+/', $line);
                if ($parts !== false && count($parts) > 0) {
                    return $this->parseInt($parts[0]);
                }
                break;
            }
        }

        return 0;
    }

    private function parseTcpExtendedStats(string $line, SocketStatsReport $report): void
    {
        if (!$this->config->extended) {
            return;
        }

        $parts = preg_split('/\s+/', trim($line));
        if ($parts === false || count($parts) < 2) {
            return;
        }

        $values = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            if (!isset($parts[$i + 1])) {
                break;
            }

            $values[$parts[$i]] = $this->parseInt($parts[$i + 1]);
        }

        $report->setTcpExt($values);
    }

    private function parseProtocolSection(array $parts, SocketStatsReport $report, string $protocol, array $mapping): void
    {
        $stats = $report->tryGetProtocol($protocol);
        if ($stats === null) {
            $stats = new ProtocolStats($protocol);
            $report->addProtocol($protocol, $stats);
        }

        for ($i = 1; $i < count($parts); $i += 2) {
            if (!isset($parts[$i + 1])) {
                break;
            }

            $key = $parts[$i];
            $value = $parts[$i + 1];

            if (isset($mapping[$key])) {
                $stats->set($mapping[$key], $this->parseInt($value));
            } else {
                $this->logMessage('DEBUG', "Unknown {$protocol} field: {$key}");
            }
        }
    }

    private function parseInt(string $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new RuntimeException("Failed to parse integer '{$value}'");
        }

        return $parsed;
    }

    private function displayStats(SocketStatsReport $report): void
    {
        if ($this->config->jsonOutput) {
            $this->outputJSON($report);
            return;
        }

        $this->outputHumanReadable($report);
    }

    private function outputJSON(SocketStatsReport $report): void
    {
        $jsonData = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        echo $jsonData . PHP_EOL;
    }

    private function outputHumanReadable(SocketStatsReport $report): void
    {
        $useColor = $this->detectTerminalCapabilities() === 'color';
        $this->outputFormatted($report, $useColor);
    }

    private function detectTerminalCapabilities(): string
    {
        if (!function_exists('posix_isatty') || !posix_isatty(STDOUT)) {
            return 'plain';
        }

        $term = getenv('TERM');
        if (is_string($term) && ($term !== '')) {
            if (str_contains($term, 'xterm') || str_contains($term, 'color')) {
                return 'color';
            }
        }

        return 'plain';
    }

    private function outputFormatted(SocketStatsReport $report, bool $useColor): void
    {
        $colorStart = $useColor ? "\033[1;36m" : '';
        $colorEnd = $useColor ? "\033[0m" : '';
        $titleColor = $useColor ? "\033[1;33m" : '';
        $sectionColor = $useColor ? "\033[1;35m" : '';
        $fieldColor = $useColor ? "\033[1;34m" : '';

        echo "{$colorStart}Socket Statistics{$colorEnd}" . PHP_EOL;
        echo "{$colorStart}================={$colorEnd}" . PHP_EOL;
        echo "{$titleColor}Generated:{$colorEnd} {$report->metadata->generatedAt}" . PHP_EOL;
        echo "{$titleColor}Hostname:{$colorEnd}  {$report->metadata->hostname}" . PHP_EOL;
        echo "{$titleColor}Source:{$colorEnd}    {$report->metadata->source}" . PHP_EOL;
        echo PHP_EOL;

        echo "{$titleColor}Sockets used:{$colorEnd} {$report->socketsUsed}" . PHP_EOL . PHP_EOL;

        $baseProtocols = ['tcp', 'udp', 'udp_lite', 'raw', 'frag'];
        foreach ($baseProtocols as $key) {
            $protocol = $report->tryGetProtocol($key);
            if ($protocol !== null) {
                $this->outputProtocol($protocol->getName(), $protocol, $sectionColor, $fieldColor, $colorEnd);
            }
        }

        if ($this->config->extended) {
            $this->outputExtended($report, $titleColor, $sectionColor, $fieldColor, $colorEnd);
        }
    }

    private function outputProtocol(string $name, ProtocolStats $protocol, string $sectionColor, string $fieldColor, string $colorEnd): void
    {
        if ($protocol->isEmpty()) {
            return;
        }

        echo "{$sectionColor}{$name}:{$colorEnd}" . PHP_EOL;

        foreach ($protocol->toArray() as $field => $value) {
            $fieldName = ucwords(str_replace('_', ' ', $field));
            $padding = str_repeat(' ', max(1, 12 - strlen($fieldName)));
            echo "  {$fieldColor}{$fieldName}:{$colorEnd}{$padding}{$value}" . PHP_EOL;
        }

        echo PHP_EOL;
    }

    private function outputExtended(SocketStatsReport $report, string $titleColor, string $sectionColor, string $fieldColor, string $colorEnd): void
    {
        echo PHP_EOL . "{$titleColor}Extended Protocol Information:{$colorEnd}" . PHP_EOL;
        echo "{$titleColor}============================={$colorEnd}" . PHP_EOL;

        $extendedProtocols = ['tcp6', 'udp6', 'unix', 'netlink', 'packet', 'icmp', 'icmp6'];

        foreach ($extendedProtocols as $key) {
            $protocol = $report->tryGetProtocol($key);
            if ($protocol === null) {
                continue;
            }

            if ($protocol->hasPositiveInUse()) {
                $this->outputProtocol($protocol->getName(), $protocol, $sectionColor, $fieldColor, $colorEnd);
            }
        }

        $tcpExt = $report->getTcpExt();
        if (!empty($tcpExt)) {
            echo "{$sectionColor}TcpExt:{$colorEnd}" . PHP_EOL;
            foreach ($tcpExt as $field => $value) {
                echo "  {$fieldColor}{$field}:{$colorEnd} {$value}" . PHP_EOL;
            }
            echo PHP_EOL;
        }
    }

    private function loadAdditionalNetworkStats(SocketStatsReport $report): void
    {
        try {
            $file = $this->safeFileOpen('/proc/net/netstat');

            foreach ($file as $line) {
                if ($this->shutdownRequested) {
                    break;
                }

                $line = trim((string) $line);

                if (str_starts_with($line, 'TcpExt:')) {
                    $this->parseTcpExtendedStats($line, $report);
                }
            }
        } catch (RuntimeException $e) {
            $this->logMessage('DEBUG', "Could not read extended network stats: " . $e->getMessage());
        }
    }

    private function buildPerformanceMetrics(): PerformanceMetrics
    {
        $executionTime = round(microtime(true) - $this->startTime, 4);
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        return new PerformanceMetrics(
            executionTimeSeconds: $executionTime,
            peakMemoryMb: $memoryUsage,
            phpVersion: PHP_VERSION
        );
    }

    private function displayPerformanceMetrics(PerformanceMetrics $metrics): void
    {
        if ($this->config->jsonOutput) {
            $jsonData = json_encode(['performance' => $metrics], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonData === false) {
                throw new RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
            }
            echo $jsonData . PHP_EOL;
            return;
        }

        $useColor = $this->detectTerminalCapabilities() === 'color';
        $colorStart = $useColor ? "\033[1;36m" : '';
        $colorEnd = $useColor ? "\033[0m" : '';
        $titleColor = $useColor ? "\033[1;33m" : '';

        echo PHP_EOL . "{$colorStart}Performance Metrics:{$colorEnd}" . PHP_EOL;
        echo "{$colorStart}==================={$colorEnd}" . PHP_EOL;
        echo "{$titleColor}Execution time:{$colorEnd} {$metrics->executionTimeSeconds}s" . PHP_EOL;
        echo "{$titleColor}Peak memory:{$colorEnd}    {$metrics->peakMemoryMb} MB" . PHP_EOL;
        echo "{$titleColor}PHP version:{$colorEnd}    {$metrics->phpVersion}" . PHP_EOL;
    }
}

if (PHP_SAPI === 'cli') {
    $app = new SocketStatsTool();
    $app->run();
} else {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}
