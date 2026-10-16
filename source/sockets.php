#!/usr/bin/env php
<?php
declare(strict_types=1);

const VERSION = '2.0.0';
const TCP_STATES = ['01'=>'ESTABLISHED','02'=>'SYN_SENT','03'=>'SYN_RECV','04'=>'FIN_WAIT1','05'=>'FIN_WAIT2','06'=>'TIME_WAIT','07'=>'CLOSE','08'=>'CLOSE_WAIT','09'=>'LAST_ACK','0A'=>'LISTEN','0B'=>'CLOSING','0C'=>'NEW_SYN_RECV'];
const TABLES = ['tcp'=>['TCP',4],'tcp6'=>['TCP',6],'udp'=>['UDP',4],'udp6'=>['UDP',6],'udplite'=>['UDPLITE',4],'udplite6'=>['UDPLITE',6],'raw'=>['RAW',4],'raw6'=>['RAW',6]];
const IMPORTANT = ['Tcp'=>['ActiveOpens','PassiveOpens','AttemptFails','EstabResets','InSegs','OutSegs','RetransSegs','InErrs','OutRsts'],'Udp'=>['InDatagrams','NoPorts','InErrors','OutDatagrams','RcvbufErrors','SndbufErrors'],'Ip'=>['InReceives','InDiscards','InDelivers','OutRequests','OutDiscards'],'TcpExt'=>['ListenOverflows','ListenDrops','TCPTimeouts','TCPSynRetrans','TCPLossProbes','TCPBacklogDrop','TCPMemoryPressures']];

final class Options {
    public string $root = '/proc';
    public string $format = 'human';
    public ?string $output = null;
    public bool $extended = false;
    public bool $processes = false;
    public int $processLimit = 20;
    public bool $watch = false;
    public float $interval = 2.0;
    public int $count = 0;
    public bool $performance = false;
    public bool $quiet = false;
    public bool $verbose = false;
    public int $maxBytes = 33554432;
    public array $thresholds = [];

