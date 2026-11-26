# VENUSeP server transfer: set up one laptop (SETUP.md, "Two or more laptops").
#   setup-laptop-A.bat  on the main laptop (the database everyone uses)
#   setup-laptop-B.bat  on each copy laptop (B, C, D)
#   undo.bat            puts a laptop back the way it was
# Every step can be run again safely. Old files are kept as *.before-failover.
# Plain ASCII on purpose: Windows PowerShell 5.1 reads scripts as ANSI.
param([ValidateSet('A', 'B', 'Undo', 'Start', 'Tunnel')][string]$Role)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Laravel = Join-Path $Root 'laravel'
$EnvFile = Join-Path $Laravel '.env'
$Begin = '# VENUSeP failover BEGIN (added by failover-setup; undo.bat removes it)'
$End = '# VENUSeP failover END'
$TaskName = 'VENUSeP failover tick'
$WebPort = 8100

function Say([string]$t) { Write-Host ''; Write-Host $t -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "  OK  $t" -ForegroundColor Green }
function Warn([string]$t) { Write-Host "  !   $t" -ForegroundColor Yellow }
function Fail([string]$t) { Write-Host ''; Write-Host "STOPPED: $t" -ForegroundColor Red; Read-Host 'Press Enter to close'; exit 1 }
function Pause-For([string]$t) { Write-Host ''; Read-Host "$t  Then press Enter" | Out-Null }

# ---------- files: .env and my.ini (pure text edits, tested on copies) ----------

