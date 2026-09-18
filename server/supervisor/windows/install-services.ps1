# SoundChex bundled server — install Windows services (S-151 Step 4).
#
# UNVERIFIED FROM THE BUILD MACHINE (an Apple-silicon Mac). Written to be run and
# tested on a real Windows box (the owner's Windows Server). Windows differs from
# the POSIX path in one fundamental way: **PHP ships no php-fpm SAPI on Windows**,
# so there is no long-lived FastCGI pool to point Caddy at. Two viable fronts:
#
#   A. php-cgi workers behind Caddy. Caddy's `php_fastcgi` can spawn php-cgi, but
#      php-cgi is single-request; a production setup needs a supervisor to keep a
#      pool warm. This script installs Caddy as a service and lets it manage
#      php-cgi IF a php-cgi.exe is present in the bundle (it is not today — the
#      Windows build ships php.exe CLI only; php-cgi packaging is a follow-up).
#   B. Run PHP's built-in server per worker (dev-grade) — not recommended.
#
# Until php-cgi packaging lands, this installs the pieces that DO work on Windows
# today — the queue worker and scheduler as services using the bundled php.exe,
# plus Caddy as a static file server — and prints clearly what is still missing
# for full HTTP serving. Run in an elevated PowerShell.
#
# Usage:
#   .\install-services.ps1 -AppDir 'C:\SoundChex\app' -RuntimeDir 'C:\SoundChex\runtime' [-Listen ':8000']

param(
    [Parameter(Mandatory = $true)][string]$AppDir,
    [Parameter(Mandatory = $true)][string]$RuntimeDir,
    [string]$Listen = ':8000'
)

$ErrorActionPreference = 'Stop'

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) {
        throw 'Run this in an elevated PowerShell (Run as administrator).'
    }
}

Assert-Admin

$php = Join-Path $RuntimeDir 'bin\php.exe'
$caddy = Join-Path $RuntimeDir 'bin\caddy.exe'
$phpCgi = Join-Path $RuntimeDir 'bin\php-cgi.exe'
$artisan = Join-Path $AppDir 'artisan'

foreach ($bin in @($php, $caddy)) {
    if (-not (Test-Path $bin)) { throw "Missing required binary: $bin" }
}

$dataDir = Join-Path $env:ProgramData 'SoundChex'
$logDir = Join-Path $dataDir 'log'
$cfgDir = Join-Path $dataDir 'config'
New-Item -ItemType Directory -Force -Path $dataDir, $logDir, $cfgDir | Out-Null

# --- Register a service that runs a command, restarting it if it stops. ---
# Uses sc.exe with a wrapper; New-Service can't restart a bare process, so we
# set failure actions to restart. (A production install may prefer a supervisor
# like WinSW; kept dependency-free here.)
function Install-CommandService {
    param([string]$Name, [string]$DisplayName, [string]$Exe, [string]$Arguments)

    if (Get-Service -Name $Name -ErrorAction SilentlyContinue) {
        sc.exe stop $Name | Out-Null
        sc.exe delete $Name | Out-Null
        Start-Sleep -Seconds 1
    }

    $binPath = "`"$Exe`" $Arguments"
    New-Service -Name $Name -DisplayName $DisplayName -BinaryPathName $binPath -StartupType Automatic | Out-Null
    # Restart on crash: after 5s, always, reset the counter daily.
    sc.exe failure $Name reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
    Start-Service -Name $Name
    Write-Host "installed + started service: $Name"
}

# Queue worker + scheduler run on the bundled php.exe — these work on Windows.
Install-CommandService -Name 'SoundChexQueue' -DisplayName 'SoundChex Queue Worker' `
    -Exe $php -Arguments "`"$artisan`" queue:work --tries=1 --timeout=21900"
Install-CommandService -Name 'SoundChexScheduler' -DisplayName 'SoundChex Scheduler' `
    -Exe $php -Arguments "`"$artisan`" schedule:work"

# HTTP front:
if (Test-Path $phpCgi) {
    # php-cgi present: install Caddy pointed at it. (Requires a php-cgi bundle;
    # not shipped yet — see the note at the top.)
    $caddyfile = Join-Path $cfgDir 'Caddyfile'
    @"
{
	admin off
	auto_https off
}
$Listen {
	root * "$($AppDir -replace '\\','/')/public"
	request_body {
		max_size 25MB
	}
	php_fastcgi {
		# Caddy spawns/keeps php-cgi workers.
		env SCRIPT_FILENAME "$($AppDir -replace '\\','/')/public/index.php"
	}
	file_server
	log {
		output file "$($logDir -replace '\\','/')/caddy-access.log"
	}
}
"@ | Set-Content -Path $caddyfile -Encoding UTF8
    Install-CommandService -Name 'SoundChexCaddy' -DisplayName 'SoundChex Web Server' `
        -Exe $caddy -Arguments "run --config `"$caddyfile`" --adapter caddyfile"
    Write-Host "Caddy installed. Listening on $Listen."
}
else {
    Write-Warning @"
No php-cgi.exe in the runtime bundle, so the HTTP front was NOT installed.
The queue worker and scheduler ARE running as services. To serve HTTP on
Windows, a php-cgi build must be added to the bundle (tracked as a follow-up
to S-151 Step 1/4). Until then, run the server another way on this host.
"@
}

Write-Host ''
Write-Host 'Done. Manage with: Get-Service SoundChex* | Format-Table'
Write-Host 'Remove with:  sc.exe delete SoundChexQueue ; sc.exe delete SoundChexScheduler ; sc.exe delete SoundChexCaddy'
