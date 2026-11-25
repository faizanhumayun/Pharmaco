<#
  Installs Pharmaco on this computer, or updates it.

  Everything it needs is in this kit: PHP, the MariaDB database and the
  application. Nothing is downloaded, so it installs with no internet.

  It puts everything in C:\Pharmaco, creates the database as a Windows service,
  and sets the application to start by itself whenever this computer is on.
  Run it again from a newer kit to update: the books and the settings are kept.
#>

$ErrorActionPreference = 'Stop'

$Kit     = Split-Path -Parent $MyInvocation.MyCommand.Path
$Target  = 'C:\Pharmaco'
$Port    = 8080
$DbPort  = 3307
$DbName  = 'pharmaco'
$Service = 'PharmacoDB'

function Say($message) { Write-Host "`n==> $message" -ForegroundColor Cyan }

# A program that must succeed. Its own output is shown; a failure stops here.
function Run($exe, [string[]]$arguments) {
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "This step failed: $exe $($arguments -join ' ')" }
}

# Windows' own tools, where failing is fine (the thing was simply not there).
function Quietly($command) { cmd /c "$command >nul 2>&1" | Out-Null }

try {
    if (-not (Test-Path "$Kit\app\artisan") -or -not (Test-Path "$Kit\runtime\php\php.exe")) {
        throw "The 'app' and 'runtime' folders must be beside this installer. Unzip the whole kit first, then run it from there."
    }

    $Updating = Test-Path "$Target\app\.env"
    $Php      = "$Target\runtime\php\php.exe"
    $Maria    = "$Target\runtime\mariadb\bin"

    Say $(if ($Updating) { "Updating Pharmaco in $Target" } else { "Installing Pharmaco into $Target" })

    # Not running while its files are replaced.
    Quietly 'schtasks /End /TN "Pharmaco Server"'
    # Ending the task can leave the server itself behind, still holding the
    # port and the old settings (and one started by hand in a window is not
    # the task's at all), so every PHP that is Pharmaco's own is stopped.
    Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$Target\*" } | Stop-Process -Force -ErrorAction SilentlyContinue

    Say 'Installing the Microsoft runtime that PHP needs'
    Start-Process "$Kit\runtime\vc_redist.x64.exe" -ArgumentList '/install', '/quiet', '/norestart' -Wait

    Say 'Copying files'
    New-Item -ItemType Directory -Force -Path $Target, "$Target\logs" | Out-Null

    if (-not (Test-Path $Php)) {
        robocopy "$Kit\runtime" "$Target\runtime" /E /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw 'The programs could not be copied.' }
    }

    # The settings and everything the shop has stored stay as they are.
    robocopy "$Kit\app" "$Target\app" /MIR /XF .env /XD "$Kit\app\storage" "$Target\app\storage" /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw 'The application could not be copied.' }

    if (-not (Test-Path "$Target\app\storage")) {
        robocopy "$Kit\app\storage" "$Target\app\storage" /E /NFL /NDL /NJH /NJS /NP | Out-Null
    }

    Copy-Item "$Kit\set-env.php" "$Target\set-env.php" -Force

    # The certificates PHP checks a secure site against. Copied every time, so
    # an installation made from an older kit gets them on its next update.
    Copy-Item "$Kit\runtime\cacert.pem" "$Target\runtime\cacert.pem" -Force

    # PHP's settings: the extensions the application uses, and room for uploads.
    $ini = Get-Content "$Target\runtime\php\php.ini-production"
    $ini += @(
        '', '; --- added by the Pharmaco installer ---',
        'extension_dir = "ext"',
        'extension=curl', 'extension=fileinfo', 'extension=gd', 'extension=intl', 'extension=mbstring',
        'extension=openssl', 'extension=pdo_mysql', 'extension=sodium', 'extension=zip',
        'memory_limit = 512M', 'upload_max_filesize = 64M', 'post_max_size = 64M', 'max_execution_time = 300',
        "curl.cainfo = `"$Target\runtime\cacert.pem`"", "openssl.cafile = `"$Target\runtime\cacert.pem`"",
        # Keeps the application's code ready in memory instead of reading and
        # preparing every file again for every page: the difference between a
        # page in seconds and a page at once.
        'zend_extension=opcache', 'opcache.enable=1', 'opcache.enable_cli=0', 'opcache.memory_consumption=192',
        'opcache.interned_strings_buffer=16', 'opcache.max_accelerated_files=20000', 'opcache.revalidate_freq=60',
        'realpath_cache_size=4096K', 'realpath_cache_ttl=600'
    )
    Set-Content -Path "$Target\runtime\php\php.ini" -Value $ini -Encoding ASCII

    # --- the database --------------------------------------------------------
    if (-not (Get-Service -Name $Service -ErrorAction SilentlyContinue)) {
        Say 'Creating the database'
        $DbPassword = -join ((48..57) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
        Run "$Maria\mariadb-install-db.exe" @("--datadir=$Target\data", "--service=$Service", "--port=$DbPort", "--password=$DbPassword")
    } elseif ($Updating) {
        $line = Get-Content "$Target\app\.env" | Where-Object { $_ -match '^DB_PASSWORD=' } | Select-Object -First 1
        $DbPassword = ($line -replace '^DB_PASSWORD=', '').Trim('"')
    } else {
        throw "A database service called $Service is already on this computer but Pharmaco's settings are not. Remove the service (sc delete $Service) and the folder $Target\data, then run this again."
    }

    Start-Service -Name $Service

    $ready = $false
    foreach ($try in 1..30) {
        & "$Maria\mariadb-admin.exe" -u root "-p$DbPassword" -h 127.0.0.1 -P $DbPort ping 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'The database did not start.' }

    Run "$Maria\mariadb.exe" @('-u', 'root', "-p$DbPassword", '-h', '127.0.0.1', '-P', "$DbPort", '-e', "CREATE DATABASE IF NOT EXISTS $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")

    # --- the application's settings -----------------------------------------
    Set-Location "$Target\app"

    if (-not $Updating) {
        # The first (back office) login is built in. It is not asked for and
        # not shown here, so it is never on the screen in front of the shop's staff.
        Write-Host "`nTo keep a read-only copy online, paste the two values from the platform's back office (Shop copies)."
        Write-Host 'Leave both empty to set that up later.'
        $PushUrl   = Read-Host 'MIRROR_PUSH_URL'
        $PushToken = Read-Host 'MIRROR_PUSH_TOKEN'

        Run $Php @("$Target\set-env.php", "$Target\app",
            'APP_ENV=production', 'APP_DEBUG=false', "APP_URL=http://localhost:$Port", 'LOG_LEVEL=warning',
            'DB_HOST=127.0.0.1', "DB_PORT=$DbPort", "DB_DATABASE=$DbName", 'DB_USERNAME=root', "DB_PASSWORD=$DbPassword",
            "MIRROR_PUSH_URL=$PushUrl", "MIRROR_PUSH_TOKEN=$PushToken")

        Run $Php @('artisan', 'key:generate', '--force', '--no-interaction')
    }

    # Marks this installation as a shop's own computer (also on an update
    # from a kit made before the mark existed).
    Run $Php @("$Target\set-env.php", "$Target\app", 'PHARMACO_SHOP=true')

    Say 'Preparing the books'
    Run $Php @('artisan', 'migrate', '--force', '--no-interaction')
    # Quietly: what it would print includes the first login's email.
    if (-not $Updating) { Run $Php @('artisan', 'db:seed', '--force', '--no-interaction', '--quiet') }
    Run $Php @('artisan', 'optimize:clear')
    # The routes and the screens, prepared once here rather than on every page.
    # The settings are left uncached on purpose, so a change to .env takes effect.
    Run $Php @('artisan', 'route:cache')
    Run $Php @('artisan', 'view:cache')

    # --- start by itself -----------------------------------------------------
    Say 'Setting it to start with this computer'

    Set-Content -Path "$Target\run-server.bat" -Encoding ASCII -Value @(
        '@echo off', "cd /d $Target\app",
        "`"$Php`" artisan serve --host=0.0.0.0 --port=$Port >> `"$Target\logs\server.log`" 2>&1")
    Set-Content -Path "$Target\run-scheduler.bat" -Encoding ASCII -Value @(
        '@echo off', "cd /d $Target\app",
        # The server is looked for every minute and started again if it is not
        # answering: a laptop that sleeps, or a start-up that missed it, mends itself.
        "netstat -ano | findstr /c:`":$Port `" | findstr LISTENING >nul || schtasks /run /tn `"Pharmaco Server`" >nul 2>&1",
        "`"$Php`" artisan schedule:run >> `"$Target\logs\scheduler.log`" 2>&1")

    # Never stopped for running too long, and started again if it ever stops.
    $always = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

    Register-ScheduledTask -TaskName 'Pharmaco Server' -Force -User 'SYSTEM' -RunLevel Highest -Settings $always `
        -Action (New-ScheduledTaskAction -Execute "$Target\run-server.bat") `
        -Trigger @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn)) | Out-Null

    # Once a minute: the application decides what is due (sending the online copy, the nightly checks).
    $brief = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew

    Register-ScheduledTask -TaskName 'Pharmaco Scheduler' -Force -User 'SYSTEM' -RunLevel Highest -Settings $brief `
        -Action (New-ScheduledTaskAction -Execute "$Target\run-scheduler.bat") `
        -Trigger (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)) | Out-Null

    Start-ScheduledTask -TaskName 'Pharmaco Server'

    # Other computers in the shop reach it over the network.
    Get-NetFirewallRule -DisplayName 'Pharmaco' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    New-NetFirewallRule -DisplayName 'Pharmaco' -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow | Out-Null

    Set-Content -Path "$env:PUBLIC\Desktop\Pharmaco.url" -Encoding ASCII -Value @('[InternetShortcut]', "URL=http://localhost:$Port")

    # A second shortcut for the counter: the browser opened so that Print sends
    # the slip straight to the default printer, with no preview in between.
    # Only a browser started with this switch does that, and only when it is
    # started fresh - hence a profile folder of its own.
    $Browser = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    ) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

    if ($Browser) {
        $Link = (New-Object -ComObject WScript.Shell).CreateShortcut("$env:PUBLIC\Desktop\Pharmaco Till (prints directly).lnk")
        $Link.TargetPath = $Browser
        $Link.Arguments = "--kiosk-printing --user-data-dir=`"$Target\till-browser`" --no-first-run --start-maximized http://localhost:$Port"
        $Link.IconLocation = "$Browser,0"
        $Link.Description = 'Pharmaco, with slips printed straight to the default printer'
        $Link.Save()
    }

    $addresses = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } | ForEach-Object { "http://$($_.IPAddress):$Port" }

    Start-Sleep -Seconds 4

    Write-Host "`nPharmaco is $(if ($Updating) { 'updated' } else { 'installed' })." -ForegroundColor Green
    Write-Host "  On this computer:       http://localhost:$Port   (there is a Pharmaco shortcut on the desktop)"
    if ($Browser) { Write-Host "  To print without a preview: open 'Pharmaco Till (prints directly)' on the desktop, and make the slip printer the default printer." }
    Write-Host "  From other computers:   $($addresses -join '   or   ')"
    Write-Host "  Sign in at:             http://localhost:$Port/back-office/login"
    Write-Host "`nThe books are in $Target\data. Backups are written to $Target\app\storage\app\private\mirror\out"

    Start-Process "http://localhost:$Port"
}
catch {
    Write-Host "`nSTOPPED: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Nothing else was changed after this point. Fix the cause and run the installer again.'
    exit 1
}
