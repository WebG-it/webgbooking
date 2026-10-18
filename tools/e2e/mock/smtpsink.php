<?php
// Minimal SMTP sink: accepts any mail on 127.0.0.1:2525 and stores it in /tmp/mail/N.eml
@mkdir('/tmp/mail', 0777, true);
$srv = stream_socket_server('tcp://0.0.0.0:2525', $errno, $errstr);
if (!$srv) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
$n = count(glob('/tmp/mail/*.eml'));
while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if (!$c) { continue; }
    fwrite($c, "220 sink ESMTP\r\n");
    $data = false; $buf = ''; $rcpt = []; $from = '';
    while (($line = fgets($c)) !== false) {
        if ($data) {
            if (rtrim($line, "\r\n") === '.') {
                $n++;
                file_put_contents("/tmp/mail/$n.eml", "X-Rcpt: " . implode(',', $rcpt) . "\r\nX-From: $from\r\n" . $buf);
                fwrite($c, "250 OK queued\r\n");
                $data = false; $buf = ''; $rcpt = [];
                continue;
            }
            $buf .= (isset($line[0]) && $line[0] === '.' && substr($line, 0, 2) === '..') ? substr($line, 1) : $line;
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') { fwrite($c, "250-sink\r\n250 8BITMIME\r\n"); }
        elseif ($cmd === 'MAIL') { $from = trim(substr($line, 10)); fwrite($c, "250 OK\r\n"); }
        elseif ($cmd === 'RCPT') {
            if (stripos($line, 'reject') !== false) { fwrite($c, "550 mailbox refused\r\n"); }   // test hook: any address containing "reject"
            else { $rcpt[] = trim(substr($line, 8)); fwrite($c, "250 OK\r\n"); }
        }
        elseif ($cmd === 'DATA') { $data = true; fwrite($c, "354 go\r\n"); }
        elseif ($cmd === 'RSET') { $rcpt = []; fwrite($c, "250 OK\r\n"); }
        elseif ($cmd === 'NOOP') { fwrite($c, "250 OK\r\n"); }
        elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        else { fwrite($c, "250 OK\r\n"); }
    }
    fclose($c);
}
