param(
    [string]$BaseUrl = 'https://stagia-rdc.onrender.com/api/v1'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$identifier = Read-Host 'Identifiant, e-mail ou code STAGIA'
$securePassword = Read-Host 'Mot de passe' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$accessToken = $null

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $body = @{
        identifiant = $identifier
        password = $plainPassword
        device_name = 'test-powershell'
    } | ConvertTo-Json

    $login = Invoke-RestMethod `
        -Method Post `
        -Uri "$BaseUrl/login" `
        -ContentType 'application/json' `
        -Body $body

    if (-not $login.success) {
        throw $login.message
    }

    $accessToken = $login.data.access_token
    $headers = @{ Authorization = "Bearer $accessToken" }
    $me = Invoke-RestMethod -Method Get -Uri "$BaseUrl/me" -Headers $headers

    [pscustomobject]@{
        Login = $login.success
        Message = $login.message
        AccessTokenReceived = -not [string]::IsNullOrWhiteSpace($login.data.access_token)
        RefreshTokenReceived = -not [string]::IsNullOrWhiteSpace($login.data.refresh_token)
        TokenType = $login.data.token_type
        ExpiresIn = $login.data.expires_in
        UserId = $login.data.user.id
        Roles = $login.data.user.roles -join ','
        StudentProfile = $null -ne $login.data.student
        MeEndpoint = $me.success
    } | Format-List
}
finally {
    if (-not [string]::IsNullOrWhiteSpace($accessToken)) {
        try {
            Invoke-RestMethod `
                -Method Post `
                -Uri "$BaseUrl/logout" `
                -Headers @{ Authorization = "Bearer $accessToken" } | Out-Null
            Write-Output 'Deconnexion API : OK'
        }
        catch {
            Write-Warning 'Le test a reussi, mais la session API de test n a pas pu etre supprimee.'
        }
    }

    $plainPassword = $null
    $body = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
}