    public static function parse(array $argv): self {
        $o = new self();
        $args = array_slice($argv, 1);
        $values = ['interval','count','proc-root','path','output','process-limit','max-file-bytes','threshold-tcp','threshold-established','threshold-timewait','threshold-orphan','threshold-retrans','config','log-level'];
        $flags = ['json','csv','extended','processes','watch','performance','quiet','verbose','help','version'];
        $raw = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                throw new RuntimeException("Unknown argument: $arg");
            }
            $pair = explode('=', substr($arg, 2), 2);
            $key = $pair[0];
            if (in_array($key, $flags, true)) {
                if (count($pair) !== 1) throw new RuntimeException("Unexpected value for --$key");
                $raw[$key] = true;
            } elseif (in_array($key, $values, true)) {
                $value = $pair[1] ?? $args[++$i] ?? null;
                if ($value === null || str_starts_with($value, '--')) throw new RuntimeException("Missing value for --$key");
                $raw[$key] = $value;
            } else {
                throw new RuntimeException("Unknown option --$key");
            }
        }
        if (isset($raw['help'])) {
            echo self::help();
            exit(0);
        }
        if (isset($raw['version'])) {
            echo 'Socket Statistics Tool '.VERSION.' | PHP '.PHP_VERSION.PHP_EOL;
            exit(0);
        }
        if (isset($raw['config'])) {
            $file = $raw['config'];
            if (!is_file($file) || !is_readable($file)) throw new RuntimeException("Cannot read config: $file");
            $ini = parse_ini_file($file, false, INI_SCANNER_TYPED);
            if (!is_array($ini)) throw new RuntimeException('Invalid INI configuration');
            foreach ($ini as $key => $value) {
                $key = str_replace('_', '-', (string)$key);
                if (!in_array($key, array_merge($flags, $values), true) || in_array($key, ['config','help','version'], true)) throw new RuntimeException("Unknown configuration key: $key");
                if (!array_key_exists($key, $raw)) $raw[$key] = $value;
            }
        }
        if (isset($raw['json'], $raw['csv'])) throw new RuntimeException('--json and --csv are mutually exclusive');
        $o->format = isset($raw['json']) && self::bool($raw['json']) ? 'json' : (isset($raw['csv']) && self::bool($raw['csv']) ? 'csv' : 'human');
        foreach (['extended','processes','watch','performance','quiet','verbose'] as $key) {
            if (array_key_exists($key, $raw)) $o->$key = self::bool($raw[$key]);
        }
        $o->root = (string)($raw['proc-root'] ?? '/proc');
        if (isset($raw['path'])) {
            $path = realpath((string)$raw['path']);
            if ($path === false || basename($path) !== 'sockstat' || basename(dirname($path)) !== 'net') throw new RuntimeException('--path must reference an existing net/sockstat');
            $o->root = dirname(dirname($path));
        }
        $root = realpath($o->root);
        if ($root === false || !is_dir($root)) throw new RuntimeException('Invalid proc root');
        $o->root = $root;
        $o->output = isset($raw['output']) ? (string)$raw['output'] : null;
        $o->interval = self::number($raw['interval'] ?? 2, 'interval', 0.001);
        $o->count = self::integer($raw['count'] ?? 0, 'count', 0);
        $o->processLimit = self::integer($raw['process-limit'] ?? 20, 'process-limit', 0);
        $o->maxBytes = self::integer($raw['max-file-bytes'] ?? 33554432, 'max-file-bytes', 1);
        foreach (['tcp','established','timewait','orphan'] as $key) {
            if (isset($raw['threshold-'.$key])) $o->thresholds[$key] = self::integer($raw['threshold-'.$key], 'threshold-'.$key, 0);
        }
        if (isset($raw['threshold-retrans'])) $o->thresholds['retrans'] = self::number($raw['threshold-retrans'], 'threshold-retrans', 0, 100);
        if (isset($raw['log-level']) && !in_array(strtoupper((string)$raw['log-level']), ['DEBUG','INFO','WARNING','ERROR'], true)) throw new RuntimeException('Invalid log level');
        return $o;
    }

    private static function bool(mixed $v): bool {
        if (is_bool($v)) return $v;
        $r = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($r === null) throw new RuntimeException('Invalid boolean option');
        return $r;
    }
    private static function integer(mixed $v, string $name, int $min): int {
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min) throw new RuntimeException("Invalid --$name");
        return $n;
    }
    private static function number(mixed $v, string $name, float $min, float $max = INF): float {
        if (!is_numeric($v)) throw new RuntimeException("Invalid --$name");
        $n = (float)$v;
        if (!is_finite($n) || $n < $min || $n > $max) throw new RuntimeException("Invalid --$name");
        return $n;
    }
    private static function help(): string {
        return 'Socket Statistics Tool '.VERSION."\nUsage: php main.php [OPTIONS]\n\n".
            "  --json | --csv          Machine-readable output\n".
            "  --extended              IPv6 and extended kernel counters\n".
            "  --processes             Inspect /proc/PID/fd socket ownership\n".
            "  --process-limit N       Maximum displayed processes (default 20)\n".
            "  --watch                 Repeated sampling\n".
            "  --interval SECONDS      Watch interval (default 2)\n".
            "  --count N               Watch samples, 0 for unlimited\n".
            "  --proc-root PATH        Alternative proc root\n".
            "  --path PATH             Alternative net/sockstat file\n".
            "  --output FILE           Write results to file\n".
            "  --performance           Include runtime metrics\n".
            "  --threshold-tcp N       TCP allocation warning\n".
            "  --threshold-established N\n".
            "  --threshold-timewait N\n".
            "  --threshold-orphan N\n".
            "  --threshold-retrans PCT Cumulative retransmission threshold\n".
            "  --max-file-bytes N      Per-file read limit\n".
            "  --config FILE           Optional flat INI configuration\n".
            "  --quiet --verbose --help --version\n";
    }
}

final class ProcReader {
    public array $warnings = [];
    public function __construct(private Options $options) {}
    public function lines(string $name, bool $required = false): array {
        $path = $this->options->root.'/'.$name;
        if (!is_file($path) || !is_readable($path)) {
            if ($required) throw new RuntimeException("Required proc file unavailable: $path");
            if ($this->options->verbose) $this->warnings[] = "Unavailable: $path";
            return [];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            if ($required) throw new RuntimeException("Cannot open $path");
            $this->warnings[] = "Cannot open $path";
            return [];
        }
        $lines = [];
        $total = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $total += strlen($line);
                if ($total > $this->options->maxBytes) throw new RuntimeException("Read limit exceeded: $path");
                $lines[] = rtrim($line, "\r\n");
            }
            if (!feof($handle)) throw new RuntimeException("Read failure: $path");
        } finally {
            fclose($handle);
        }
        return $lines;
    }
}

