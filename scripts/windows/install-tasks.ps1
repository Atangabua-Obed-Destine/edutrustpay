<#
Registers the background jobs for this machine in Windows Task Scheduler, under
the folder \EdutrustPay. Safe to run again: it replaces its own tasks.

  .\install-tasks.ps1                                  the console only
  .\install-tasks.ps1 -Schools C:\xampp\htdocs\pax     a school's reporting only
  .\install-tasks.ps1 -Console -Schools a,b            both, on a test machine

For a school this deliberately runs only the EdutrustPay commands, never
`schedule:run`. A school's scheduler also sends fee reminders to parents and
applies fines, which must not start because reporting was set up.

Tasks run while this user is signed in, and catch up if the machine was off.
#>
param(
    [switch]$Console,
    [string[]]$Schools = @(),
    [string]$ConsolePath = (Resolve-Path "$PSScriptRoot\..\..").Path,
    [string]$Php = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$runner = Join-Path $PSScriptRoot 'run-hidden.vbs'
$folder = '\EdutrustPay\'

if (-not $Console -and $Schools.Count -eq 0) { $Console = $true }

function Add-Task([string]$Name, [string]$App, [string]$Command, $Trigger) {
    $action = New-ScheduledTaskAction -Execute 'wscript.exe' `
        -Argument "//B `"$runner`" `"$App`" `"$Php`" `"$Command`""
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
    Register-ScheduledTask -TaskName $Name -TaskPath $folder -Action $action -Trigger $Trigger `
        -Settings $settings -Force | Out-Null
    Write-Output "  $Name  ->  $Command"
}

function Every([int]$Minutes) {
    New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $Minutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)
}

if ($Console) {
    Write-Output "Console: $ConsolePath"
    Add-Task 'Console background work' $ConsolePath 'schedule:run' (Every 1)
    Add-Task 'Console silence check' $ConsolePath 'edutrustpay:sweep' (New-ScheduledTaskTrigger -Daily -At '07:00')
}

foreach ($school in $Schools) {
    $label = Split-Path $school -Leaf
    Write-Output "School: $school"
    Add-Task "$label report" $school 'edutrustpay:report --months=3' (New-ScheduledTaskTrigger -Daily -At '03:00')
    Add-Task "$label deliver" $school 'edutrustpay:flush' (Every 60)
    Add-Task "$label heartbeat" $school 'edutrustpay:heartbeat' (New-ScheduledTaskTrigger -Daily -At '05:30')
}
