# ============================================================
#  CharBook - Ejecutar API (Laravel) + APP MOVIL (Expo) para
#  probar en un iPhone/Android FISICO con Expo Go.
#
#  La app de Expo (CharBookReact-main) detecta sola la IP de tu
#  PC desde el host de Metro, asi que funciona en CUALQUIER red
#  mientras el telefono y la PC esten en el MISMO Wi-Fi.
#
#  USO normal (cada vez que quieras probar en el telefono):
#     click derecho -> "Ejecutar con PowerShell"
#     o:  powershell -ExecutionPolicy Bypass -File run-mobile.ps1
#
#  UNA SOLA VEZ, como ADMINISTRADOR, para abrir el firewall
#  (puertos 8000 = API y 8081 = Metro/Expo). Despues ya no
#  necesitas admin.
# ============================================================

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$expoDir = Join-Path (Split-Path $root -Parent) "CharBookReact-main"

# --- 1) Detectar la IP de la red local (adaptador con puerta de enlace) ---
$ip = (Get-NetIPConfiguration |
        Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } |
        Select-Object -First 1).IPv4Address.IPAddress
if (-not $ip) {
  $ip = (Get-NetIPAddress -AddressFamily IPv4 |
          Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } |
          Select-Object -First 1).IPAddress
}
if (-not $ip) {
  Write-Host "No pude detectar la IP de tu red. Conectate al Wi-Fi e intentalo de nuevo." -ForegroundColor Red
  Read-Host "Enter para salir"; exit 1
}

Write-Host ""
Write-Host "  IP de tu PC en la red:  $ip" -ForegroundColor Cyan
Write-Host ""

# --- 2) Reglas del firewall (solo si corres como administrador) ---
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
if ($admin) {
  foreach ($p in 8000, 8081) {
    $name = "CharBook dev ($p)"
    if (-not (Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue)) {
      New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $p | Out-Null
      Write-Host "  Firewall: abierto el puerto $p" -ForegroundColor DarkGray
    }
  }
} else {
  Write-Host "  (Si Expo Go NO conecta: ejecuta este script UNA vez como ADMINISTRADOR" -ForegroundColor Yellow
  Write-Host "   para abrir los puertos 8000 (API) y 8081 (Metro) en el firewall.)" -ForegroundColor Yellow
  Write-Host ""
}

# --- 3) Laravel: limpiar config para que tome la nueva APP_URL ---
Push-Location (Join-Path $root "apps\api")
php artisan config:clear | Out-Null
Pop-Location

# --- 4) Arrancar API (escuchando en todas las interfaces) ---
#     APP_URL = la IP de la red para que las portadas/comics (que el API
#     devuelve como URL) se vean en el telefono.
$apiCmd = "`$env:APP_URL='http://${ip}:8000'; `$env:FRONTEND_URL='http://${ip}:3000'; Set-Location '$root\apps\api'; Write-Host 'API en http://${ip}:8000' -ForegroundColor Green; php artisan serve --host=0.0.0.0 --port=8000"
Start-Process powershell -ArgumentList "-NoExit", "-Command", $apiCmd

# --- 5) Arrancar la app de Expo (apunta a la misma IP de la API) ---
if (Test-Path $expoDir) {
  $expoCmd = "`$env:EXPO_PUBLIC_API_URL='http://${ip}:8000'; Set-Location '$expoDir'; npx expo start"
  Start-Process powershell -ArgumentList "-NoExit", "-Command", $expoCmd
  Write-Host "  Se abrieron 2 ventanas: API + Expo." -ForegroundColor Cyan
  Write-Host "  En el iPhone abre EXPO GO y escanea el QR de la ventana de Expo." -ForegroundColor Green
} else {
  Write-Host "  No encontre la app de Expo en: $expoDir" -ForegroundColor Yellow
  Write-Host "  Solo se inicio la API. Abre tu proyecto de Expo y corre: npx expo start" -ForegroundColor Yellow
}

Write-Host "  (El iPhone y la PC deben estar en la MISMA red Wi-Fi.)" -ForegroundColor DarkGray
Write-Host ""
Read-Host "Enter para cerrar esta ventana (la API y Expo siguen en sus ventanas)"
