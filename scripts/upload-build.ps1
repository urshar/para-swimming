# Baut die Frontend-Assets lokal und lädt sie auf den Produktionsserver (docs/deployment.md). Der Server hat kein
# Node.js, deshalb liegt public/build nicht im Repository, sondern kommt auf diesem Weg dorthin.
#
#   .\scripts\upload-build.ps1 -Target "hosting123456@hosting123456.abc12.netcup.net"
#
# -Target ist der SSH-Zugang aus dem netcup CCP (Benutzer@Server) oder ein Host-Alias aus ~/.ssh/config.
# -RemoteDir ist der Projektordner relativ zum SSH-Startordner (Standard: paraswim.at/app).
#
# Die Datei ist als UTF-8 mit BOM gespeichert, damit Windows PowerShell 5.1 die Umlaute richtig liest.
param(
    [Parameter(Mandatory = $true)][string] $Target,
    [string] $RemoteDir = 'paraswim.at/httpdocs'
)

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

if ($Target -match '^\s*$|@\s*$|^@|[<>]') {
    throw "Ungültiges Ziel '$Target' — erwartet wird Benutzer@Server, z. B. hosting123456@hosting123456.abc12.netcup.net (steht im netcup CCP beim Webhosting unter SSH)."
}

# Zuerst die Verbindung prüfen, damit ein Tippfehler nicht erst nach dem Build auffällt.
ssh $Target "test -d $RemoteDir/public"
if ($LASTEXITCODE -ne 0) { throw "Keine SSH-Verbindung zu '$Target' oder $RemoteDir/public fehlt auf dem Server." }

npm run build
if ($LASTEXITCODE -ne 0) { throw 'npm run build ist fehlgeschlagen.' }

# Alte Dateien entfernen (Vite vergibt neue Dateinamen je Build), dann den neuen Stand hochladen.
ssh $Target "rm -rf $RemoteDir/public/build"
if ($LASTEXITCODE -ne 0) { throw 'Alter Build ließ sich auf dem Server nicht entfernen.' }
scp -r public/build "${Target}:$RemoteDir/public/"
if ($LASTEXITCODE -ne 0) { throw 'Upload von public/build ist fehlgeschlagen.' }

Write-Host "public/build nach ${Target}:$RemoteDir/public/build hochgeladen."
