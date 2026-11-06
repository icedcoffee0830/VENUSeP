<?php
/* A pretend mail server for the tests, on 127.0.0.1 only: nothing it takes goes anywhere.
   php fake-smtp.php accept|reject|leak <capture file>. Prints its port, then answers until
   it is stopped. Reject acts like Gmail refusing the sign-in. Leak signs in, then refuses
   the sender and repeats the password, so a test can prove it never gets past the mailer. */
[, $mode, $capture] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0');
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";

while ($client = stream_socket_accept($server, 120)) {
    fwrite($client, "220 fake ESMTP\r\n");
    $state = '';
    $message = '';
    while (($line = fgets($client)) !== false) {
        $bare = rtrim($line, "\r\n");
        if ($state === 'data') {
            if ($bare !== '.') {
                $message .= $line;
                continue;
            }
            file_put_contents($capture, $message, FILE_APPEND);
            $message = '';
            $state = '';
            fwrite($client, "250 taken\r\n");
        } elseif ($state === 'auth') {
            $state = '';
            $password = substr(strrchr(base64_decode($bare), "\0"), 1);
            fwrite($client, $mode === 'reject' ? "535 5.7.8 Username and Password not accepted\r\n" : "235 ok\r\n");
        } elseif ($mode === 'leak' && stripos($bare, 'MAIL') === 0) {
            fwrite($client, "550 5.7.1 Refused for {$password}\r\n");
        } elseif (stripos($bare, 'EHLO') === 0) {
            fwrite($client, "250-fake\r\n250 AUTH PLAIN\r\n");
        } elseif (stripos($bare, 'AUTH') === 0) {
            $state = 'auth';
            fwrite($client, "334 \r\n");
        } elseif (stripos($bare, 'DATA') === 0) {
            $state = 'data';
            fwrite($client, "354 go on\r\n");
        } elseif (stripos($bare, 'QUIT') === 0) {
            fwrite($client, "221 bye\r\n");
            break;
        } else {
            if (stripos($bare, 'RCPT') === 0) {
                file_put_contents($capture, $bare . "\n", FILE_APPEND);
            }
            fwrite($client, "250 ok\r\n");
        }
    }
    fclose($client);
}
