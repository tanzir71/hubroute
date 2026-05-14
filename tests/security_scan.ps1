$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$app = Join-Path $root 'hubroute.php'
$source = Get-Content -LiteralPath $app -Raw

$checks = @(
    @{ Name = 'htmlEscape helper exists'; Pattern = 'function\s+htmlEscape\s*\(' },
    @{ Name = 'security headers are sent'; Pattern = 'Content-Security-Policy' },
    @{ Name = 'session idle timeout configured'; Pattern = 'SESSION_IDLE_TIMEOUT_SECONDS' },
    @{ Name = 'rate limit table exists'; Pattern = 'CREATE TABLE IF NOT EXISTS rate_limits' },
    @{ Name = 'login rate limiting exists'; Pattern = 'checkRateLimit\(\$pdo,\s*''login''' },
    @{ Name = 'logout is a CSRF POST action'; Pattern = 'name="action" value="logout"' },
    @{ Name = 'status inputs are whitelisted'; Pattern = 'allowedStatuses\(' },
    @{ Name = 'parcel access helper exists'; Pattern = 'function\s+canAccessParcel\s*\(' },
    @{ Name = 'hub assignment ownership validation exists'; Pattern = 'assertHubOwnsAgentAndRoute' },
    @{ Name = 'data directory web denial is installed'; Pattern = 'installDataDirectoryDenyFiles' }
)

$failures = @()
foreach ($check in $checks) {
    if ($source -notmatch $check.Pattern) {
        $failures += $check.Name
    }
}

$unsafeSql = Select-String -LiteralPath $app -Pattern '\$pdo->query\([^"]*\$|ORDER BY\s+\$|LIMIT\s+\$|WHERE\s+.*\.\s*\$' -AllMatches
if ($unsafeSql) {
    $failures += 'possible direct SQL concatenation with variables'
}

if ($failures.Count -gt 0) {
    Write-Host 'Security scan failed:'
    foreach ($failure in $failures) {
        Write-Host " - $failure"
    }
    exit 1
}

Write-Host 'Security scan passed.'