final class Parsers {
    public static function pairs(array $fields): array {
        $result = [];
        for ($i = 0; $i + 1 < count($fields); $i += 2) {
            if (preg_match('/^-?\d+$/D', $fields[$i + 1])) $result[$fields[$i]] = (int)$fields[$i + 1];
        }
        return $result;
    }
    public static function sockstat(array $lines): array {
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 3) $result[strtolower(rtrim(array_shift($parts), ':'))] = self::pairs($parts);
        }
        return $result;
    }
    public static function mib(array $lines): array {
        $result = [];
        $pending = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2) continue;
            $section = rtrim(array_shift($parts), ':');
            if (!isset($pending[$section])) {
                $pending[$section] = $parts;
                continue;
            }
            $names = $pending[$section];
            unset($pending[$section]);
            if (count($names) !== count($parts)) continue;
            foreach ($names as $i => $name) {
                if (preg_match('/^-?\d+$/D', $parts[$i])) $result[$section][$name] = (int)$parts[$i];
            }
        }
        return $result;
    }
    public static function inet(array $lines, string $protocol): array {
        $states = [];
        $inodes = [];
        $count = 0;
        foreach (array_slice($lines, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 10) continue;
            $count++;
            if ($protocol === 'TCP') {
                $state = TCP_STATES[strtoupper($parts[3])] ?? strtoupper($parts[3]);
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
            $inode = filter_var($parts[9], FILTER_VALIDATE_INT);
            if ($inode !== false && $inode > 0) $inodes[$inode] = true;
        }
        ksort($states);
        return ['count'=>$count,'states'=>$states,'inodes'=>$inodes];
    }
    public static function unix(array $lines): array {
        $types = [];
        $states = [];
        $inodes = [];
        $count = 0;
        $named = 0;
        $typeNames = ['0001'=>'STREAM','0002'=>'DGRAM','0005'=>'SEQPACKET'];
        $stateNames = ['01'=>'UNCONNECTED','02'=>'CONNECTING','03'=>'CONNECTED','04'=>'DISCONNECTING'];
        foreach (array_slice($lines, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line), 8);
            if (count($parts) < 7) continue;
            $count++;
            $type = $typeNames[$parts[4]] ?? $parts[4];
            $state = $stateNames[$parts[5]] ?? $parts[5];
            $types[$type] = ($types[$type] ?? 0) + 1;
            $states[$state] = ($states[$state] ?? 0) + 1;
            if (isset($parts[7]) && $parts[7] !== '') $named++;
            $inode = filter_var($parts[6], FILTER_VALIDATE_INT);
            if ($inode !== false && $inode > 0) $inodes[$inode] = true;
        }
        ksort($types);
        ksort($states);
        return ['count'=>$count,'types'=>$types,'states'=>$states,'named_paths'=>$named,'inodes'=>$inodes];
    }
    public static function count(array $lines): int {
        return count(array_filter(array_slice($lines, 1), static fn($line) => trim($line) !== ''));
    }
    public static function snmp6(array $lines): array {
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2 && preg_match('/^-?\d+$/D', $parts[1])) $result[$parts[0]] = (int)$parts[1];
        }
        return $result;
    }
}

