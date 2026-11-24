# VENUSeP server transfer: set up one laptop (SETUP.md, "Two or more laptops").
#   setup-laptop-A.bat  on the main laptop (the database everyone uses)
#   setup-laptop-B.bat  on each copy laptop (B, C, D)
#   undo.bat            puts a laptop back the way it was
# Every step can be run again safely. Old files are kept as *.before-failover.
# Plain ASCII on purpose: Windows PowerShell 5.1 reads scripts as ANSI.
param([ValidateSet('A', 'B', 'Undo')][string]$Role)

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
# option file the later line wins.
function Add-FailoverBlock([string]$text, [int]$serverId, [string]$db) {
    $text = Remove-FailoverBlock $text
    $head = [regex]::Match($text, '(?m)^\[mysqld\][^\n]*\n')
    if (-not $head.Success) { throw 'my.ini has no [mysqld] section.' }
    $next = [regex]::Match($text.Substring($head.Index + $head.Length), '(?m)^\[')
    $at = if ($next.Success) { $head.Index + $head.Length + $next.Index } else { $text.Length }
    $block = @($Begin,
        "server-id=$serverId", 'bind-address=0.0.0.0', 'skip-name-resolve', 'log-bin=binlog', 'relay-log=relay-bin',
        'binlog-format=ROW', "binlog-do-db=$db", 'log-slave-updates=ON', 'gtid-strict-mode=ON', 'expire_logs_days=7',
        'log_bin_trust_function_creators=1', 'read-only=ON', 'rpl_semi_sync_master_enabled=ON', 'rpl_semi_sync_slave_enabled=ON',
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

function Wait-MySqlRestart {
    Pause-For 'Now STOP and START MySQL in the XAMPP Control Panel (the new settings load on start).'
    for ($i = 0; $i -lt 15 -and -not (Test-MySqlUp); $i++) { Start-Sleep -Seconds 2 }
    if (-not (Test-MySqlUp)) { Fail 'MySQL is not answering. Start it in the XAMPP panel; if it will not start, run undo.bat.' }
    if ((Invoke-Sql 'SELECT @@log_bin') -ne '1') { Fail 'MySQL started without the new settings. Restart it in the XAMPP panel and run this again.' }
    Ok 'MySQL restarted with the copy settings (it starts locked: read-only)'
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
        Invoke-Sql "DROP USER IF EXISTS ''@'localhost'; FLUSH PRIVILEGES;" | Out-Null
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
    $a = New-ScheduledTaskAction -Execute $script:Php -Argument 'artisan venusep:failover-tick' -WorkingDirectory $Laravel
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
    [IO.File]::WriteAllText($script:MyIni, (Add-FailoverBlock ([IO.File]::ReadAllText($script:MyIni)) 1 $db))
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
    $main = (Read-Host "Laptop A's address (shown at the end of A's setup)").Trim()
    if ($main -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { Fail "'$main' is not an IPv4 address." }
    $n = Read-Host 'Which copy is this? 2 = laptop B, 3 = C, 4 = D [2]'
    $id = if ($n) { [int]$n } else { 2 }
    if ($id -lt 2 -or $id -gt 9) { Fail 'Type 2, 3 or 4.' }
    $fromA = (Read-Host "Where is laptop A's .env? (the file on the USB stick, for example E:\.env)").Trim('"', ' ')
    if (-not (Test-Path $fromA)) { Fail "No file at $fromA." }
    if (-not (Get-EnvValue $fromA 'DB_REPL_PASSWORD')) { Fail "$fromA is not the .env from laptop A's setup (no DB_REPL_PASSWORD)." }
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
    [IO.File]::WriteAllText($script:MyIni, (Add-FailoverBlock ([IO.File]::ReadAllText($script:MyIni)) $id $db))
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
    Pause-For 'Now STOP and START MySQL in the XAMPP Control Panel.'
    Say 'DONE. The MySQL accounts are left in place (harmless). A copy laptop''s own database was saved in your user folder as <name>-before-copy-<date>.sql.'
}

# ---------- run (dot-sourcing this file without -Role only loads the functions, for testing) ----------

if (-not $Role) { return }
if (-not (Test-Admin)) {
    Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Role', $Role
    exit
}
$Xampp = Find-Xampp
$MySql = Join-Path $Xampp 'mysql\bin\mysql.exe'
$MySqlDump = Join-Path $Xampp 'mysql\bin\mysqldump.exe'
$MyIni = Join-Path $Xampp 'mysql\bin\my.ini'
$Php = Join-Path $Xampp 'php\php.exe'
if (-not (Test-Path $EnvFile)) { Fail "No $EnvFile. Set up the project first (SETUP.md steps 1-3)." }
Write-Host "VENUSeP server transfer setup ($Role) - XAMPP at $Xampp, project at $Root" -ForegroundColor White
try {
    switch ($Role) { 'A' { Setup-A } 'B' { Setup-B } 'Undo' { Undo-Laptop } }
} catch {
    Fail $_.Exception.Message
}
Read-Host 'Press Enter to close'
