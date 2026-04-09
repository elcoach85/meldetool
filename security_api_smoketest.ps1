param(
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,

    [int]$TeamId = 6962,
    [int]$RiderId = 7225,

    [string]$SubscriberUser,
    [string]$SubscriberAppPassword,

    [string]$AdminUser,
    [string]$AdminAppPassword
)

$ErrorActionPreference = 'Stop'
$base = $BaseUrl.TrimEnd('/')

function New-BasicAuthHeader {
    param(
        [string]$User,
        [string]$Password
    )

    if ([string]::IsNullOrWhiteSpace($User) -or [string]::IsNullOrWhiteSpace($Password)) {
        return @{}
    }

    $raw = "{0}:{1}" -f $User, $Password
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($raw)
    $token = [System.Convert]::ToBase64String($bytes)
    return @{ Authorization = "Basic $token" }
}

function Invoke-TestRequest {
    param(
        [string]$Url,
        [hashtable]$Headers
    )

    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -Headers $Headers -TimeoutSec 20
        return [pscustomobject]@{
            StatusCode = [int]$response.StatusCode
            Body       = [string]$response.Content
            ErrorText  = ''
        }
    }
    catch {
        $statusCode = 0
        $body = ''
        $err = $_.Exception.Message

        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
            $statusCode = [int]$_.Exception.Response.StatusCode
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $body = $reader.ReadToEnd()
                $reader.Close()
            }
            catch {
                $body = ''
            }
        }

        return [pscustomobject]@{
            StatusCode = $statusCode
            Body       = [string]$body
            ErrorText  = [string]$err
        }
    }
}

function Contains-SensitiveFields {
    param([string]$Body)

    if ([string]::IsNullOrWhiteSpace($Body)) {
        return $false
    }

    $pattern = '"(iban|bic|kontoinhaber|email_manager|email_rider)"\s*:'
    return [regex]::IsMatch($Body, $pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
}

function Is-BlockedStatus {
    param([int]$StatusCode)

    return ($StatusCode -in @(401, 403, 404))
}

$scenarios = @(
    [pscustomobject]@{
        Name = 'Unauth'
        Headers = @{}
    }
)

if (-not [string]::IsNullOrWhiteSpace($SubscriberUser) -and -not [string]::IsNullOrWhiteSpace($SubscriberAppPassword)) {
    $scenarios += [pscustomobject]@{
        Name = 'Subscriber'
        Headers = New-BasicAuthHeader -User $SubscriberUser -Password $SubscriberAppPassword
    }
}

if (-not [string]::IsNullOrWhiteSpace($AdminUser) -and -not [string]::IsNullOrWhiteSpace($AdminAppPassword)) {
    $scenarios += [pscustomobject]@{
        Name = 'Admin'
        Headers = New-BasicAuthHeader -User $AdminUser -Password $AdminAppPassword
    }
}

$targets = @(
    [pscustomobject]@{ Name = 'Type team'; Url = "$base/wp-json/wp/v2/types/team"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Type fahrer'; Url = "$base/wp-json/wp/v2/types/fahrer"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Team item'; Url = "$base/wp-json/wp/v2/team/$TeamId"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Fahrer item'; Url = "$base/wp-json/wp/v2/fahrer/$RiderId"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Team list'; Url = "$base/wp-json/wp/v2/team?per_page=1"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Fahrer list'; Url = "$base/wp-json/wp/v2/fahrer?per_page=1"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Pods team'; Url = "$base/wp-json/pods/v1/team"; Kind = 'api' },
    [pscustomobject]@{ Name = 'Pods fahrer'; Url = "$base/wp-json/pods/v1/fahrer"; Kind = 'api' },
    [pscustomobject]@{ Name = 'mail_log.txt'; Url = "$base/wp-content/plugins/meldetool/mail_log.txt"; Kind = 'logfile' }
)

$results = @()

foreach ($scenario in $scenarios) {
    foreach ($target in $targets) {
        $res = Invoke-TestRequest -Url $target.Url -Headers $scenario.Headers
        $hasSensitive = Contains-SensitiveFields -Body $res.Body
        $pass = $false
        $reason = ''

        if ($target.Kind -eq 'logfile') {
            if (Is-BlockedStatus -StatusCode $res.StatusCode) {
                $pass = $true
                $reason = 'log blocked'
            }
            else {
                $pass = $false
                $reason = 'log reachable'
            }
        }
        else {
            if ($scenario.Name -eq 'Unauth') {
                if (Is-BlockedStatus -StatusCode $res.StatusCode) {
                    $pass = $true
                    $reason = 'blocked as expected'
                }
                elseif ($hasSensitive) {
                    $pass = $false
                    $reason = 'sensitive fields exposed'
                }
                else {
                    $pass = $true
                    $reason = 'not blocked, but no sensitive fields detected'
                }
            }
            else {
                if ($hasSensitive) {
                    $pass = $false
                    $reason = 'sensitive fields exposed'
                }
                else {
                    $pass = $true
                    $reason = 'no sensitive fields detected'
                }
            }
        }

        $results += [pscustomobject]@{
            Scenario   = $scenario.Name
            Endpoint   = $target.Name
            StatusCode = $res.StatusCode
            Sensitive  = $hasSensitive
            Pass       = $pass
            Reason     = $reason
            Url        = $target.Url
        }
    }
}

$results | Sort-Object Scenario, Endpoint | Format-Table -AutoSize Scenario, Endpoint, StatusCode, Sensitive, Pass, Reason

$failed = $results | Where-Object { -not $_.Pass }

Write-Host ''
if ($failed.Count -eq 0) {
    Write-Host 'OVERALL: PASS' -ForegroundColor Green
}
else {
    Write-Host ("OVERALL: FAIL ({0} failed checks)" -f $failed.Count) -ForegroundColor Red
    $failed | Format-Table -AutoSize Scenario, Endpoint, StatusCode, Sensitive, Reason, Url
}