function Get-EnvValue([string]$file, [string]$key) {
    foreach ($l in [IO.File]::ReadAllLines($file)) {
        if ($l -match "^\s*$key=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return ''
}

function Set-EnvValues([string]$file, [System.Collections.Specialized.OrderedDictionary]$values) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.AddRange([string[]][IO.File]::ReadAllLines($file))
    foreach ($k in $values.Keys) {
        $at = -1
        for ($n = 0; $n -lt $lines.Count; $n++) { if ($lines[$n] -match "^\s*$k=") { $at = $n; break } }
        if ($at -ge 0) { $lines[$at] = "$k=$($values[$k])" } else { $lines.Add("$k=$($values[$k])") }
    }
    [IO.File]::WriteAllLines($file, $lines.ToArray(), (New-Object Text.UTF8Encoding $false))
}

function Remove-FailoverBlock([string]$text) {
    return [regex]::Replace($text, '(?s)' + [regex]::Escape($Begin) + '.*?' + [regex]::Escape($End) + '\r?\n', '')
}

# The lines go at the END of [mysqld]: XAMPP's my.ini already says server-id=1 further up, and in an
# option file the later line wins. No read-only=ON: a server STARTED read-only skips Aria's start-up
# recovery, so after XAMPP's Stop button (not always a clean shutdown) MySQL's own account tables stayed
# damaged ("wrong checksum", "table is full" on two laptops). init-file locks it instead, a moment later,
# after that recovery and before anyone can save.
function Add-FailoverBlock([string]$text, [int]$serverId, [string]$db, [string]$lockFile) {
    $text = Remove-FailoverBlock $text
    $head = [regex]::Match($text, '(?m)^\[mysqld\][^\n]*\n')
    if (-not $head.Success) { throw 'my.ini has no [mysqld] section.' }
    $next = [regex]::Match($text.Substring($head.Index + $head.Length), '(?m)^\[')
    $at = if ($next.Success) { $head.Index + $head.Length + $next.Index } else { $text.Length }
    $block = @($Begin,
        "server-id=$serverId", 'bind-address=0.0.0.0', 'skip-name-resolve', 'log-bin=binlog', 'relay-log=relay-bin',
        'binlog-format=ROW', "binlog-do-db=$db", 'log-slave-updates=ON', 'gtid-strict-mode=ON', 'expire_logs_days=7',
        'log_bin_trust_function_creators=1', ('init-file=' + ($lockFile -replace '\\', '/')), 'rpl_semi_sync_master_enabled=ON', 'rpl_semi_sync_slave_enabled=ON',
        'rpl_semi_sync_master_wait_point=AFTER_SYNC', 'rpl_semi_sync_master_timeout=10000', 'slave_net_timeout=10',
        $End) -join "`r`n"
    $before = $text.Substring(0, $at)
    if (-not $before.EndsWith("`n")) { $before += "`r`n" }
    return $before + $block + "`r`n" + $text.Substring($at)
}

function Backup-Once([string]$file) {
    $copy = "$file.before-failover"
    if ((Test-Path $file) -and -not (Test-Path $copy)) { Copy-Item $file $copy; Ok "Kept the old file as $copy" }
}

# ---------- this machine ----------

function Find-Xampp {
    $guess = Split-Path -Parent (Split-Path -Parent $Root)   # <xampp>\htdocs\<project> -> <xampp>
    foreach ($d in @($guess, 'C:\xampp', 'C:\xamp5')) {
        if ($d -and (Test-Path (Join-Path $d 'mysql\bin\mysql.exe'))) { return $d }
    }
    $d = Read-Host 'Where is XAMPP installed? (for example C:\xampp)'
    if (-not (Test-Path (Join-Path $d 'mysql\bin\mysql.exe'))) { Fail "No mysql.exe under $d\mysql\bin." }
    return $d
}

# The address this laptop's traffic leaves by (the hotspot), not a VirtualBox or VPN adapter.
function Find-MyAddress {
    try {
        $ip = (Find-NetRoute -RemoteIPAddress 8.8.8.8 -ErrorAction Stop | Where-Object { $_.IPAddress } | Select-Object -First 1).IPAddress
    } catch { $ip = $null }
    $answer = Read-Host "This laptop's address on the hotspot [$ip] (press Enter if that is right)"
    if ($answer) { $ip = $answer.Trim() }
    if ($ip -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { Fail "'$ip' is not an IPv4 address. Run ipconfig and look at the Wi-Fi adapter." }
    return $ip
}

function Test-Admin {
    $me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# ---------- MySQL (run by you, as root, on this laptop) ----------

# Native programs: PowerShell 5.1 turns anything they print on stderr into an error, so run them with
# $ErrorActionPreference Continue and judge by the exit code alone.
function Invoke-Native([string]$exe, [string[]]$argv, [string]$stdin = $null) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $out = if ($stdin) { $stdin | & $exe @argv 2>&1 } else { & $exe @argv 2>&1 }
        $script:NativeOut = ($out | Out-String).Trim()
        return $LASTEXITCODE
    } finally { $ErrorActionPreference = $old }
}

function Invoke-Sql([string]$sql) {
    if ((Invoke-Native $script:MySql @('-u', 'root', '-N') $sql) -ne 0) { throw ('MySQL said: ' + $script:NativeOut) }
    return $script:NativeOut
}

function Test-MySqlUp {
    try { Invoke-Sql 'SELECT 1' | Out-Null; return $true } catch { return $false }
}

# MySQL is shut down cleanly here (mysqladmin), not with XAMPP's Stop button, which can leave the Aria
# system tables half-written. Then you press Start.
function Restart-MySql {
    if (Test-MySqlUp) {
        Invoke-Native $script:MySqlAdmin @('-u', 'root', 'shutdown') | Out-Null
        for ($i = 0; $i -lt 20 -and (Test-MySqlUp); $i++) { Start-Sleep -Seconds 1 }
        if (Test-MySqlUp) { Fail ('MySQL did not shut down: ' + $script:NativeOut) }
        Ok 'MySQL shut down cleanly'
    }
    Pause-For 'Now press START on MySQL in the XAMPP Control Panel (only Start; it is already stopped).'
    for ($i = 0; $i -lt 15 -and -not (Test-MySqlUp); $i++) { Start-Sleep -Seconds 2 }
    if (-not (Test-MySqlUp)) { Fail 'MySQL is not answering. Start it in the XAMPP panel; if it will not start, run undo.bat.' }
}

function Wait-MySqlRestart {
    [IO.File]::WriteAllText($script:LockFile, "SET GLOBAL read_only = 1;`r`n")
    Restart-MySql
    if ((Invoke-Sql 'SELECT @@log_bin') -ne '1') { Fail 'MySQL started without the new settings. Run this again; if it repeats, send a screenshot.' }
    if ((Invoke-Sql 'SELECT @@read_only') -ne '1') { Fail "MySQL started but did not lock itself (init-file $script:LockFile)." }
    Ok 'MySQL restarted with the copy settings, and locked itself (read-only)'
    # every Aria table of MySQL's own (the accounts, roles, ...): laptop A had more than one damaged
    $aria = (Invoke-Sql "SELECT GROUP_CONCAT(CONCAT('mysql.', TABLE_NAME)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'mysql' AND ENGINE = 'Aria' AND TABLE_TYPE = 'BASE TABLE'").Trim()
    $check = if ($aria) { Invoke-Sql "CHECK TABLE $aria;" } else { '' }
    if ($check -match 'Corrupt|\terror\t') {
        Invoke-Sql "REPAIR TABLE $aria;" | Out-Null
        Warn 'MySQL''s own account tables were damaged (an earlier stop that was not clean) and are repaired.'
    }
}

# The accounts. MySQL is unlocked for this step only (a locked XAMPP MariaDB refused the per-database
# rights on laptop A: "ERROR 1114 The table 'db' is full") and locked again whatever happens. If the
# per-database rights still fail, the same rights go server-wide: they are kept in the other system
# table, which works, and the lock still stops the website account from writing on a copy.
function Set-Accounts([string]$db, [string]$app, [string]$admin, [string]$repl) {
    Invoke-Sql 'SET GLOBAL read_only = 0;' | Out-Null
    try {
        Invoke-Sql @"
CREATE USER IF NOT EXISTS 'venusep_app'@'%' IDENTIFIED BY '$app';
ALTER USER 'venusep_app'@'%' IDENTIFIED BY '$app';
CREATE USER IF NOT EXISTS 'venusep_admin'@'%' IDENTIFIED BY '$admin';
ALTER USER 'venusep_admin'@'%' IDENTIFIED BY '$admin';
CREATE USER IF NOT EXISTS 'venusep_repl'@'%' IDENTIFIED BY '$repl';
ALTER USER 'venusep_repl'@'%' IDENTIFIED BY '$repl';
GRANT SUPER, REPLICATION CLIENT, RELOAD ON *.* TO 'venusep_admin'@'%';
GRANT REPLICATION SLAVE ON *.* TO 'venusep_repl'@'%';
"@ | Out-Null
        try {
            Invoke-Sql @"
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES ON ``$db``.* TO 'venusep_app'@'%';
GRANT ALL PRIVILEGES ON ``$db``.* TO 'venusep_admin'@'%';
GRANT SELECT ON mysql.proc TO 'venusep_admin'@'%';
"@ | Out-Null
            Ok "MySQL accounts venusep_app, venusep_admin and venusep_repl (rights on $db)"
        } catch {
            if ($_.Exception.Message -notmatch '1114') { throw }
            Invoke-Sql @"
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES ON *.* TO 'venusep_app'@'%';
GRANT ALL PRIVILEGES ON *.* TO 'venusep_admin'@'%';
"@ | Out-Null
            Ok 'MySQL accounts venusep_app, venusep_admin and venusep_repl'
            Warn "This MySQL refused rights on $db alone (error 1114), so the rights are server-wide. Fine for testing."
        }
        # XAMPP's anonymous login: removed when possible. Not needed: the website connects by network
        # address, which that login (localhost only) never matches.
        try { Invoke-Sql "DROP USER IF EXISTS ''@'localhost';" | Out-Null } catch { Warn ('Left XAMPP''s anonymous login in place: ' + $_.Exception.Message) }
        Invoke-Sql 'FLUSH PRIVILEGES;' | Out-Null
    } finally {
        Invoke-Sql 'SET GLOBAL read_only = 1;' | Out-Null
    }
}

# ---------- Windows: firewall, network, the 1-minute task, the website ----------

function Open-Firewall([string]$myIp) {
    foreach ($r in @(@('VENUSeP MySQL', 3306), @('VENUSeP web', $WebPort))) {
        netsh advfirewall firewall delete rule name="$($r[0])" | Out-Null
        netsh advfirewall firewall add rule name="$($r[0])" dir=in action=allow protocol=TCP localport=$($r[1]) profile=private | Out-Null
    }
    Ok "Firewall: ports 3306 and $WebPort open on private networks"
    $if = (Get-NetIPAddress -IPAddress $myIp -ErrorAction SilentlyContinue).InterfaceIndex
    if ($if) {
        Get-NetConnectionProfile -InterfaceIndex $if | Set-NetConnectionProfile -NetworkCategory Private
        Ok 'The hotspot network is set to Private'
    } else { Warn 'Set the hotspot network to Private yourself: Settings > Network > the hotspot > Private' }
}

function Add-Task {
    # php-win.exe: the same PHP without a console window (php.exe popped one up every minute)
    $quiet = Join-Path (Split-Path -Parent $script:Php) 'php-win.exe'
    $exe = if (Test-Path $quiet) { $quiet } else { $script:Php }
    $a = New-ScheduledTaskAction -Execute $exe -Argument 'artisan venusep:failover-tick' -WorkingDirectory $Laravel
    $t = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $s = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
    Register-ScheduledTask -TaskName $TaskName -Action $a -Trigger $t -Settings $s -Force | Out-Null
    Ok "The 1-minute task '$TaskName'"
}

function Start-Site {
    Start-Process cmd -ArgumentList '/k', "title VENUSeP website (keep open) && cd /d `"$Laravel`" && `"$script:Php`" artisan serve --host=0.0.0.0 --port=$WebPort"
    Ok "The website started in its own window on port $WebPort. Keep that window open."
}

function Get-Passwords {
    Say 'Pick three passwords for the MySQL accounts (letters, numbers, - and _ only). Use the same three on every laptop.'
    $p = [ordered]@{}
    foreach ($k in @('app', 'admin', 'repl')) {
        $v = Read-Host "  Password for venusep_$k"
        if ($v -notmatch '^[A-Za-z0-9_-]{8,}$') { Fail 'Use at least 8 letters, numbers, - or _.' }
        $p[$k] = $v
    }
    return $p
}

# ---------- the three roles ----------

function Setup-A {
    $db = Get-EnvValue $EnvFile 'DB_DATABASE'
    if (-not $db) { Fail "No DB_DATABASE in $EnvFile." }
    if (-not (Test-MySqlUp)) { Fail 'Start MySQL in the XAMPP Control Panel first.' }
    $level = Invoke-Sql "SELECT setting_value FROM ``$db``.system_settings WHERE setting_key = 'schema_version'"
    if ($level -ne '22') { Fail "The database $db is not at migration 22 (it says '$level'). Run migrations 21 and 22 first." }
    $ip = Find-MyAddress
    $pw = Get-Passwords

    Say '1/6  MySQL settings (my.ini)'
    Backup-Once $script:MyIni
    [IO.File]::WriteAllText($script:MyIni, (Add-FailoverBlock ([IO.File]::ReadAllText($script:MyIni)) 1 $db $script:LockFile))
    Ok 'Added the copy settings, server-id=1'
    Wait-MySqlRestart

    Say '2/6  Firewall';             Open-Firewall $ip
    Say '3/6  MySQL accounts';        Set-Accounts $db $pw.app $pw.admin $pw.repl
    Say '4/6  The website settings (.env)'
    Backup-Once $EnvFile
    Set-EnvValues $EnvFile ([ordered]@{ DB_HOST = $ip; DB_USERNAME = 'venusep_app'; DB_PASSWORD = $pw.app;
        DB_ADMIN_USERNAME = 'venusep_admin'; DB_ADMIN_PASSWORD = $pw.admin; DB_REPL_USERNAME = 'venusep_repl';
        DB_REPL_PASSWORD = $pw.repl; FAILOVER_WEB_PORT = $WebPort })
    Ok ".env now points at this laptop ($ip)"
    Say '5/6  The 1-minute task';     Add-Task
    Invoke-Native $script:Php @((Join-Path $Laravel 'artisan'), 'venusep:failover-tick') | Out-Null   # the emergency sign-in, now
    Say '6/6  The website';           Start-Site

    Say 'DONE on laptop A. What you do now:'
    Write-Host "  1. Copy this file to a USB stick for laptop B:  $EnvFile"
    Write-Host "  2. Open http://$($ip):$WebPort/server-emergency , sign in as the super admin with your"
    Write-Host '     authenticator code, and press "Unlock saving" on this laptop''s row.'
    Write-Host "  3. On laptop B, run setup-laptop-B.bat. It asks for this address: $ip"
    Start-Process "http://$($ip):$WebPort/server-emergency"
}

function Setup-B {
    $fromA = (Read-Host "Where is laptop A's .env? (the file you were sent, for example C:\Users\you\Downloads\.env)").Trim('"', ' ')
    if (-not (Test-Path $fromA)) { Fail "No file at $fromA." }
    if (-not (Get-EnvValue $fromA 'DB_REPL_PASSWORD')) { Fail "$fromA is not the .env from laptop A's setup (no DB_REPL_PASSWORD)." }
    Say 'Looking for the main laptop on this hotspot (a few seconds)...'
    $found = Find-MainOnHotspot $fromA
    $main = $null
    if ($found) {
        $answer = (Read-Host "The main is $($found[1]) at $($found[0]). Press Enter to use it, or type another address").Trim()
        $main = if ($answer) { $answer } else { $found[0] }
    } else {
        $main = (Read-Host "No main found by itself. Type laptop A's address (its Server page shows it under This laptop)").Trim()
    }
    if ($main -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { Fail "'$main' is not an IPv4 address." }
    $n = Read-Host 'Which copy is this? 2 = laptop B, 3 = C, 4 = D [2]'
    $id = if ($n) { [int]$n } else { 2 }
    if ($id -lt 2 -or $id -gt 9) { Fail 'Type 2, 3 or 4.' }
    if (-not (Test-MySqlUp)) { Fail 'Start MySQL in the XAMPP Control Panel first.' }
    $ip = Find-MyAddress
    if (-not (Test-NetConnection $main -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue)) {
        Fail "Laptop A ($main) does not answer on port 3306. Is it on the same hotspot, with MySQL running and its setup done?"
    }
    Ok "Laptop A answers at $main"

    Say '1/7  The website settings (.env, the same file as laptop A)'
    Backup-Once $EnvFile
    Copy-Item $fromA $EnvFile -Force
    $db = Get-EnvValue $EnvFile 'DB_DATABASE'
    Ok "Copied A's .env (same APP_KEY, database $db)"

    Say '2/7  Your own database, kept before it is replaced'
    $keep = Join-Path $env:USERPROFILE ("$db-before-copy-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
    if ((Invoke-Native $script:MySqlDump @('-u', 'root', '--routines', '--triggers', '--events', '--single-transaction', "--result-file=$keep", '--databases', $db)) -eq 0) { Ok "Saved as $keep" } else { Remove-Item $keep -ErrorAction SilentlyContinue; Warn "No database $db here yet: nothing to keep" }

    Say '3/7  MySQL settings (my.ini)'
    Backup-Once $script:MyIni
    [IO.File]::WriteAllText($script:MyIni, (Add-FailoverBlock ([IO.File]::ReadAllText($script:MyIni)) $id $db $script:LockFile))
    Ok "Added the copy settings, server-id=$id"
    Wait-MySqlRestart

    Say '4/7  Firewall';         Open-Firewall $ip
    Say '5/7  MySQL accounts';    Set-Accounts $db (Get-EnvValue $EnvFile 'DB_PASSWORD') (Get-EnvValue $EnvFile 'DB_ADMIN_PASSWORD') (Get-EnvValue $EnvFile 'DB_REPL_PASSWORD')

    Say "6/7  Load laptop A's database and start copying (a minute or two)"
    $seed = "$env:SystemDrive/venusep-failover-seed.sql"   # no spaces: the mysql client's source command takes it bare
    $env:MYSQL_PWD = Get-EnvValue $EnvFile 'DB_ADMIN_PASSWORD'
    $dumped = Invoke-Native $script:MySqlDump @('-h', $main, '-u', 'venusep_admin', '--single-transaction', '--gtid', '--master-data=1', '--routines',
        '--triggers', '--add-drop-database', "--result-file=$seed", '--databases', $db)
    Remove-Item Env:\MYSQL_PWD
    if ($dumped -ne 0) { Fail "Could not read laptop A's database ($script:NativeOut). Check that A's setup finished and the passwords match." }
    Invoke-Sql 'STOP SLAVE; RESET SLAVE ALL;' | Out-Null
    if ((Invoke-Native $script:MySql @('-u', 'root', '--default-character-set=utf8mb4', '--init-command=SET sql_log_bin=0', '-e', "source $seed")) -ne 0) {
        Fail ('Loading the copy failed: ' + $script:NativeOut)
    }
    Remove-Item $seed
    $repl = Get-EnvValue $EnvFile 'DB_REPL_PASSWORD'
    Invoke-Sql "CHANGE MASTER TO MASTER_HOST='$main', MASTER_PORT=3306, MASTER_USER='venusep_repl', MASTER_PASSWORD='$repl', MASTER_USE_GTID=slave_pos; START SLAVE;" | Out-Null
    Start-Sleep -Seconds 5
    $status = Invoke-Sql 'SHOW SLAVE STATUS\G'
    if ($status -notmatch 'Slave_IO_Running: Yes' -or $status -notmatch 'Slave_SQL_Running: Yes') {
        Write-Host $status
        Fail 'Copying did not start (see Last_IO_Error / Last_SQL_Error above).'
    }
    Ok "Copying from laptop A ($main)"

    Say '7/7  The 1-minute task and the website'
    Add-Task
    Start-Site

    Say "DONE on this copy ($ip). What you do now:"
    Write-Host "  On laptop A's Server page: Find servers on this hotspot (or type $ip), Add to the list, then Test."
    Write-Host '  It must say "Up to date". Then start the drill (SETUP.md).'
}

function Undo-Laptop {
    Say 'Putting this laptop back'
    if (Test-Path "$script:MyIni.before-failover") {
        [IO.File]::WriteAllText($script:MyIni, (Remove-FailoverBlock ([IO.File]::ReadAllText($script:MyIni))))
        Ok 'Removed the copy settings from my.ini'
    }
    if (Test-Path $script:LockFile) { Remove-Item $script:LockFile; Ok 'Removed the start-up lock file' }
    if (Test-MySqlUp) {
        try { Invoke-Sql 'STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = 0;' | Out-Null; Ok 'This laptop stopped copying' } catch { Warn $_.Exception.Message }
    }
    if (Test-Path "$EnvFile.before-failover") { Move-Item "$EnvFile.before-failover" $EnvFile -Force; Ok 'Put the old .env back' }
    $sf = Join-Path $Laravel 'storage\app\failover\server.json'
    if (Test-Path $sf) { Remove-Item $sf; Ok 'Removed the server file' }
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false; Ok 'Removed the 1-minute task' }
    netsh advfirewall firewall delete rule name="VENUSeP MySQL" | Out-Null
    netsh advfirewall firewall delete rule name="VENUSeP web" | Out-Null
    Ok 'Removed the firewall rules'
    Restart-MySql
    Say 'DONE. The MySQL accounts are left in place (harmless). A copy laptop''s own database was saved in your user folder as <name>-before-copy-<date>.sql.'
}

# ---------- every session: start-venusep.bat (no administrator rights needed) ----------

function Test-Port([string]$ip, [int]$port, [int]$ms = 600) {
    $c = New-Object Net.Sockets.TcpClient
    try { return $c.ConnectAsync($ip, $port).Wait($ms) -and $c.Connected } catch { return $false } finally { $c.Close() }
}

function Get-MyIp {
    try { return (Find-NetRoute -RemoteIPAddress 8.8.8.8 -ErrorAction Stop | Where-Object { $_.IPAddress } | Select-Object -First 1).IPAddress } catch { return $null }
}

# The laptop on this hotspot that takes saves, found with the admin account from A's .env:
# MySQL answers, read_only = 0 and it copies from nobody. Null = none or more than one.
function Find-MainOnHotspot([string]$envFile) {
    $me = Get-MyIp
    if (-not $me) { return $null }
    $net = $me.Substring(0, $me.LastIndexOf('.') + 1)
    $open = @()
    $tasks = @{}
    foreach ($n in 1..254) {
        $c = New-Object Net.Sockets.TcpClient
        $tasks["$net$n"] = @($c, $c.ConnectAsync("$net$n", 3306))
    }
    Start-Sleep -Milliseconds 1500
    foreach ($ip in $tasks.Keys) {
        if ($tasks[$ip][1].IsCompleted -and $tasks[$ip][0].Connected -and $ip -ne $me) { $open += $ip }
        $tasks[$ip][0].Close()
    }
    $env:MYSQL_PWD = Get-EnvValue $envFile 'DB_ADMIN_PASSWORD'
    $mains = @()
    try {
        foreach ($ip in $open) {
            if ((Invoke-Native $script:MySql @('-h', $ip, '-u', 'venusep_admin', '-N', '-e', 'SELECT @@hostname, @@read_only; SHOW SLAVE STATUS;')) -ne 0) { continue }
            $lines = @($script:NativeOut -split "`r?`n" | Where-Object { $_ })
            $first = $lines[0] -split "`t"
            if ($first.Count -ge 2 -and $first[1] -eq '0' -and $lines.Count -eq 1) { $mains += , @($ip, $first[0]) }
        }
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
    if ($mains.Count -eq 1) { return $mains[0] }
    return $null
}

function Start-Session {
    Say '1/4  MySQL'
    if (Test-MySqlUp) { Ok 'already running' } else {
        Start-Process -FilePath (Join-Path (Split-Path -Parent $script:MySql) 'mysqld.exe') -ArgumentList "--defaults-file=`"$script:MyIni`"", '--standalone' -WindowStyle Hidden
        for ($i = 0; $i -lt 30 -and -not (Test-MySqlUp); $i++) { Start-Sleep -Seconds 1 }
        if (-not (Test-MySqlUp)) { Fail 'MySQL did not start. Start it in the XAMPP Control Panel, then double-click this again.' }
        Ok 'started'
    }

    Say '2/4  The website (port 8100 for the hotspot, localhost:8000 for this laptop)'
    if (Test-Port '127.0.0.1' $WebPort) { Ok 'already running' } else {
        Start-Process cmd.exe -ArgumentList '/c', "`"$PSScriptRoot\start-website.bat`"" -WindowStyle Minimized
        for ($i = 0; $i -lt 20 -and -not (Test-Port '127.0.0.1' $WebPort); $i++) { Start-Sleep -Seconds 1 }
        if (-not (Test-Port '127.0.0.1' $WebPort)) { Fail 'The website did not start. Double-click start-website.bat to see why.' }
        Ok 'started in its own window (minimized): keep it open'
    }
    # localhost:8000 = "Continue with Google" on this laptop (Google takes localhost, never a hotspot address)
    if (-not (Test-Port '127.0.0.1' 8000)) {
        Start-Process cmd.exe -ArgumentList '/c', 'start', '"VENUSeP website for this laptop (localhost:8000, keep open)"', '/min', "`"$script:Php`"", 'artisan', 'serve', '--port=8000' -WorkingDirectory $Laravel -WindowStyle Hidden
        for ($i = 0; $i -lt 15 -and -not (Test-Port '127.0.0.1' 8000); $i++) { Start-Sleep -Seconds 1 }
    }
    if (Test-Port '127.0.0.1' 8000) { Ok 'http://localhost:8000 runs too: use it on this laptop (Continue with Google works there)' }
    else { Warn 'localhost:8000 did not start (another program on port 8000?): Continue with Google will not work on this laptop' }

    Say '3/4  Find the other laptops on this hotspot (a few seconds)'
    Invoke-Native $script:Php @((Join-Path $Laravel 'artisan'), 'venusep:failover-start') | Out-Null
    Write-Host $script:NativeOut

    Say '4/4  The Server page'
    $ip = Get-MyIp
    if (-not $ip) { $ip = '127.0.0.1' }
    Start-Process 'http://localhost:8000/server-emergency'
    Ok 'opened http://localhost:8000 (on this laptop: Continue with Google works here)'
    Ok "phones and the other laptops open http://$($ip):$WebPort (no Google sign-in there)"
}

# ---------- phones: start-tunnel.bat (the team's fixed https address, ngrok) ----------

# The team's address (not secret); NGROK_URL in .env overrides it. The ngrok token is kept by
# ngrok itself on each laptop (ngrok config add-authtoken): asked for once on a laptop without it.
$TeamTunnel = 'https://willed-remarry-rope.ngrok-free.dev'

function Start-Tunnel {
    $url = Get-EnvValue $EnvFile 'NGROK_URL'
    if (-not $url) { $url = $TeamTunnel }

    $ngrok = (Get-Command ngrok -ErrorAction SilentlyContinue).Source
    if (-not $ngrok) {
        Say 'Installing ngrok (once, about a minute)'
        winget install --id Ngrok.Ngrok -e --accept-source-agreements --accept-package-agreements
        $ngrok = (Get-Command ngrok -ErrorAction SilentlyContinue).Source
        if (-not $ngrok) { $ngrok = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Links\ngrok.exe' }
        if (-not (Test-Path $ngrok)) { Fail 'ngrok was installed, but this window cannot see it yet. Close it and double-click start-tunnel.bat again.' }
    }
    # where ngrok keeps the token: its own folder, or the app-package folder when installed from the Microsoft Store
    $cfgs = @(Join-Path $env:LOCALAPPDATA 'ngrok\ngrok.yml') + @(Get-ChildItem "$env:LOCALAPPDATA\Packages\*ngrok*\LocalCache\Local\ngrok\ngrok.yml" -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
    if (-not ($cfgs | Where-Object { (Test-Path $_) -and (Select-String -Path $_ -Pattern 'authtoken' -Quiet) })) {
        Say 'First time on this laptop: the ngrok token (ngrok dashboard > Your Authtoken; ask Aron)'
        $token = (Read-Host '  Paste the token').Trim()
        if ($token -notmatch '^[A-Za-z0-9_-]{20,}$') { Fail 'That does not look like an ngrok token. Run this again.' }
        if ((Invoke-Native $ngrok @('config', 'add-authtoken', $token)) -ne 0) { Fail ('ngrok did not take the token: ' + $script:NativeOut) }
        Ok 'token saved on this laptop'
    }
    if (-not (Test-Port '127.0.0.1' 8000)) { Fail 'The website is not running on localhost:8000. Double-click start-venusep.bat first.' }

    Say "Phones open:  $url"
    Write-Host "  Sign in:     $url/customer/customer-login   (Continue with Google works there)"
    Write-Host '  Keep this window open: closing it stops the address.'
    Write-Host ''
    & $ngrok http 8000 --url $url
}
# ---------- run (dot-sourcing this file without -Role only loads the functions, for testing) ----------

if (-not $Role) { return }
if ($Role -notin @('Start', 'Tunnel') -and -not (Test-Admin)) {   # every session (Start) runs without administrator rights
    Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Role', $Role
    exit
}
$Xampp = Find-Xampp
$MySql = Join-Path $Xampp 'mysql\bin\mysql.exe'
$MySqlDump = Join-Path $Xampp 'mysql\bin\mysqldump.exe'
$MySqlAdmin = Join-Path $Xampp 'mysql\bin\mysqladmin.exe'
$LockFile = Join-Path $Xampp 'mysql\bin\venusep-failover-lock.sql'   # init-file: lock right after start
$MyIni = Join-Path $Xampp 'mysql\bin\my.ini'
$Php = Join-Path $Xampp 'php\php.exe'
if (-not (Test-Path $EnvFile)) { Fail "No $EnvFile. Set up the project first (SETUP.md steps 1-3)." }
Write-Host "VENUSeP server transfer ($Role) - XAMPP at $Xampp, project at $Root" -ForegroundColor White
try {
    switch ($Role) { 'A' { Setup-A } 'B' { Setup-B } 'Undo' { Undo-Laptop } 'Start' { Start-Session } 'Tunnel' { Start-Tunnel } }
} catch {
    Fail $_.Exception.Message
}
Read-Host 'Press Enter to close'