final class Collector {
    public function __construct(private Options $options) {}
    public function collect(): array {
        $start = hrtime(true);
        $reader = new ProcReader($this->options);
        $sock4 = Parsers::sockstat($reader->lines('net/sockstat', true));
        $summary = [
            'sockets_used'=>$sock4['sockets']['used'] ?? 0,
            'tcp'=>$sock4['tcp'] ?? [], 'udp'=>$sock4['udp'] ?? [],
            'udplite'=>$sock4['udplite'] ?? [], 'raw'=>$sock4['raw'] ?? [],
            'frag'=>$sock4['frag'] ?? [],
        ];
        if ($this->options->extended) $summary['ipv6'] = Parsers::sockstat($reader->lines('net/sockstat6'));
        $tables = [];
        $inodes = [];
        $states = [];
        foreach (TABLES as $name => [$protocol, $family]) {
            if ($family === 6 && !$this->options->extended) continue;
            $table = Parsers::inet($reader->lines('net/'.$name), $protocol);
            $tables[$name] = ['count'=>$table['count'],'states'=>$table['states']];
            $inodes += $table['inodes'];
            foreach ($table['states'] as $state => $count) $states[$state] = ($states[$state] ?? 0) + $count;
        }
        ksort($states);
        $unix = Parsers::unix($reader->lines('net/unix'));
        $inodes += $unix['inodes'];
        unset($unix['inodes']);
        $summary['unix'] = $unix;
        $summary['netlink'] = ['count'=>Parsers::count($reader->lines('net/netlink'))];
        $summary['packet'] = ['count'=>Parsers::count($reader->lines('net/packet'))];
        $summary['tcp_states'] = $states;
        $summary['table_counts'] = array_map(static fn($t) => $t['count'], $tables);
        $counters = array_replace_recursive(
            Parsers::mib($reader->lines('net/snmp')),
            Parsers::mib($reader->lines('net/netstat'))
        );
        if ($this->options->extended) {
            $six = Parsers::snmp6($reader->lines('net/snmp6'));
            if ($six) $counters['IPv6'] = $six;
        }
        $data = [
            'metadata'=>['version'=>VERSION,'hostname'=>gethostname() ?: 'unknown','timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'proc_root'=>$this->options->root,'pid'=>getmypid()],
            'summary'=>$summary,'tables'=>$tables,'counters'=>$counters,'warnings'=>$reader->warnings,
        ];
        if ($this->options->processes) $data['ownership'] = $this->owners($inodes);
        $this->thresholds($data);
        if ($this->options->performance) $data['performance'] = [
            'collection_seconds'=>round((hrtime(true) - $start) / 1e9, 6),
            'peak_memory_mb'=>round(memory_get_peak_usage(true) / 1048576, 2),
            'php_version'=>PHP_VERSION,
        ];
        return $data;
    }
    private function owners(array $inodes): array {
        $counts = [];
        $names = [];
        $matched = [];
        $scanned = 0;
        $inaccessible = 0;
        $dirs = @scandir($this->options->root);
        if ($dirs === false) throw new RuntimeException('Cannot enumerate processes');
        foreach ($dirs as $pid) {
            if (!ctype_digit($pid)) continue;
            $path = $this->options->root.'/'.$pid.'/fd';
            $fds = @scandir($path);
            if ($fds === false) {
                $inaccessible++;
                continue;
            }
            $scanned++;
            $seen = [];
            foreach ($fds as $fd) {
                if ($fd === '.' || $fd === '..') continue;
                $link = @readlink($path.'/'.$fd);
                if ($link === false || !preg_match('/^socket:\[(\d+)\]$/D', $link, $m)) continue;
                $inode = (int)$m[1];
                if (!isset($inodes[$inode]) || isset($seen[$inode])) continue;
                $seen[$inode] = true;
                $matched[$inode] = true;
                $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            }
            if (isset($counts[$pid])) {
                $name = @file_get_contents($this->options->root.'/'.$pid.'/comm');
                $names[$pid] = $name === false ? 'unknown' : trim($name);
            }
        }
        arsort($counts, SORT_NUMERIC);
        $processes = [];
        foreach (array_slice($counts, 0, $this->options->processLimit, true) as $pid => $count) {
            $processes[] = ['pid'=>(int)$pid,'name'=>$names[$pid],'sockets'=>$count];
        }
        return ['scanned_processes'=>$scanned,'inaccessible_processes'=>$inaccessible,'matched_inodes'=>count($matched),'processes'=>$processes,'note'=>'Ownership is limited by permissions and visible namespaces.'];
    }
    private function thresholds(array &$data): void {
        $s = $data['summary'];
        $checks = [
            'tcp'=>['TCP allocated',$s['tcp']['alloc'] ?? 0],
            'established'=>['TCP established',$s['tcp_states']['ESTABLISHED'] ?? 0],
            'timewait'=>['TCP time-wait',$s['tcp']['tw'] ?? 0],
            'orphan'=>['TCP orphan',$s['tcp']['orphan'] ?? 0],
        ];
        foreach ($checks as $key => [$label,$value]) {
            if (isset($this->options->thresholds[$key]) && $value >= $this->options->thresholds[$key]) {
                $data['warnings'][] = "$label threshold reached: $value >= ".$this->options->thresholds[$key];
            }
        }
        if (isset($this->options->thresholds['retrans'])) {
            $tcp = $data['counters']['Tcp'] ?? [];
            $out = $tcp['OutSegs'] ?? 0;
            if ($out > 0) {
                $ratio = 100 * ($tcp['RetransSegs'] ?? 0) / $out;
                if ($ratio >= $this->options->thresholds['retrans']) $data['warnings'][] = sprintf('Cumulative TCP retransmission ratio %.2f%% >= %.2f%%', $ratio, $this->options->thresholds['retrans']);
            }
        }
    }
}

final class Formatter {
    public static function numeric(array $data, string $prefix = ''): array {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix.'.'.$key;
            if (is_array($value)) $out += self::numeric($value, $name);
            elseif (is_int($value) || is_float($value)) $out[$name] = $value;
        }
        return $out;
    }
    public static function sampling(array $now, array $previous, float $elapsed): array {
        $changes = [];
        $rates = [];
        foreach (['summary','counters'] as $section) {
            $current = self::numeric($now[$section] ?? [], $section);
            $old = self::numeric($previous[$section] ?? [], $section);
            foreach ($current as $key => $value) {
                if (!isset($old[$key])) continue;
                $delta = $value - $old[$key];
                if ($section === 'counters' && $delta < 0) continue;
                if ($delta != 0) $changes[$key] = $delta;
                if ($section === 'counters' && $elapsed > 0) $rates[$key] = round($delta / $elapsed, 3);
            }
        }
        return ['elapsed_seconds'=>round($elapsed, 3),'changes'=>$changes,'counter_rates_per_second'=>$rates];
    }
    private static function rows(array $rows): string {
        $out = '';
        foreach ($rows as $key => $value) $out .= sprintf("  %-32s %s\n", (string)$key, is_float($value) ? number_format($value, 3) : (string)$value);
        return $out;
    }
    public static function human(array $data, Options $options): string {
        $s = $data['summary'];
        $m = $data['metadata'];
        $out = "SOCKET STATISTICS\n".str_repeat('=', 72)."\n";
        $out .= "Host:       {$m['hostname']}\nTimestamp:  {$m['timestamp']}\nSource:     {$m['proc_root']}\nVersion:    {$m['version']}\n";
        $out .= "\nSOCKET SUMMARY\n".str_repeat('-', 72)."\n";
        $out .= self::rows([
            'Sockets used'=>$s['sockets_used'],'TCP allocated'=>$s['tcp']['alloc'] ?? 0,
            'TCP in use'=>$s['tcp']['inuse'] ?? 0,'TCP orphan'=>$s['tcp']['orphan'] ?? 0,
            'TCP time-wait'=>$s['tcp']['tw'] ?? 0,'UDP in use'=>$s['udp']['inuse'] ?? 0,
            'RAW in use'=>$s['raw']['inuse'] ?? 0,'UNIX sockets'=>$s['unix']['count'],
            'Netlink sockets'=>$s['netlink']['count'],'Packet sockets'=>$s['packet']['count'],
        ]);
        $out .= "\nSOCKET MEMORY\n".str_repeat('-', 72)."\n";
        $memory = [];
        foreach (['tcp','udp','frag'] as $protocol) {
            foreach (['mem','memory'] as $field) {
                if (isset($s[$protocol][$field])) $memory[strtoupper($protocol)." $field (pages)"] = $s[$protocol][$field];
            }
        }
        $out .= $memory ? self::rows($memory) : "  No memory counters available\n";
        $out .= "\nTCP CONNECTION STATES\n".str_repeat('-', 72)."\n";
        arsort($s['tcp_states'], SORT_NUMERIC);
        $out .= $s['tcp_states'] ? self::rows($s['tcp_states']) : "  No TCP entries found\n";
        $out .= "\nPROTOCOL TABLES\n".str_repeat('-', 72)."\n".self::rows($s['table_counts']);
        if ($options->extended) {
            $out .= "\nIPV6 SOCKET STATISTICS\n".str_repeat('-', 72)."\n";
            $out .= self::rows(self::numeric($s['ipv6'] ?? []));
            $out .= "\nUNIX SOCKET TYPES\n".str_repeat('-', 72)."\n".self::rows($s['unix']['types']);
            $out .= "\nKERNEL NETWORK COUNTERS\n".str_repeat('-', 72)."\n";
            $selected = [];
            foreach (IMPORTANT as $section => $fields) {
                foreach ($fields as $field) {
                    if (isset($data['counters'][$section][$field])) $selected[$section.'.'.$field] = $data['counters'][$section][$field];
                }
            }
            $out .= self::rows($selected);
        }
        if (isset($data['ownership'])) {
            $p = $data['ownership'];
            $out .= "\nSOCKET OWNERSHIP\n".str_repeat('-', 72)."\n";
            $out .= self::rows(['Processes scanned'=>$p['scanned_processes'],'Processes inaccessible'=>$p['inaccessible_processes'],'Matched socket inodes'=>$p['matched_inodes']]);
            $out .= sprintf("\n  %8s  %10s  %s\n", 'PID','SOCKETS','PROCESS');
            foreach ($p['processes'] as $process) $out .= sprintf("  %8d  %10d  %s\n", $process['pid'],$process['sockets'],$process['name']);
        }
        if (isset($data['sampling'])) {
            $p = $data['sampling'];
            $out .= "\nCHANGES SINCE PREVIOUS SAMPLE\n".str_repeat('-', 72)."\n";
            $out .= '  Interval: '.$p['elapsed_seconds']." seconds\n";
            $out .= self::rows($p['changes']);
            $out .= "\nCOUNTER RATES PER SECOND\n".str_repeat('-', 72)."\n";
            $out .= self::rows(array_filter($p['counter_rates_per_second'], static fn($n) => $n != 0));
        }
        if (isset($data['performance'])) $out .= "\nPERFORMANCE\n".str_repeat('-', 72)."\n".self::rows($data['performance']);
        if ($data['warnings']) $out .= "\nWARNINGS\n".str_repeat('-', 72)."\n  ".implode("\n  ", $data['warnings'])."\n";
        return rtrim($out)."\n";
    }
    public static function csvRow(array $data): array {
        $row = ['timestamp'=>$data['metadata']['timestamp'],'hostname'=>$data['metadata']['hostname']];
        foreach (['summary','counters','performance','sampling'] as $section) $row += self::numeric($data[$section] ?? [], $section);
        return $row;
    }
}

