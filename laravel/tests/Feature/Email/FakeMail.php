<?php

namespace Tests\Feature\Email;

/* Fake mail settings and a pretend mail server (fake-smtp.php), so a test can see what
   "Gmail" did with an email without one leaving this machine. */
trait FakeMail
{
    private ?string $fakeSettings = null;
    private ?string $fakeCapture = null;
    private $fakeServer = null;

    /* Settings like a laptop's mail-config.php, with no server behind them yet. */
    private function fakeMailSettings(array $settings): void
    {
        $this->fakeSettings ??= tempnam(sys_get_temp_dir(), 'vmail');
        file_put_contents($this->fakeSettings, '<?php return ' . var_export($settings + [
            'transport' => 'smtp', 'host' => '127.0.0.1', 'encryption' => '',
            'username' => 'venusep.test@example.test', 'password' => 'fake-app-password-123',
            'from_email' => 'venusep.test@example.test', 'base_url' => 'https://mail-test.example.test',
        ], true) . ';');
        config(['venusep.mail_config' => $this->fakeSettings]);
    }

    /* Starts the pretend server: 'accept' takes every email, 'reject' refuses the sign-in. */
    private function fakeGmail(string $mode = 'accept', array $settings = []): void
    {
        $this->fakeCapture = tempnam(sys_get_temp_dir(), 'vsmtp');
        $this->fakeServer = proc_open([PHP_BINARY, __DIR__ . '/fake-smtp.php', $mode, $this->fakeCapture],
            [1 => ['pipe', 'w']], $pipes);
        $this->fakeMailSettings($settings + ['port' => (int) fgets($pipes[1])]);
    }

    /* Settings pointing at a port where nothing listens: what no network looks like. */
    private function noNetwork(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->fakeMailSettings(['port' => $port]);
    }

    /* Everything the pretend server took so far, quoted-printable undone. */
    private function fakeInbox(): string
    {
        return quoted_printable_decode((string) file_get_contents($this->fakeCapture));
    }

    private function stopFakeMail(): void
    {
        if ($this->fakeServer) {
            proc_terminate($this->fakeServer);
        }
        foreach ([$this->fakeSettings, $this->fakeCapture] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }
    }
}
