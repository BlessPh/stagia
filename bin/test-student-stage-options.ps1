param(
    [string]$BaseUrl = 'https://stagia-rdc.onrender.com/api/v1'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')
$identifier = Read-Host 'Identifiant, e-mail ou code STAGIA de l etudiant'
$securePassword = Read-Host 'Mot de passe' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$accessToken = $null
$plainPassword = $null

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $loginBody = @{
        identifiant = $identifier
        password = $plainPassword
        device_name = 'test-stage-options-powershell'
    } | ConvertTo-Json

    $login = Invoke-RestMethod `
        -Method Post `
        -Uri "$BaseUrl/login" `
        -ContentType 'application/json' `
        -Body $loginBody

    if (-not $login.success -or [string]::IsNullOrWhiteSpace($login.data.access_token)) {
        throw "Connexion API refusee : $($login.message)"
    }

    $accessToken = $login.data.access_token
    $headers = @{ Authorization = "Bearer $accessToken" }
    $response = Invoke-RestMethod `
        -Method Get `
        -Uri "$BaseUrl/student/stage-options" `
        -Headers $headers

    if (-not $response.success) {
        throw "Lecture des campagnes refusee : $($response.message)"
    }

    $d4Campaigns = @($response.data.campaigns)
    $managedCampaigns = @($response.data.university_managed_campaigns)

    Write-Output ''
    Write-Output "Campagnes reservables par l etudiant : $($d4Campaigns.Count)"
    foreach ($campaign in $d4Campaigns) {
        [pscustomobject]@{
            Id = $campaign.campaign_id
            Code = $campaign.code
            Titre = $campaign.title
            Type = $campaign.stage_type.code
            Promotion = $campaign.promotion.code
            Hopitaux = @($campaign.hospitals).Count
            HopitauxDisponibles = $campaign.available_hospitals
        } | Format-List
    }

    Write-Output "Campagnes gerees par l universite : $($managedCampaigns.Count)"
    foreach ($campaign in $managedCampaigns) {
        [pscustomobject]@{
            Id = $campaign.campaign_id
            Code = $campaign.code
            Titre = $campaign.title
            Type = $campaign.stage_type.code
            Promotion = $campaign.promotion.code
            Mode = $campaign.mode
        } | Format-List
    }

    Write-Output 'Statistiques retournees par l API :'
    $response.data.stats | Format-List
}
catch {
    $details = $_.ErrorDetails.Message
    if (-not [string]::IsNullOrWhiteSpace($details)) {
        Write-Error "$($_.Exception.Message)`nReponse API : $details"
    }
    else {
        Write-Error $_.Exception.Message
    }
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
            Write-Warning 'Le test est termine, mais la session API de test n a pas pu etre supprimee.'
        }
    }

    $plainPassword = $null
    $loginBody = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
}