final class Output {
    private $stream;
    private bool $owned;
    private bool $first = true;
    private array $csvColumns = [];
    public function __construct(private Options $options) {
        $this->owned = $options->output !== null;
        $this->stream = $this->owned ? @fopen($options->output, 'wb') : STDOUT;
        if ($this->stream === false) throw new RuntimeException('Cannot open output file');
    }
    public function emit(array $data): void {
        if ($this->options->quiet) return;
        if ($this->options->format === 'csv') {
            $row = Formatter::csvRow($data);
            if ($this->first) {
                $this->csvColumns = array_keys($row);
                if ($this->options->watch) {
                    foreach (array_keys(Formatter::numeric($data['counters'] ?? [], 'counters')) as $key) $this->csvColumns[] = 'sampling.counter_rates_per_second.'.$key;
                    $this->csvColumns[] = 'sampling.elapsed_seconds';
                }
                fputcsv($this->stream, $this->csvColumns, ',', '"', '');
            }
            $values = [];
            foreach ($this->csvColumns as $key) $values[] = $row[$key] ?? '';
            fputcsv($this->stream, $values, ',', '"', '');
        } elseif ($this->options->format === 'json') {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (!$this->options->watch) $flags |= JSON_PRETTY_PRINT;
            fwrite($this->stream, json_encode($data, $flags)."\n");
        } else {
            if (!$this->first) fwrite($this->stream, "\n");
            fwrite($this->stream, Formatter::human($data, $this->options));
        }
        fflush($this->stream);
        $this->first = false;
    }
    public function close(): void {
        if ($this->owned && is_resource($this->stream)) fclose($this->stream);
    }
}

function main(array $argv): int {
    $options = Options::parse($argv);
    $collector = new Collector($options);
    $output = new Output($options);
    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $sig) pcntl_signal($sig, static function () use (&$running): void { $running = false; });
    }
    $previous = null;
    $previousTime = null;
    $completed = 0;
    try {
        while ($running) {
            $started = hrtime(true) / 1e9;
            $data = $collector->collect();
            if ($previous !== null && $previousTime !== null) $data['sampling'] = Formatter::sampling($data, $previous, $started - $previousTime);
            $output->emit($data);
            $previous = $data;
            $previousTime = $started;
            $completed++;
            if (!$options->watch || ($options->count > 0 && $completed >= $options->count)) break;
            $deadline = $started + $options->interval;
            while ($running && ($remaining = $deadline - hrtime(true) / 1e9) > 0) usleep((int)(min($remaining, 0.2) * 1e6));
        }
    } finally {
        $output->close();
    }
    return 0;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage().PHP_EOL);
    exit(1);
}
